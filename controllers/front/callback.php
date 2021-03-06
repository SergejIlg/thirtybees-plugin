<?php
/**
* NOTICE OF LICENSE
*
* The MIT License (MIT)
*
* Copyright (c) 2015-2016 CoinGate
*
* Permission is hereby granted, free of charge, to any person obtaining a copy of
* this software and associated documentation files (the "Software"), to deal in
* the Software without restriction, including without limitation the rights to use,
* copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
* and to permit persons to whom the Software is furnished to do so, subject
* to the following conditions:
*
* The above copyright notice and this permission notice shall be included in all
* copies or substantial portions of the Software.
*
* THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
* IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
* FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
* AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
* WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR
* IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*
*  @author    CoinGate <info@coingate.com>
*  @copyright 2015-2016 CoinGate
*  @license   https://github.com/coingate/thirtybees-plugin/blob/master/LICENSE  The MIT License (MIT)
*/

require_once(_PS_MODULE_DIR_ . '/coingate/vendor/coingate/init.php');
require_once(_PS_MODULE_DIR_ . '/coingate/vendor/version.php');

class CoingateCallbackModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $cart_id = (int)Tools::getValue('order_id');
        $order_id = Order::getOrderByCartId($cart_id);
        $order = new Order($order_id);

        try {
            if (!$order) {
                $error_message = 'CoinGate Order #' . Tools::getValue('order_id') . ' does not exists';

                $this->logError($error_message, $cart_id);
                throw new Exception($error_message);
            }

            $token = $this->generateToken(Tools::getValue('order_id'));
            $cg_token = Tools::getValue('token');
            $cg_token = empty($cg_token) ? Tools::getValue('cg_token') : $cg_token;


            if (empty($cg_token) || strcmp($cg_token, $token) !== 0) {
                $error_message = 'CoinGate Token: ' . Tools::getValue('cg_token') . ' is not valid';

                $this->logError($error_message, $cart_id);
                throw new Exception($error_message);
            }

            $auth_token = Configuration::get('COINGATE_API_AUTH_TOKEN');
            $auth_token = empty($auth_token) ? Configuration::get('COINGATE_API_SECRET') : $auth_token;

            $cgConfig = array(
              'auth_token' => $auth_token,
              'environment' => (int)(Configuration::get('COINGATE_TEST')) == 1 ? 'sandbox' : 'live',
              'user_agent' => 'CoinGate - Thirtybees v'._PS_VERSION_
                .' Module v'.COINGATE_THIRTYBEES_MODULE_VERSION
            );

            \CoinGate\CoinGate::config($cgConfig);
            $cgOrder = \CoinGate\Merchant\Order::find(Tools::getValue('id'));

            if (!$cgOrder) {
                $error_message = 'CoinGate Order #' . Tools::getValue('id') . ' does not exists';

                $this->logError($error_message, $cart_id);
                throw new Exception($error_message);
            }

            if ($order->id_cart != $cgOrder->order_id) {
                $error_message = 'CG Order and PS cart does not match';

                $this->logError($error_message, $cart_id);
                throw new Exception($error_message);
            }


            switch ($cgOrder->status) {
                case 'paid':
                    if (((float) $order->getOrdersTotalPaid()) == ((float) $cgOrder->price_amount)) {
                        $order_status = 'PS_OS_PAYMENT';
                    } else {
                        $order_status = 'COINGATE_INVALID';
                        $this->logError('PS Orders Total does not match with Coingate Price Amount', $cart_id);
                    }
                    break;
                case 'pending':
                    $order_status = 'COINGATE_PENDING';
                    break;
                case 'confirming':
                    $order_status = 'COINGATE_CONFIRMING';
                    break;
                case 'expired':
                    $order_status = 'COINGATE_EXPIRED';
                    break;
                case 'invalid':
                    $order_status = 'COINGATE_INVALID';
                    break;
                case 'canceled':
                    $order_status = 'PS_OS_CANCELED';
                    break;
                case 'refunded':
                    $order_status = 'PS_OS_REFUND';
                    break;
                default:
                    $order_status = false;
            }

            if ($order_status !== false) {
                $history = new OrderHistory();
                $history->id_order = $order->id;
                $history->changeIdOrderState((int)Configuration::get($order_status), $order->id);
                $history->addWithemail(true, array(
                    'order_name' => Tools::getValue('order_id'),
                ));

                $this->context->smarty->assign(array(
                    'text' => 'OK'
                ));
            } else {
                $this->context->smarty->assign(array(
                    'text' => 'Order Status '.$cgOrder->status.' not implemented'
                ));
            }
        } catch (Exception $e) {
            $this->context->smarty->assign(array(
                'text' => get_class($e) . ': ' . $e->getMessage()
            ));
        }
        if (_PS_VERSION_ >= '1.7') {
            $this->setTemplate('module:coingate/views/templates/front/payment_callback.tpl');
        } else {
            $this->setTemplate('payment_callback.tpl');
        }
    }

    private function generateToken($order_id)
    {
        return hash('sha256', $order_id + (empty($this->module->api_auth_token) ?
        $this->module->api_secret :
        $this->module->api_auth_token
        ));
    }

    private function logError($message, $cart_id)
    {
        PrestaShopLogger::addLog($message, 3, null, 'Cart', $cart_id, true);
    }
}
