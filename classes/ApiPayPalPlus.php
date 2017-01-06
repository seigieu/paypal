<?php
/**
 * 2017 Thirty Bees
 * 2007-2016 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 *  @author    Thirty Bees <modules@thirtybees.com>
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2017 Thirty Bees
 *  @copyright 2007-2016 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace PayPalModule;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ApiPayPalPlus
{
    /*     * ********************************************************* */
    /*     * ******************** CONNECT METHODS ******************** */
    /*     * ********************************************************* */

    /**
     * ApiPaypalPlus constructor.
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function __construct()
    {
        if (class_exists('Context')) {
            $this->context = \Context::getContext();
        } else {
            global $smarty, $cookie;
            $this->context = new \StdClass();
            $this->context->smarty = $smarty;
            $this->context->cookie = $cookie;
        }
    }

    /**
     * @param      $url
     * @param      $body
     * @param bool $httpHeader
     * @param bool $identify
     *
     * @return mixed
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function sendByCURL($url, $body, $httpHeader = false, $identify = false)
    {
        $ch = curl_init();

        if ($ch) {

            if ((int) \Configuration::get('PAYPAL_SANDBOX') == 1) {
                curl_setopt($ch, CURLOPT_URL, 'https://api.sandbox.paypal.com'.$url);
            } else {
                curl_setopt($ch, CURLOPT_URL, 'https://api.paypal.com'.$url);
            }

            if ($identify) {
                curl_setopt($ch, CURLOPT_USERPWD, \Configuration::get('PAYPAL_PLUS_CLIENT_ID').':'.\Configuration::get('PAYPAL_PLUS_SECRET'));
            }

            if ($httpHeader) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeader);
            }
            if ($body) {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($identify) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }

            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSLVERSION, defined('CURL_SSLVERSION_TLSv1') ? CURL_SSLVERSION_TLSv1 : 1);
            curl_setopt($ch, CURLOPT_VERBOSE, false);

            $result = curl_exec($ch);

            curl_close($ch);
        }

        return $result;
    }

    /**
     * @param $url
     * @param $body
     *
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getToken($url, $body)
    {
        $result = $this->sendByCURL($url, $body, false, true);

        /*
         * Init variable
         */
        $oPayPalToken = \Tools::jsonDecode($result);

        if (isset($oPayPalToken->error)) {
            return false;
        } else {
            $timeMax = time() + $oPayPalToken->expires_in;
            $accessToken = $oPayPalToken->access_token;

            /*
             * Set Token in Cookie
             */
            $this->context->cookie->__set('paypal_access_token_time_max', $timeMax);
            $this->context->cookie->__set('paypal_access_token_access_token', $accessToken);
            $this->context->cookie->write();

            return $accessToken;
        }
    }

    /**
     * @return \stdClass
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function createWebProfile()
    {

        $presentation = new \stdClass();
        $presentation->brand_name = \Configuration::get('PS_SHOP_NAME');
        $presentation->logo_image = _PS_BASE_URL_.__PS_BASE_URI__.'img/logo.jpg';
        $presentation->locale_code = \Tools::strtoupper(\Language::getIsoById($this->context->language->id));

        $inputFields = new \stdClass();
        $inputFields->allow_note = true;
        $inputFields->no_shipping = 1;
        $inputFields->address_override = 1;

        $flowConfig = new \stdClass();
        $flowConfig->landing_page_type = "billing";

        $webProfile = new \stdClass();
        $webProfile->name = \Configuration::get('PS_SHOP_NAME');
        $webProfile->presentation = $presentation;
        $webProfile->input_fields = $inputFields;
        $webProfile->flow_config = $flowConfig;

        return $webProfile;
    }

    /**
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getWebProfile()
    {
        $accessToken = $this->getToken(URL_PPP_CREATE_TOKEN, array('grant_type' => 'client_credentials'));

        if ($accessToken) {

            $data = $this->createWebProfile();

            $header = array(
                'Content-Type:application/json',
                'Authorization:Bearer '.$accessToken,
            );

            $result = \Tools::jsonDecode($this->sendByCURL(URL_PPP_WEBPROFILE, \Tools::jsonEncode($data), $header));

            if (isset($result->id)) {
                return $result->id;
            } else {

                $results = $this->getListProfile();

                foreach ($results as $result) {
                    if (isset($result->id) && $result->name == \Configuration::get('PS_SHOP_NAME')) {
                        return $result->id;
                    }
                }

                return false;
            }
        }
    }

    /**
     * @return array
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function getListProfile()
    {

        $accessToken = $this->getToken(URL_PPP_CREATE_TOKEN, array('grant_type' => 'client_credentials'));

        if ($accessToken) {

            $header = array(
                'Content-Type:application/json',
                'Authorization:Bearer '.$accessToken,
            );

            return \Tools::jsonDecode($this->sendByCURL(URL_PPP_WEBPROFILE, false, $header));
        }
    }

    /**
     * @return bool
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    public function refreshToken()
    {
        if ($this->context->cookie->paypal_access_token_time_max < time()) {
            return $this->getToken(URL_PPP_CREATE_TOKEN, array('grant_type' => 'client_credentials'));
        } else {
            return $this->context->cookie->paypal_access_token_access_token;
        }
    }

    /**
     * @param $customer
     * @param $cart
     *
     * @return \stdClass
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function _createObjectPayment($customer, $cart)
    {
        /*
         * Init Variable
         */
        $oCurrency = new \Currency($cart->id_currency);
        $address = new \Address((int) $cart->id_address_invoice);

        $country = new \Country((int) $address->id_country);
        $iso_code = $country->iso_code;

        if (version_compare(_PS_VERSION_, '1.5', '<')) {
            $totalShippingCostWithoutTax = $cart->getOrderShippingCost(null, false);
        } else {
            $totalShippingCostWithoutTax = $cart->getTotalShippingCost(null, false);
        }

        $totalCartWithTax = $cart->getOrderTotal(true);
        $totalCartWithoutTax = $cart->getOrderTotal(false);
        $total_tax = $totalCartWithTax - $totalCartWithoutTax;

        if ($cart->gift) {
            if (version_compare(_PS_VERSION_, '1.5.3.0', '>=')) {
                $giftWithoutTax = $cart->getGiftWrappingPrice(false);
            } else {
                $giftWithoutTax = (float) (Configuration::get('PS_GIFT_WRAPPING_PRICE'));
            }

        } else {
            $giftWithoutTax = 0;
        }

        $cartItems = $cart->getProducts();

        $shop_url = \PayPal::getShopDomainSsl(true, true);

        /*
         * Création de l'obj à envoyer à Paypal
         */

        $state = new \State($address->id_state);
        $shipping_address = new \stdClass();
        $shipping_address->recipient_name = $address->alias;
        $shipping_address->type = 'residential';
        $shipping_address->line1 = $address->address1;
        $shipping_address->line2 = $address->address2;
        $shipping_address->city = $address->city;
        $shipping_address->country_code = $iso_code;
        $shipping_address->postal_code = $address->postcode;
        $shipping_address->state = ($state->iso_code == null) ? '' : $state->iso_code;
        $shipping_address->phone = $address->phone;

        $payer_info = new \stdClass();
        $payer_info->email = '"'.$customer->email.'"';
        $payer_info->first_name = $address->firstname;
        $payer_info->last_name = $address->lastname;
        $payer_info->country_code = '"'.$iso_code.'"';
        $payer_info->shipping_address = array($shipping_address);

        $payer = new \stdClass();
        $payer->payment_method = "paypal";
        //$payer->payer_info = $payer_info; // Objet set by PayPal

        $aItems = array();
        /* Item */
        foreach ($cartItems as $cartItem) {

            $item = new \stdClass();
            $item->name = $cartItem['name'];
            $item->currency = $oCurrency->iso_code;
            $item->quantity = $cartItem['quantity'];
            $item->price = number_format(round($cartItem['price'], 2), 2);
            $item->tax = number_format(round($cartItem['price_wt'] - $cartItem['price'], 2), 2);
            $aItems[] = $item;
            unset($item);
        }

        /* ItemList */
        $itemList = new \stdClass();
        $itemList->items = $aItems;

        /* Detail */
        $details = new \stdClass();
        $details->shipping = number_format($totalShippingCostWithoutTax, 2);
        $details->tax = number_format($total_tax, 2);
        $details->handling_fee = number_format($giftWithoutTax, 2);
        $details->subtotal = number_format($totalCartWithoutTax - $totalShippingCostWithoutTax - $giftWithoutTax, 2);

        /* Amount */
        $amount = new \stdClass();
        $amount->total = number_format($totalCartWithTax, 2);
        $amount->currency = $oCurrency->iso_code;
        $amount->details = $details;

        /* Transaction */
        $transaction = new \stdClass();
        $transaction->amount = $amount;
        $transaction->item_list = $itemList;
        $transaction->description = "Payment description";

        /* Redirecte Url */

        $redirectUrls = new \stdClass();
        $redirectUrls->cancel_url = $shop_url._MODULE_DIR_.'paypal/paypal_plus/submit.php?id_cart='.(int) $cart->id;
        $redirectUrls->return_url = $shop_url._MODULE_DIR_.'paypal/paypal_plus/submit.php?id_cart='.(int) $cart->id;

        /* Payment */
        $payment = new \stdClass();
        $payment->transactions = array($transaction);
        $payment->payer = $payer;
        $payment->intent = "sale";
        if (\Configuration::get('PAYPAL_WEB_PROFILE_ID')) {
            $payment->experience_profile_id = \Configuration::get('PAYPAL_WEB_PROFILE_ID');
        }
        $payment->redirect_urls = $redirectUrls;

        return $payment;
    }

    /**
     * @param $customer
     * @param $cart
     * @param $accessToken
     *
     * @return mixed
     *
     * @author    PrestaShop SA <contact@prestashop.com>
     * @copyright 2007-2016 PrestaShop SA
     * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
     */
    protected function createPayment($customer, $cart, $accessToken)
    {

        $data = $this->_createObjectPayment($customer, $cart);

        $header = array(
            'Content-Type:application/json',
            'Authorization:Bearer '.$accessToken,
        );

        $result = $this->sendByCURL(URL_PPP_CREATE_PAYMENT, \Tools::jsonEncode($data), $header);

        return $result;
    }
}
