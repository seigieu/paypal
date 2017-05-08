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

function upgrade_module_5_0_0($module)
{
    /** @var PayPal $module */
    if (!Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT *
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = \''._DB_NAME_.'\'
            AND TABLE_NAME = \''._DB_PREFIX_.'paypal_order\'
            AND COLUMN_NAME = \'id_payer\'')) {
        Db::getInstance()->execute(
            'ALTER IGNORE TABLE `'._DB_PREFIX_.'paypal_order`
                 ADD COLUMN id_payer VARCHAR(255)'
        );
    }
    if (!Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT *
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = \''._DB_NAME_.'\'
            AND TABLE_NAME = \''._DB_PREFIX_.'paypal_order\'
            AND COLUMN_NAME = \'id_payment\'')) {
        Db::getInstance()->execute(
            'ALTER IGNORE TABLE `'._DB_PREFIX_.'paypal_order`
                 ADD COLUMN id_payment VARCHAR(255)'
        );
    }

    return true;
}