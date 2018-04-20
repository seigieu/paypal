<?php
/**
 * Copyright (C) 2017-2018 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <contact@thirtybees.com>
 * @copyright 2017-2018 thirty bees
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PayPalModule\Exception\PaymentException;
use PayPalModule\PayPalOrder;
use PayPalModule\PayPalRestApi;

/**
 * Class PayPalExpressCheckoutModuleFrontController
 */
class PayPalExpressCheckoutModuleFrontController extends ModuleFrontController
{
    /** @var int $idOrder */
    public $idOrder;
    /** @var int $idModule */
    public $idModule;
    /** @var string $payPalKey */
    public $payPalKey;
    /** @var \PayPal $module */
    public $module;
    /** @var bool $ssl */
    public $ssl = true;

    /**
     * Initialize content
     *
     * @return void
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws Adapter_Exception
     */
    public function initContent()
    {
        if (Tools::isSubmit('paymentId') && Tools::isSubmit('PayerID')) {
            if (!$this->processPayment()) {
                parent::initContent();
            }

            return;
        }

        if (!Validate::isLoadedObject(Context::getContext()->cart)) {
            $this->errors[] = $this->module->l('Cart not found');
        } else {
            $this->preparePayment();
        }

        $this->context->smarty->assign([
            'errors' => $this->errors,
        ]);

        parent::initContent();

        $this->setTemplate('expresscheckout_error.tpl');
    }

    /**
     * Prepare to redirect visitor to PayPal website
     *
     * @throws PrestaShopException
     * @throws Adapter_Exception
     */
    public function preparePayment()
    {
        $rest = new PayPalRestApi();
        /** @var array $payment */
        try {
            $payment = $rest->createPayment(false, false, PayPalRestApi::STANDARD_PROFILE);
        } catch (PaymentException $e) {
            if ($e->getRequest() instanceof Request && $e->getResponse() instanceof Response) {
                $requestBody = $e->getRequest()->getBody();
                $responseBody = $e->getResponse()->getBody();
            } else {
                $requestBody = 'Request unavailable';
                $responseBody = 'Response unavailable';
            }
            Logger::addLog("PayPal module error while creating payment: {$requestBody} -- {$responseBody}", 3);
            $this->errors[] = $this->l('Unable to initialize payment. Please contact support.');
        }

        if (isset($payment->id) && $payment->id) {
            foreach ($payment['links'] as $link) {
                /** @var array $link */
                if ($link['rel'] === 'approval_url') {
                    Tools::redirectLink($link['href']);
                }
            }
        }

        if (!empty($payment['message'])) {
            $this->errors[] = $payment['message'];
        }
    }

    /**
     * Process PayPal payment
     *
     * @return bool Status
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function processPayment()
    {
        $cart = $this->context->cart;
        $paymentId = Tools::getValue('paymentId');

        $rest = new PayPalRestApi();
        $payment = $rest->lookUpPayment($paymentId);

        /* Check modification on the product cart / quantity */
        if (!empty($payment->transactions[0]->related_resources[0]->authorization->id)) {
            /** @var Currency $currency */
            $currency = Currency::getCurrencyInstance($cart->id_currency);
            $orderTotal = Tools::ps_round($cart->getOrderTotal(true), 2);
            if (!$orderTotal) {
                // This page has been revisited, redirect to order history
                // TODO: handle guests
                Tools::redirectLink($this->context->link->getPageLink('order-history', true));
            }
            $authorization = $rest->capturePayment(
                $payment->transactions[0]->related_resources[0]->authorization->id,
                $orderTotal,
                strtoupper($currency->iso_code)
            );

            if (isset($authorization->name) && isset($authorization->message)) {
                // Capture failed: void and redirect
                $rest->voidAuthorization($payment->id);
                Tools::redirectLink($this->context->link->getModuleLink($this->module->name, 'expresscheckout', [], true));
            }

            $customer = new \Customer((int) $cart->id_customer);

            $this->validateOrder($customer, $cart, $payment, $authorization);
        } elseif ($payment->state === 'authorized') {
            // Authorized, void and redirect
            $rest->voidAuthorization($payment->id);
            Tools::redirectLink($this->context->link->getModuleLink($this->module->name, 'expresscheckout', [], true));
        } elseif (isset($payment->transactions[0]) && isset($payment->state) && $payment->state === 'approved') {
            // Unable to authorize, try again, but unable to capture due to a 15%+ price increase, redirect to PayPal for a new auth
            Tools::redirectLink($this->context->link->getModuleLink($this->module->name, 'expresscheckout', [], true));
        }

