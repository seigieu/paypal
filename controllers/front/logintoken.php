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

use PayPalModule\PayPalLogin;

/**
 * Class PayPalLoginTokenModuleFrontController
 */
class PayPalLoginTokenModuleFrontController extends \ModuleFrontController
{
    /** @var bool $ssl */
    public $ssl = true;

    /**
     * Init content
     *
     * @return void
     * @throws Adapter_Exception
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function initContent()
    {
        $login = new PayPalLogin();
        $obj = $login->getAuthorizationCode();
        if ($obj) {
            $context = Context::getContext();
            $customer = new Customer((int) $obj->id_customer);
            $context->cookie->id_customer = (int) ($customer->id);
            $context->cookie->customer_lastname = $customer->lastname;
            $context->cookie->customer_firstname = $customer->firstname;
            $context->cookie->logged = 1;
            $customer->logged = 1;
            $context->cookie->is_guest = $customer->isGuest();
            $context->cookie->passwd = $customer->passwd;
            $context->cookie->email = $customer->email;
            $context->customer = $customer;
            $context->cookie->write();
        }

        header('Content-Type: text/html');
        echo '<!doctype html><html><body><script type="text/javascript">window.opener.location.reload(false);window.close();</script></body></html>';
        die();
    }
}
