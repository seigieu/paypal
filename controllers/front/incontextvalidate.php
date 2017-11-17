<?php
/**
 * Copyright (C) 2017 thirty bees
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
 * @copyright 2017 thirty bees
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_TB_VERSION_')) {
    exit;
}

use PayPalModule\PayPalCustomer;
use PayPalModule\PayPalRestApi;

/**
 * Class PayPalInContextValidateModuleFrontController
 */
class PayPalInContextValidateModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public $payerId;

    public $paymentId;

    /** @var \PayPal $module */
    public $module;

    /**
     * Initialize content
     */
    public function initContent()
    {
        $this->payerId = \Tools::getValue('PayerID');
        $this->paymentId = \Tools::getValue('paymentId');

        if ($this->payerId && $this->paymentId) {
            $callApiPaypalPlus = new PayPalRestApi();
            $callApiPaypalPlus->getWebProfile();
            $payment = $callApiPaypalPlus->lookUpPayment($this->paymentId);
            $email = $payment->payer->payer_info->email;
            /* Create Customer if not exist with address etc */
            if ($this->context->cookie->logged) {
                $idCustomer = PaypalCustomer::getPayPalCustomerIdByEmail($email);
                if (!$idCustomer) {
                    $ppc = new PayPalCustomer();
                    $ppc->id_customer = $this->context->customer->id;
                    $ppc->paypal_email = $email;
                    $ppc->add();
                }

                $customer = $this->context->customer;
            } elseif ($idCustomer = \Customer::customerExists($email, true)) {
                $customer = new \Customer($idCustomer);
            } else {
                $customer = $this->setCustomerInformation($payment, $email);
                $customer->add();

                $ppc = new PayPalCustomer();
                $ppc->id_customer = $this->context->customer->id;
                $ppc->paypal_email = $email;
                $ppc->add();
            }

            $shippingAddress = $payment->payer->payer_info->shipping_address;
            if (!isset($shippingAddress->line1) || !isset($shippingAddress->city)
                || !isset($shippingAddress->postal_code) || !isset($shippingAddress->country_code)
            ) {
                Tools::redirectLink($this->context->link->getPageLink('order'));
            }

            $addresses = $customer->getAddresses($this->context->language->id);
            foreach ($addresses as $address) {
                if ($address['alias'] == 'PayPal_Address') {
                    //If address has already been created
                    $address = new \Address($address['id_address']);
                    break;
                }
            }

            /* Create address */
            if (isset($address) && is_array($address) && isset($address['id_address'])) {
                $address = new \Address($address['id_address']);
            }

            if ((!isset($address) || !$address || !$address->id) && $customer->id) {
                //If address does not exists, we create it
                $address = $this->setCustomerAddress($payment, $customer);
                $address->add();
            }

            $cart = $this->context->cart;
            if ($customer->id && isset($address) && $address->id) {
                $cart->id_customer = $customer->id;
                $cart->id_address_delivery = $address->id;
                $cart->id_address_invoice = $address->id;
                $cart->id_guest = $this->context->cookie->id_guest;

                $cart->update();
            }

            if (isset($payment->state) && $payment->state === 'created') {
                $params = [
                    'id_cart'    => $this->context->cart->id,
                    'id_module'  => $this->module->id,
                    'secure_key' => $this->context->cart->secure_key,
                    'PayerID'    => $this->payerId,
                    'paymentId'  => $this->paymentId,
                ];

                header('Content-Type: application/json');
                die(json_encode([
                    'success'    => true,
                    'confirmUrl' => $this->context->link->getModuleLink('paypal', 'expresscheckoutconfirm', $params, true),
                ]));
            } else {
                if (($this->context->customer->is_guest) || $this->context->customer->id == false) {
                    /* If guest we clear the cookie for security reason */
                    $this->context->customer->mylogout();
                }

                header('Content-Type: application/json');
                die(json_encode(['success' => false]));
            }
        }
    }

    /**
     * Set customer information
     * Used to create user account with PayPal account information
     *
     * @param \stdClass $payment
     * @param string    $email
     *
     * @return Customer
     */
    protected function setCustomerInformation($payment, $email)
    {
        $customer = new \Customer();
        $customer->email = $email;
        $customer->firstname = $payment->payer->payer_info->first_name;
        $customer->lastname = $payment->payer->payer_info->last_name;
        $customer->passwd = \Tools::encrypt(\Tools::passwdGen());

        return $customer;
    }

    /**
     * Set customer address (when not logged in)
     * Used to create user address with PayPal account information
     *
     * @param \stdClass $payment
     * @param \Customer $customer
     * @param int|null  $id
     *
     * @return Address
     *
     * @todo: figure out what $id is xD
     */
    protected function setCustomerAddress($payment, \Customer $customer, $id = null)
    {
        $address = new \Address($id);
        $payerInfo = $payment->payer->payer_info;
        $shippingAddress = $payerInfo->shipping_address;
        $address->id_country = \Country::getByIso($shippingAddress->country_code);
        if ($id == null) {
            $address->alias = 'Paypal address';
        }

        $name = trim($shippingAddress->recipient_name);
        $name = explode(' ', $name);
        if (isset($name[1])) {
            $firstname = $name[0];
            unset($name[0]);
            $lastname = implode(' ', $name);
        } else {
            $firstname = $payerInfo->first_name;
            $lastname = $payerInfo->last_name;
        }

        $address->lastname = $lastname;
        $address->firstname = $firstname;
        $address->address1 = $shippingAddress->line1;
        if (isset($shippingAddress->line2)) {
            $address->address2 = $shippingAddress->line2;
        }

        $address->city = $shippingAddress->city;
        if (\Country::containsStates($address->id_country)) {
            $address->id_state = (int) \State::getIdByIso($shippingAddress->state, $address->id_country);
        }

        $address->postcode = $shippingAddress->postal_code;
        if (isset($shippingAddress->phone)) {
            $address->phone = $shippingAddress->phone;
        }

        $address->id_customer = $customer->id;

        return $address;
    }
}