        $logs = [sprintf($this->module->l('An unknown error occurred. The authorization status is `%s`. The amount has not been charged (yet).'), isset($payment->state) ? $payment->state : $this->module->l('Unknown'))];
        if (_PS_MODE_DEV_) {
            $logs[] = json_encode(['The full payment object looks like' => $payment]);
        }

        $this->context->smarty->assign(
            [
                'logs' => $logs,
                'message' => $this->module->l('Error occurred'),
            ]
        );

        $template = 'error.tpl';


        /**
         * Detect if we are using mobile or not
         * Check the 'ps_mobile_site' parameter.
         */
        $this->context->smarty->assign([
            'use_mobile' => (bool) $this->context->getMobileDevice(),
        ]);

        $this->setTemplate($template);

        return false;
    }

    /**
     * Check payment return
     *
     * @param Customer $customer
     * @param Cart     $cart
     * @param array    $payment
     * @param array    $authorization
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    protected function validateOrder($customer, $cart, $payment, $authorization = null)
    {
        if (!empty($authorization['state'])) {
            $authorizationState = $authorization['state'];
            $transactionAmount = $authorization['amount']['total'];
        } elseif (!empty($payment['transactions'][0]['related_resources'][0]['authorization']['id'])) {
            $authorization = $payment['transactions'][0]['related_resources'][0]['authorization'];
            $authorizationState = $authorization['state'];
            $transactionAmount = $authorization['amount']['total'];
        } else {
            $authorizationState = $payment['state'];
            $transactionAmount = (float) $payment['transactions'][0]['amount']['total'];
        }
        $orderTotal = (float) round($cart->getOrderTotal(true, Cart::BOTH), 2);

        // Payment check
        if ($authorizationState === 'completed' && $transactionAmount == $orderTotal) {
            if (!Configuration::get(PayPal::IMMEDIATE_CAPTURE)) {
                $paymentType = (int) Configuration::get('PS_OS_PAYPAL');
                $message = $this->module->l('Pending payment capture.').'<br />';
            } else {
                $paymentType = (int) Configuration::get('PS_OS_PAYMENT');
                $message = $this->module->l('Payment accepted.').'<br />';
            }
        } else {
            $paymentType = (int) Configuration::get('PS_OS_PAYPAL');
            $message = $this->module->l('Pending payment capture.').'<br />';
        }

        $transaction = PayPalOrder::getTransactionDetails($payment);
        $this->context->cookie->id_cart = $cart->id;

        $this->module->validateOrder(
            (int) $cart->id,
            isset($paymentType) ? $paymentType : Configuration::get('PS_OS_PAYMENT'),
            $orderTotal,
            'PayPal',
            isset($message) ? $message : '',
            $transaction,
            (int) $cart->id_currency,
            false,
            $customer->secure_key,
            $this->context->shop
        );

        if ($customer->isGuest()) {
            Tools::redirectLink($this->context->link->getModuleLink(
                $this->module->name,
                'expresscheckoutguest',
                [],
                true
            ));
        } else {
            Tools::redirectLink($this->context->link->getPageLink(
                'order-confirmation',
                true,
                null,
                ['id_cart' => $cart->id, 'id_module' => $this->module->id, 'key' => $customer->secure_key]
            ));
        }
    }
}
