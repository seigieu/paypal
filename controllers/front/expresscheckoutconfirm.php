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

use PayPalModule\PayPalRestApi;
use PayPalModule\PayPalTools;

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class PayPalExpressCheckoutConfirmModuleFrontController
 *
 * Used for In-Context, Website Payments Standards and Website Payments Plus
 */
class PayPalExpressCheckoutConfirmModuleFrontController extends ModuleFrontController
{
    // @codingStandardsIgnoreStart
    /** @var bool $display_column_left */
    public $display_column_left = false;
    // @codingStandardsIgnoreEnd

    /** @var \PayPal $module */
    public $module;

    /** @var bool $ssl */
    public $ssl = true;

    /**
     * Initialize content
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws ReflectionException
     * @throws SmartyException
     * @throws Adapter_Exception
     */
    public function initContent()
    {
        parent::initContent();

        $payerId = Tools::getValue('PayerID');
        $paymentId = Tools::getValue('paymentId');

        $canShip = $this->assignCartSummary();
        if (!$canShip) {
            $this->setTemplate('cant-ship.tpl');

            return;
        }

        /** @var PayPalRestApi $rest */
        $rest = PayPalRestApi::getInstance();
        $previouslyAuthorized = Tools::getValue('authorized');
        /** @var array $payment */
        $payment = $rest->lookUpPayment($paymentId);
        if (!empty($payment['transactions'][0]['related_resources'][0]['authorization']['id'])) {
            $this->redirectToPayment($payerId, $paymentId);
        }

        $authorized = isset($payment['links']) && is_array($payment['links']) && in_array('capture', array_map(function ($item) {
                return $item['rel'];
            }, $payment['link']));

        if (!$authorized && !$previouslyAuthorized) {
            $rest->executePayment($payerId, $paymentId);
            Tools::redirectLink(
                $this->context->link->getModuleLink(
                    $this->module->name,
                    'expresscheckoutconfirm',
                    [
                        'PayerID'        => $payerId,
                        'paymentId'      => $paymentId,
                        'addressChanged' => (int) Tools::getValue('addressChanged'),
                        'authorized'     => 1,
                    ],
                    true
                )
            );
        } elseif ($previouslyAuthorized && $payment['state'] === 'authorized') {
            $rest->voidAuthorization($payment['id']);

            // Unable to authorize, try again
            Tools::redirectLink($this->context->link->getModuleLink($this->module->name, 'expresscheckout', [], true));
        }

        $params = [
            'PayerID'   => $payerId,
            'paymentId' => $paymentId,
        ];

        $this->context->smarty->assign([
            'confirm_form_action' => $this->context->link->getModuleLink($this->module->name, 'expresscheckout', $params, true),
        ]);

        $this->setTemplate('order-summary.tpl');
    }

    /**
     * Assign cart summary
     *
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws ReflectionException
     * @throws SmartyException
     * @throws Adapter_Exception
     */
    public function assignCartSummary()
    {
        // Rest API object
        $restApi = PayPalRestApi::getInstance();
        $cart = $this->context->cart;

        // Get the currency
        $currency = new Currency((int) $cart->id_currency);

        // Indicates whether we have checked the address before
        $addressChanged = (bool) Tools::getValue('addressChanged');
        $tbShippingAddress = new Address($cart->id_address_delivery);
        $tbBillingAddress = new Address($cart->id_address_invoice);

        // Check whether the address has been updated by the user
        /** @var array $paymentInfo */
        $paymentInfo = $restApi->lookUpPayment(Tools::getValue('paymentId'));

        if (!$addressChanged && PayPalTools::checkAddressChanged($paymentInfo, $tbShippingAddress)) {
            $tbBillingAddress = $tbShippingAddress = PayPalTools::checkAndModifyAddress($paymentInfo, $this->context->customer);
            $cart->id_address_delivery = $tbShippingAddress->id;
            $cart->id_address_invoice = $tbShippingAddress->id;

            $deliveryOption = $cart->getDeliveryOption();
            if (is_array($deliveryOption) && !empty($deliveryOption)) {
                $deliveryOption = array_values($deliveryOption);
                if (!in_array($cart->id_carrier, $deliveryOption)) {
                    $idCarrier = (int) trim($deliveryOption[0], ", ");
                    if (!$idCarrier) {
                        return false;
                    }
                    $cart->id_carrier = $idCarrier;

                }
            } else {
                return false;
            }

            $cart->save();
            Tools::redirectLink(
                $this->context->link->getModuleLink(
                    $this->module->name,
                    'expresscheckoutconfirm',
                    [
                        'PayerID'        => Tools::getValue('PayerID'),
                        'paymentId'      => Tools::getValue('paymentId'),
                        'addressChanged' => 1,
                        'authorized'     => (int) Tools::getValue('authorized'),
                    ],
                    true
                )
            );
        }

        // Grab the module's file path
        $reflection = new ReflectionClass($this->module);
        $moduleFilepath = $reflection->getFileName();
        $this->context->smarty->assign([
            'total'            => \Tools::displayPrice($this->context->cart->getOrderTotal(true), $currency),
            'use_mobile'       => (bool) $this->context->getMobileDevice(),
            'address_shipping' => $tbShippingAddress,
            'address_billing'  => $tbBillingAddress,
            'cart'             => $this->context->cart,
            'patternRules'     => ['avoid' => []],
            'cart_image_size'  => 'cart_default',
            'addressChanged'   => $addressChanged,
        ]);

        // With these smarty vars, generate the new template
        $this->context->smarty->assign([
            'paypal_cart_summary' => $this->module->display($moduleFilepath, 'views/templates/hook/paypal_cart_summary.tpl'),
        ]);

        return true;
    }

    /**
     * Redirect to payment
     *
     * @param string $payerId
     * @param string $paymentId
     *
     * @throws PrestaShopException
     */
    protected function redirectToPayment($payerId, $paymentId)
    {
        Tools::redirectLink(
            $this->context->link->getModuleLink(
                $this->module->name,
                'expresscheckout',
                [
                    'PayerID'   => $payerId,
                    'paymentId' => $paymentId,
                ],
                true
            )
        );
    }
}
