<?php

class JokulCcRequestModuleFrontController extends ModuleFrontController
{

	public function postProcess()
	{
		$jokulcc = new jokulcc();
		$task		         = $_GET['task'];
		$path = 'module:jokulcc/views/templates/front/';

		switch ($task) {
			case "redirect":
				if (empty($_GET['orderid'])) {
					echo "Stop : Access Not Valid";
					die;
				}

				$config = $jokulcc->getServerConfig();
				$order_id = $jokulcc->get_order_id($_GET['orderid']);

				$extra_vars = array(
                    '{order_name}' => $_GET['invoiceNumber']
                );

				if (!$order_id) {
					$order_state = $config['DOKU_CC_AWAITING_PAYMENT'];
					$jokulcc->validateOrder($_GET['orderid'], $order_state, $_GET['amount'], $jokulcc->displayName,'', $extra_vars);
					$order_id = $jokulcc->get_order_id($_GET['orderid']);
				}

				$order = new Order($order_id);
				$order->reference = $_GET['invoiceNumber'];
				$order->update();

				$dateTime = gmdate("Y-m-d H:i:s");
				$statusNotif = $jokulcc->get_request_notif($_GET['orderid']);
				if ($statusNotif == '1') {
					$status_no      = $config['DOKU_CC_PAYMENT_RECEIVED'];
					$jokulcc->update_notify($_GET['orderid'], '', $dateTime, '1', '1');
					
					$email_data = array(
						'{payment_channel}' => $payment_channel,
						'{amount}' => $_GET['amount']
					);
					$jokulcc->set_order_status($order_id, $status_no, $email_data);
				}
				
				$template       = "pending_cc.tpl";
				$payment_channel = "Credit Card";
						
				$this->context->smarty->assign(array('payment_channel' => $payment_channel));
				$this->setTemplate($path . $template);

				$cart = $this->context->cart;

				if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
					Tools::redirect('index.php?controller=order&step=1');
				}

				$customer = new Customer($cart->id_customer);
				if (!Validate::isLoadedObject($customer))
					Tools::redirect('index.php?controller=order&step=1');

				$currency = $this->context->currency;

				$total = (float)$cart->getOrderTotal(true, Cart::BOTH);

				Configuration::updateValue('PAYMENT_CHANNEL', trim($payment_channel));
				Configuration::updateValue('PAYMENT_AMOUNT', $_GET['amount']);

				$config = Configuration::getMultiple(array('SERVER_CC_DEST', 'MALL_CC_ID_DEV', 'SHARED_CC_KEY_DEV', 'MALL_CC_ID_PROD', 'SHARED_CC_KEY_PROD'));

				if (empty($config['SERVER_CC_DEST']) || intval($config['SERVER_CC_DEST']) == 0) {
					$MALL_ID    = Tools::safeOutput(Configuration::get('MALL_CC_ID_DEV'));
					$SHARED_KEY = Tools::safeOutput(Configuration::get('SHARED_CC_KEY_DEV'));
				} else {
					$MALL_ID    = Tools::safeOutput(Configuration::get('MALL_CC_ID_PROD'));
					$SHARED_KEY = Tools::safeOutput(Configuration::get('SHARED_CC_KEY_PROD'));
				}

				$mailVars = array(
					'{jokulcc_mall_id}'     => $MALL_ID,
					'{jokulcc_shared_key}'  => $SHARED_KEY
				);
				
				Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
				break;
			case "redirectFailed":
				$template       = "failed.tpl";
				parent::initContent();
				$this->setTemplate($path . $template);
				break;
		}
	}
}
