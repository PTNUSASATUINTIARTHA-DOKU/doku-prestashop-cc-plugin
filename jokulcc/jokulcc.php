<?php

/*
    Plugin Name : Prestashop DOKU Jokul Credit Card Payment Gateway
    Plugin URI  : http://www.doku.com
    Description : DOKU Jokul Credit Card Payment Gateway for Prestashop 1.7
    Version     : 1.0.0
    Author      : DOKU
    Author URI  : http://www.doku.com
*/

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_'))
	exit;

class JokulCc extends PaymentModule
{
	private $_html = '';
	private $_postErrors = array();
	public $payment_channels;
	public $ip_range;
	public $cc_channel;

	public function __construct()
	{
		$this->name             = 'jokulcc';
		$this->tab              = 'payments_gateways';
		$this->author           = 'DOKU';
		$this->version          = '1.0.0';
		$this->need_instance 	= 0;
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
		$this->bootstrap 		= true;

		$this->controllers = array('payment', 'validation');
		$this->is_eu_compatible = 1;

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		parent::__construct();
		$this->displayName      = $this->l('Jokul - Credit Card');
		$this->description      = $this->l('Accept payments through Credit Card with Jokul. Make it easy for your customers to purchase on your store.');
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

		$this->cc_channel       = array("Credit Card");
	}

	public function install()
	{
		parent::install();
		$this->registerHook('paymentOptions');
		$this->registerHook('displayPaymentReturn');
		$this->registerHook('updateOrderStatus');
		$this->addDOKUOrderStatus();
		$this->copyEmailFiles();
		$this->createjokulccTable();
		Configuration::updateGlobalValue('DOKU_CC_NAME', "Credit Card");
		Configuration::updateGlobalValue('DOKU_CC_DESCRIPTION', "Please select payment channel");
		return true;
	}

	public function hookPaymentOptions($params)
	{
		if (!$this->active) {
			return;
		}

		if (!$this->checkCurrency($params['cart'])) {
			return;
		}

		$cart = $this->context->cart;
		$this->execPayment($cart);

		$paymentOption = new PaymentOption();

		$paymentForm = $this->context->smarty->fetch('module:jokulcc/views/templates/hook/payment_channel.tpl');
		$paymentOption->setCallToActionText(Configuration::get('DOKU_CC_NAME'))
			->setForm($paymentForm)
			->setBinary(true);

		$payment_options = [
			$paymentOption,
		];

		return $payment_options;
	}

	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module)) {
			foreach ($currencies_module as $currency_module) {
				if ($currency_order->id == $currency_module['id_currency']) {
					return true;
				}
			}
		}
		return false;
	}

	public function uninstall()
	{
		if (
			!parent::uninstall()
		) {
			return false;
		} else {
			Configuration::deleteByName('SERVER_CC_DEST');
			Configuration::deleteByName('MALL_CC_ID_DEV');
			Configuration::deleteByName('SHARED_CC_KEY_DEV');
			Configuration::deleteByName('MALL_CC_ID_PROD');
			Configuration::deleteByName('SHARED_CC_KEY_PROD');
			Configuration::deleteByName('DOKU_CC_NAME');
			Configuration::deleteByName('DOKU_CC_DESCRIPTION');
			Configuration::deleteByName('LANGUAGE');
			Configuration::deleteByName('BACKGROUND_COLOR');
			Configuration::deleteByName('FONT_COLOR');
			Configuration::deleteByName('BUTTON_BACKGROUND_COLOR');
			Configuration::deleteByName('BUTTON_FONT_COLOR');
			Configuration::deleteByName('PAYMENT_CHANNEL');
			Configuration::deleteByName('PAYMENT_CODE');
			Configuration::deleteByName('PAYMENT_CHANNELS');
			Configuration::deleteByName('PAYMENT_CHANNELS_CC');

			parent::uninstall();
			Db::getInstance()->Execute("DROP TABLE `" . _DB_PREFIX_ . "jokulcc`");
			parent::uninstall();
			return true;
		}
	}

	function createjokulccTable()
	{
		$db = Db::getInstance();
		$query = "CREATE TABLE IF NOT EXISTS `" . _DB_PREFIX_ . "jokulcc`(
			`trx_id` int( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`ip_address` VARCHAR( 16 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
			`process_type` VARCHAR( 15 ) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
			`process_datetime` DATETIME NULL,    
			`invoice_number` VARCHAR(30) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
			`order_id` VARCHAR(30) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
			`amount` DECIMAL( 20,2 ) NOT NULL DEFAULT '0',
			`raw_post_data` TEXT COLLATE utf8_unicode_ci,  
			`message` TEXT COLLATE utf8_unicode_ci,
			`notif` VARCHAR(2) COLLATE utf8_unicode_ci DEFAULT '0'
		)";

		$db->Execute($query);
	}

	private function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit')) {
			if (intval(Tools::getValue('server_cc_dest')) == 0) {
				if (!Tools::getValue('mall_cc_id_dev'))
					$this->_postErrors[] = $this->l('Client ID is required.');
				elseif (!Tools::getValue('shared_cc_key_dev'))
					$this->_postErrors[] = $this->l('Secret Key is required.');
			} else {
				if (!Tools::getValue('mall_cc_id_prod'))
					$this->_postErrors[] = $this->l('Client ID is required.');
				elseif (!Tools::getValue('shared_cc_key_prod'))
					$this->_postErrors[] = $this->l('Secret Key is required.');
			}
		}
	}

	private function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit')) {
			Configuration::updateValue('SERVER_CC_DEST',               			trim(Tools::getValue('server_cc_dest')));
			Configuration::updateValue('MALL_CC_ID_DEV',               			trim(Tools::getValue('mall_cc_id_dev')));
			Configuration::updateValue('SHARED_CC_KEY_DEV',            			trim(Tools::getValue('shared_cc_key_dev')));
			Configuration::updateValue('MALL_CC_ID_PROD',              			trim(Tools::getValue('mall_cc_id_prod')));
			Configuration::updateValue('SHARED_CC_KEY_PROD',           			trim(Tools::getValue('shared_cc_key_prod')));
			Configuration::updateValue('DOKU_CC_NAME',                 			trim(Tools::getValue('doku_cc_name')));
			Configuration::updateValue('DOKU_CC_DESCRIPTION',          			trim(Tools::getValue('doku_cc_description')));
			Configuration::updateValue('LANGUAGE', 								trim(Tools::getValue('lang')));
			Configuration::updateValue('BACKGROUND_COLOR', 						trim(Tools::getValue('background_color')));
			Configuration::updateValue('FONT_COLOR', 							trim(Tools::getValue('font_color')));
			Configuration::updateValue('BUTTON_BACKGROUND_COLOR', 				trim(Tools::getValue('buttton_background_color')));
			Configuration::updateValue('BUTTON_FONT_COLOR', 					trim(Tools::getValue('buttton_font_color')));
			Configuration::updateValue('PAYMENT_CHANNELS', 						trim(Tools::getValue('payment_channels')));
			Configuration::updateValue('PAYMENT_CHANNELS_CC', 				    trim(Tools::getValue('payment_channels_CC')));
		}
		$this->_html .= '<div class="alert alert-success conf confirm"> ' . $this->l('Settings updated') . '</div>';
	}

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit')) {
			$this->_postValidation();
			if (!sizeof($this->_postErrors)) {
				$this->_postProcess();
			} else {
				foreach ($this->_postErrors as $err) {
					$this->_html .= '<div class="alert error">' . $err . '</div>';
				}
			}
		} else {
			$this->_html .= '<br />';
		}

		$this->_html .= $this->_displayBanner();
		$this->_html .= $this->renderForm();

		return $this->_html;
	}

	public function renderForm()
	{
		$payment_channels = [
			[
				'id_option' => 'CC',
				'name' 		=> 'Credit Card',
			]

		];

		$environment = [
			[
				'id_option' => 'https://api-sandbox.doku.com',
				'name' 		=> 'Sandbox',
			],

			[
				'id_option' => 'https://jokul.doku.com',
				'name' 		=> 'Production',
			],
		];

		$language = [
			[
				'id_option' => 'ID',
				'name' 		=> 'ID',
			],

			[
				'id_option' => 'EN',
				'name' 		=> 'EN',
			],
		];

		//CONFIGURATION FORM
		$fields_form = [

			'form'	 => [

				'legend' => [
					'title' => $this->l('Jokul - Credit Card Payment Configuration'),
					'icon' => 'icon-cogs'
				],
				'input' => 	[

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Payment Method Title'),
						'name'  => 'doku_cc_name',
						'hint'  => [
							$this->l('This controls the title which the user sees during checkout.')
						],
					],

					[
						'type'  => 'textarea',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Description'),
						'name'  => 'doku_cc_description',
						'hint'  => [
							$this->l('This controls the description which the user sees during checkout.')
						],
					],

					[
						'type' 		=> 'select',
						'label' 	=> $this->l('Environment'),
						'name' 		=> 'server_cc_dest',
						'required' 	=> false,
						'hint'  	=> [
							$this->l('Sandbox mode provides you with a chance to test your gateway integration with Jokul. The payment requests will be send to the Jokul sandbox URL. Production to start accepting live payment.')
						],
						'options' 	=> [
							'query' => $environment,
							'id' 	=> 'id_option',
							'name' 	=> 'name'
						]
					],
					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Sandbox Client ID'),
						'name'  => 'mall_cc_id_dev',
						'hint'  => [
							$this->l('Sandbox Client ID.'),
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Sandbox Secret Key'),
						'name'  => 'shared_cc_key_dev',
						'hint'  => [
							$this->l('Sandbox Secret Key.')
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Production Client ID'),
						'name'  => 'mall_cc_id_prod',
						'hint'  => [
							$this->l('Production Client ID.'),
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Production Secret Key'),
						'name'  => 'shared_cc_key_prod',
						'hint'  => [
							$this->l('Production Secret Key.')
						],
					],

					[
						'type' 		=> 'checkbox',
						'label' 	=> $this->l('Payment Channels'),
						'name' 		=> 'payment_channels',
						'multiple' 	=> true,

						'hint' 		=> [
							$this->l('Choose the payment channels that you can offer to the customers. The payment channels will be presented to the customer on the checkout page.')
						],

						'values' 	=> [
							'query'  => $payment_channels,
							'id' 	 => 'id_option',
							'name' 	 => 'name',
						]
					],

					[
						'type' 		=> 'select',
						'label' 	=> $this->l('language'),
						'name' 		=> 'lang',
						'required' 	=> false,
						'hint'  	=> [
							$this->l('Select Language form')
						],
						'options' 	=> [
							'query' => $language,
							'id' 	=> 'id_option',
							'name' 	=> 'name'
						]
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Background Color'),
						'name'  => 'background_color',
						'hint'  => [
							$this->l('Background Color')
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Font Color'),
						'name'  => 'font_color',
						'hint'  => [
							$this->l('Font Color')
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Button Background Color'),
						'name'  => 'buttton_background_color',
						'hint'  => [
							$this->l('Button Background Color')
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Button Font Color'),
						'name'  => 'buttton_font_color',
						'hint'  => [
							$this->l('Button Font Color')
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Notification URL'),
						'name'  => 'notification_url',
						'disabled' => true,
						'desc' => 'Set this URL to Jokul Back Office',
						'hint'  => [
							$this->l('Notification URL.')
						],
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
				]
			]
		];

		$helper 				= new HelperForm();
		$helper->show_toolbar 	= false;

		$helper->table 			= $this->table;
		$lang 					= new Language((int)Configuration::get('PS_LANG_DEFAULT'));


		$helper->default_form_language 		= $lang->id;

		$helper->allow_employee_form_lang 	= Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;


		$this->fields_form 		= array();
		$helper->id 			= (int)Tools::getValue('id_carrier');
		$helper->identifier 	= $this->identifier;
		$helper->submit_action 	= 'btnSubmit';


		$helper->currentIndex 	= $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;


		$helper->token 		= Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars 	= array(
			'fields_value' 	=> $this->getConfigFieldsValues(),
			'languages' 	=> $this->context->controller->getLanguages(),
			'id_language' 	=> $this->context->language->id
		);

		return $helper->generateForm(array($fields_form));
	}

	public function execPayment($cart)
	{
		if (!$this->active)
			return;

		$basket = '';
		global $cookie, $smarty;

		$jokulcc = new jokulcc();
		$cart            = new Cart(intval($cookie->id_cart));
		$address         = new Address(intval($cart->id_address_invoice));
		$country         = new Country(intval($address->id_country));
		$state           = NULL;
		if ($address->id_state)
			$state       = new State(intval($address->id_state));
		$customer        = new Customer(intval($cart->id_customer));
		$currency_order  = new Currency(intval($cart->id_currency));
		$products        = $cart->getProducts();
		$summarydetail   = $cart->getSummaryDetails();

		$i = 0;
		$basket = '';
		$itemQty = array();

		foreach ($products as $product) {
			$name_wt1  = preg_replace("/([^a-zA-Z0-9.\-=:&% ]+)/", " ", $product['name']);
			$name_wt = str_replace(',', '-', $name_wt1);
			$price_wt = number_format($product['price_wt'], 2, '.', '');
			$total_wt = number_format($product['total_wt'], 2, '.', '');

			$basket .= $name_wt . ',';
			$basket .= $price_wt . ',';
			$basket .= $product['cart_quantity'] . ',';
			$basket .= $total_wt . ';';
			$itemQty[] = array('name' => $product['name'], 'price' => $product['price_wt'], 'quantity' => "1");
		}

		# Discount
		if ($summarydetail['total_discounts'] > 0) {
			$nDiskon =    number_format($summarydetail['total_discounts'], 2, '.', '');
			$nMinus  = -1 * $nDiskon;

			$basket .= 'Total Discount ,';
			$basket .=  $nMinus . ',';
			$basket .=  '1,';
			$basket .=  $nMinus . ';';
		}

		# Shipping
		if ($summarydetail['total_shipping'] > 0) {
			$basket .= 'Shipping Cost ,';
			$basket .=  number_format($summarydetail['total_shipping'], 2, '.', '') . ',';
			$basket .=  '1,';
			$basket .=  number_format($summarydetail['total_shipping'], 2, '.', '') . ';';
		}

		# Gift Wrapping		
		if ($summarydetail['total_wrapping'] > 0) {
			$basket .= 'Gift Wrapping ,';
			$basket .=  number_format($summarydetail['total_wrapping'], 2, '.', '') . ',';
			$basket .=  '1,';
			$basket .=  number_format($summarydetail['total_wrapping'], 2, '.', '') . ';';
		}

		$total = $cart->getOrderTotal(true, Cart::BOTH);

		$this->total_amount = intval($total);

		//$order       = new Order();
		$server_cc_dest = Tools::safeOutput(Configuration::get('SERVER_CC_DEST'));

		if (empty($server_cc_dest) || intval($server_cc_dest) == 0) {
			$MALL_ID     			= Tools::safeOutput(Configuration::get('MALL_CC_ID_DEV'));
			$SHARED_KEY  			= Tools::safeOutput(Configuration::get('SHARED_CC_KEY_DEV'));
			$URL				 	= "";
			$URL_MERCHANTHOSTED 	= "config_url_cc_dev.ini";
		} else {
			$MALL_ID     			= Tools::safeOutput(Configuration::get('MALL_CC_ID_PROD'));
			$SHARED_KEY  			= Tools::safeOutput(Configuration::get('SHARED_CC_KEY_PROD'));
			$URL				 	= "";
			$URL_MERCHANTHOSTED 	= "config_url_cc_prod.ini";
		}

		# Set Redirect Parameter
		$CURRENCY            			= 360;
		$invoiceNumber  	   			= strtoupper(Tools::passwdGen(9, 'NO_NUMERIC'));
		$orderid                        = intval($cart->id);
		$NAME                			= Tools::safeOutput($address->firstname . ' ' . $address->lastname);
		$EMAIL               			= $customer->email;
		$ADDRESS             			= Tools::safeOutput($address->address1 . ' ' . $address->address2);
		$CITY                			= Tools::safeOutput($address->city);
		$ZIPCODE             			= Tools::safeOutput($address->postcode);
		$STATE               			= Tools::safeOutput($state->name);
		$REQUEST_DATETIME    			= date("YmdHis");
		$IP_ADDRESS          			= $this->getipaddress();
		$PROCESS_DATETIME    			= date("Y-m-d H:i:s");
		$PROCESS_TYPE        			= "REQUEST";
		$amount              			= $total;
		$PHONE               			= trim($address->phone_mobile);
		$PAYMENT_CHANNEL     			= "";
		$LANGUAGE 						= Tools::safeOutput(Configuration::get('LANGUAGE'));
		$BACKGROUND_COLOR 				= Tools::safeOutput(Configuration::get('BACKGROUND_COLOR'));
		$FONT_COLOR 					= Tools::safeOutput(Configuration::get('FONT_COLOR'));
		$BUTTON_BACKGROUND_COLOR 		= Tools::safeOutput(Configuration::get('BUTTON_BACKGROUND_COLOR'));
		$BUTTON_FONT_COLOR 				= Tools::safeOutput(Configuration::get('BUTTON_FONT_COLOR'));
		$DATETIME = gmdate("Y-m-d H:i:s");
		$DATETIME = date(DATE_ISO8601, strtotime($DATETIME));
		$DATETIMEFINAL = substr($DATETIME, 0, 19) . "Z";
		$REGID = $this->guidv4();
		$requestTarget = "/credit-card/v1/payment-page";
            
		$customerId = (int)$this->context->cookie->id_customer;
		$data = array(
			"customer" => array(
				"id" => $customerId,
				"name" => $NAME,
				"email" => $EMAIL,
				"phone" => $PHONE,
				"country" => "ID",
				"address" => $ADDRESS
			),
			"order" => array(
				"invoice_number" => $invoiceNumber,
				"line_items" => $itemQty,
				"amount" => $amount,
				"failed_url" => _PS_BASE_URL_ . __PS_BASE_URI__."index.php?fc=module&module=jokulcc&controller=request&task=redirectFailed",
				"callback_url" => _PS_BASE_URL_ . __PS_BASE_URI__."index.php?fc=module&module=jokulcc&controller=request&task=redirect&orderid=".$orderid."&invoiceNumber=".$invoiceNumber."&amount=".$amount,
				"auto_redirect" => true
			),
			"override_configuration" => array(
				"themes" => array(
					"language" => $LANGUAGE ,
					"background_color" => $BACKGROUND_COLOR ,
					"font_color" => $FONT_COLOR ,
					"button_background_color" => $BUTTON_BACKGROUND_COLOR ,
					"button_font_color" => $BUTTON_FONT_COLOR ,
				)
			),
			"additional_info" => array (
				"integration" => array (
					"name" => "prestashop-plugin",
					"module-name" => "jokul-credit-card",
					"version" => "1.0.0"
				)
			)
		);

		$bodyJson = json_encode($data);
		$dataBody = str_replace(array("\r", "\n"), array("\\r", "\\n"), $bodyJson);
		$digest = base64_encode(hash("sha256", $dataBody, True));

		$dataWords = "Client-Id:" . $MALL_ID . "\n" .
			"Request-Id:" . $REGID . "\n" .
			"Request-Timestamp:" . $DATETIMEFINAL . "\n" .
			"Request-Target:" . $requestTarget . "\n" .
			"Digest:" . $digest;

		$signature = base64_encode(hash_hmac('SHA256', htmlspecialchars_decode($dataWords), htmlspecialchars_decode($SHARED_KEY), True));
		
		$getUrl = parse_ini_file($URL_MERCHANTHOSTED);
		$ch = curl_init($getUrl['CC'].$requestTarget);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_FAILONERROR, true);

		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Signature:' . "HMACSHA256=" . $signature,
			'Request-Id:' . $REGID,
			'Client-Id:' . $MALL_ID,
			'Request-Timestamp:' . $DATETIMEFINAL
		));

		$GETDATARESULT = curl_exec($ch);
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error_msg = curl_error($ch);
		$myservername = Tools::getHttpHost(true) . __PS_BASE_URI__;
		$GETDATARESULT = json_decode($GETDATARESULT);

		if ($httpcode == 200) {
			$urlCC = $GETDATARESULT->credit_card_payment_page->url;
			$this->addTransactions($invoiceNumber, $amount, $orderid);
			curl_close($ch);
		} else {
			$urlCC = "";
		}

		$SMARTY_ARRAY = 	array(
			'this_path'        				=> $this->_path,
			'this_path_ssl'    				=> Configuration::get('PS_FO_PROTOCOL') . $_SERVER['HTTP_HOST'] . __PS_BASE_URI__ . "modules/{$this->name}/",
			'payment_name'     				=> Configuration::get('DOKU_CC_NAME'),
			'payment_description' 			=> Configuration::get('DOKU_CC_DESCRIPTION'),
			'URL'			   				=> $URL,
			'URLCC'			   				=> $urlCC,
			'amount'           				=> $amount,
			'PURCHASEAMOUNT'           		=> $amount,
			'LANGUAGE'           			=> $LANGUAGE,
			'BACKGROUND_COLOR'           	=> $BACKGROUND_COLOR,
			'FONT_COLOR'           			=> $FONT_COLOR,
			'BUTTON_BACKGROUND_COLOR'       => $BUTTON_BACKGROUND_COLOR,
			'BUTTON_FONT_COLOR'           	=> $BUTTON_FONT_COLOR,
			'REGID'           				=> $REGID,
			'DATETIME'           			=> $DATETIMEFINAL,
			'invoice_number'  				=> $invoiceNumber,
			'order_id'  				    => $orderid,
			'REQUESTDATETIME'  				=> $REQUEST_DATETIME,
			'CURRENCY'         				=> $CURRENCY,
			'PURCHASECURRENCY' 				=> $CURRENCY,
			'PAYMENTCHANNEL'   				=> $PAYMENT_CHANNEL,
			'HOMEPHONE'        				=> $PHONE,
			'TRANSIDMERCHANT'        		=> $invoiceNumber,
			'MOBILEPHONE'      				=> $PHONE,
			'BASKET'           				=> $basket,
			'ADDRESS'          				=> $ADDRESS,
			'CITY'             				=> $CITY,
			'STATE'            				=> $STATE,
			'ZIPCODE'          				=> $ZIPCODE,
			'SHIPPING_ZIPCODE' 				=> $ZIPCODE,
			'SHIPPING_CITY'    				=> $CITY,
			'SHIPPING_ADDRESS' 				=> $ADDRESS,
			'NAME' 						    => $NAME,
			'EMAIL' 					    => $EMAIL,
			'SHIPPING_COUNTRY' 				=> 'ID',
			'URL_MERCHANTHOSTED'			=> $URL_MERCHANTHOSTED,
			'PAYMENT_CHANNELS_CC'    		=> Tools::safeOutput(Configuration::get('PAYMENT_CHANNELS_CC'))
		);

		$smarty->assign($SMARTY_ARRAY);
	}

	function addTransactions ($invoiceNumber, $amount, $orderId) {
		$trx['ip_address']          			= $this->getipaddress();;
		$trx['process_type']        			= "PAYMENT_PENDING";
		$trx['process_datetime']    			= gmdate("Y-m-d H:i:s");
		$trx['order_id']     					= $orderId;
		$trx['invoice_number'] 					= $invoiceNumber;
		$trx['amount']              			= $amount;
		$trx['message']             			= "Jokul Credit Card";

		$order_id = $this->get_cart_id($orderId);
		if (!$order_id) {
			$this->add_jokulcc($trx);
		} else {
			$this->update_jokulcc($invoiceNumber, $orderId);
		}
	}

	function addDOKUOrderStatus()
	{
		$stateConfig = array();
		try {
			$stateConfig['color'] = '#00ff00';
			$this->addOrderStatus(
				'DOKU_CC_PAYMENT_RECEIVED',
				'Credit Card Payment Received',
				$stateConfig,
				false,
				''
			);
			$stateConfig['color'] = 'blue';
			$this->addOrderStatus(
				'DOKU_CC_AWAITING_PAYMENT',
				'Credit Card Awaiting for Payment',
				$stateConfig,
				true,
				'doku_payment_code'
			);
			$this->addOrderStatus(
				'DOKU_CC_FAILED_PAYMENT',
				'Credit Card Payment Failed',
				$stateConfig,
				false,
				''
			);
			return true;
		} catch (Exception $exception) {
			return false;
		}
	}

	function addOrderStatus($configKey, $statusName, $stateConfig, $send_email, $template)
	{
		if (!Configuration::get($configKey)) {
			$orderState = new OrderState();
			$orderState->name = array();
			$orderState->module_name = $this->name;
			$orderState->send_email = $send_email;
			$orderState->color = $stateConfig['color'];
			$orderState->hidden = false;
			$orderState->delivery = false;
			$orderState->logable = true;
			$orderState->invoice = false;
			$orderState->paid = false;

			foreach (Language::getLanguages() as $language) {
				$orderState->template[$language['id_lang']] = $template;
				$orderState->name[$language['id_lang']] = $statusName;
			}

			if ($orderState->add()) {
				$dokuIcon = dirname(__FILE__) . '/logo.png';
				$newStateIcon = dirname(__FILE__) . '/../../img/os/' . (int) $orderState->id . '.gif';
				copy($dokuIcon, $newStateIcon);
			}

			$order_state_id = (int) $orderState->id;
		} else {
			$order_state_id = Tools::safeOutput(Configuration::get($configKey));
		}

		Configuration::updateValue($configKey, $order_state_id);
	}

	function copyEmailFiles()
	{
		$folderSource = dirname(__FILE__) . '/mail';
		$folderDestination = _PS_ROOT_DIR_ . '/mails/en';

		$files = glob($folderSource . "/*.*");

		foreach ($files as $file) {
			$file_to_go = str_replace($folderSource, $folderDestination, $file);
			copy($file, $file_to_go);
		}
	}

	function deleteOrderState($id_order_state)
	{
		$orderState = new OrderState($id_order_state);
		$orderState->delete();
	}

	function getipaddress()
	{
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		return $ip;
	}

	function checkTrx($trx, $process = 'REQUEST', $result_msg = '')
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;

		if ($result_msg == "PENDING") return 0;

		$check_result_msg = "";
		if (!empty($result_msg)) {
			$check_result_msg = " AND result_msg = '$result_msg'";
		}

		$db->Execute("SELECT * FROM " . $db_prefix . "jokulcc" .
			" WHERE " .
			"invoice_number = '" . $trx['invoice_number'] . "'" .
			" AND payment_code = '" . $trx['payment_code'] . "'" .
			" AND amount = '" . $trx['amount'] . "'");

		return $db->numRows();
	}

	function checkTrxNotify($trx)
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;

		$db->Execute("SELECT * FROM " . $db_prefix . "jokulcc" .
			" WHERE " .
			"invoice_number  = '" . $trx['invoice_number'] . "'" .
			" AND process_type = 'PAYMENT_PENDING'" .
			" AND amount = '" . $trx['amount'] . "'");

		return $db->numRows();
	}

	function add_jokulcc($datainsert)
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;

		$SQL = "";

		foreach ($datainsert as $field_name => $field_data) {
			$SQL .= " $field_name = '$field_data',";
		}
		$SQL = substr($SQL, 0, -1);

		$response = $db->Execute("INSERT INTO " . $db_prefix . "jokulcc SET $SQL");
	}

	function emptybag()
	{
		$products = $this->context->cart->getProducts();
		foreach ($products as $product) {
			$this->context->cart->deleteProduct($product["id_product"]);
		}
	}

	function get_order_id($cart_id)
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;
		$SQL       = "SELECT id_order FROM " . $db_prefix . "orders WHERE id_cart = $cart_id";

		return $db->getValue($SQL);
	}

	function get_order_id_jokul($invoiceNumber)
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;
		$SQL       = "SELECT order_id FROM " . $db_prefix . "jokulcc where invoice_number ='" . $invoiceNumber . "'";

		return $db->getValue($SQL);
	}

	function get_cart_id($cartId)
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;
		$SQL       = "SELECT order_id FROM " . $db_prefix . "jokulcc where order_id ='" . $cartId . "'";

		return $db->getValue($SQL);
	}

	function get_request_notif($cartId)
	{
		$db = Db::getInstance();

		$db_prefix = _DB_PREFIX_;
		$SQL       = "SELECT notif FROM " . $db_prefix . "jokulcc where order_id ='" . $cartId . "'";

		return $db->getValue($SQL);
	}

	function update_jokulcc($invoiceNumber, $cartId)
	{
		$db = Db::getInstance();
		$db_prefix = _DB_PREFIX_;

		$query = "UPDATE " . $db_prefix . "jokulcc SET invoice_number = '" . $invoiceNumber . "' where order_id ='" . $cartId . "'";
		$db->Execute($query);
	}

	function update_notify($cartId, $rawPost, $dateTime, $notif, $state)
	{
		$db = Db::getInstance();
		$db_prefix = _DB_PREFIX_;
		if ($notif == '0') {
			if ($rawPost != '') {
				$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', raw_post_data ='".$rawPost."' , process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
			} else {
				$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
			}
		} else {
			if ($state == 1) {
				if ($rawPost != '') {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', raw_post_data ='".$rawPost."' , process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
				} else {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
				}
			} else {
				if ($rawPost != '') {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'PAYMENT_PENDING', raw_post_data ='".$rawPost."' , process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
				} else {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'PAYMENT_PENDING', process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
				}
			}
		}
		$db->Execute($query);
	}

	function set_order_status($order_id, $state, $emaildata = array())
	{
		$objOrder = new Order($order_id);
		$history = new OrderHistory();
		$history->id_order = (int) $objOrder->id;
		$history->changeIdOrderState((int) $state, (int) ($objOrder->id));
		$history->addWithemail(true, $emaildata);
		$history->save();
	}

	function getServerConfig()
	{
		$server_cc_dest = Tools::safeOutput(Configuration::get('SERVER_CC_DEST'));

		if (empty($server_cc_dest) || intval($server_cc_dest) == 0) {
			$MALL_ID    = Tools::safeOutput(Configuration::get('MALL_CC_ID_DEV'));
			$SHARED_KEY = Tools::safeOutput(Configuration::get('SHARED_CC_KEY_DEV'));
			$URL_CHECK  = "";
		} else {
			$MALL_ID    = Tools::safeOutput(Configuration::get('MALL_CC_ID_PROD'));
			$SHARED_KEY = Tools::safeOutput(Configuration::get('SHARED_CC_KEY_PROD'));
			$URL_CHECK  = "";
		}

		$USE_IDENTIFY = Tools::safeOutput(Configuration::get('USE_IDENTIFY'));

		$DOKU_INITIALIZE_PAYMENT = Tools::safeOutput(Configuration::get('DOKU_CC_FAILED_PAYMENT'));
		$DOKU_AWAITING_PAYMENT = Tools::safeOutput(Configuration::get('DOKU_CC_AWAITING_PAYMENT'));
		$DOKU_PAYMENT_RECEIVED = Tools::safeOutput(Configuration::get('DOKU_CC_PAYMENT_RECEIVED'));

		$config = array(
			"MALL_ID" => $MALL_ID,
			"SHARED_KEY" => $SHARED_KEY,
			"USE_IDENTIFY" => $USE_IDENTIFY,
			"URL_CHECK" => $URL_CHECK,
			"DOKU_CC_FAILED_PAYMENT" => $DOKU_INITIALIZE_PAYMENT,
			"DOKU_CC_AWAITING_PAYMENT" => $DOKU_AWAITING_PAYMENT,
			"DOKU_CC_PAYMENT_RECEIVED" => $DOKU_PAYMENT_RECEIVED
		);

		return $config;
	}

	//ADMIN -- GET DATA FROM CONFIGURATION FORM
	public function getConfigFieldsValues()
	{
		return array(
			'doku_cc_name' 						=> Tools::safeOutput(Tools::getValue('DOKU_CC_NAME', Configuration::get('DOKU_CC_NAME'))),
			'doku_cc_description' 				=> Tools::safeOutput(Tools::getValue('DOKU_CC_DESCRIPTION', Configuration::get('DOKU_CC_DESCRIPTION'))),
			'mall_cc_id_dev'					=> Tools::safeOutput(Tools::getValue('MALL_CC_ID_DEV', Configuration::get('MALL_CC_ID_DEV'))),
			'mall_cc_id_prod' 					=> Tools::safeOutput(Tools::getValue('MALL_CC_ID_PROD', Configuration::get('MALL_CC_ID_PROD'))),
			'shared_cc_key_dev'					=> Tools::safeOutput(Tools::getValue('SHARED_CC_KEY_DEV', Configuration::get('SHARED_CC_KEY_DEV'))),
			'shared_cc_key_prod'				=> Tools::safeOutput(Tools::getValue('SHARED_CC_KEY_PROD', Configuration::get('SHARED_CC_KEY_PROD'))),
			'payment_channels'					=> Tools::safeOutput(Tools::getValue('PAYMENT_CHANNELS', Configuration::get('PAYMENT_CHANNELS'))),
			'payment_channels_CC'			    => Tools::safeOutput(Tools::getValue('PAYMENT_CHANNELS_CC', Configuration::get('PAYMENT_CHANNELS_CC'))),
			'lang'								=> Tools::safeOutput(Tools::getValue('LANGUAGE', Configuration::get('LANGUAGE'))),
			'background_color'					=> Tools::safeOutput(Tools::getValue('BACKGROUND_COLOR', Configuration::get('BACKGROUND_COLOR'))),
			'font_color'						=> Tools::safeOutput(Tools::getValue('FONT_COLOR', Configuration::get('FONT_COLOR'))),
			'buttton_background_color'			=> Tools::safeOutput(Tools::getValue('BUTTON_BACKGROUND_COLOR', Configuration::get('BUTTON_BACKGROUND_COLOR'))),
			'buttton_font_color'				=> Tools::safeOutput(Tools::getValue('BUTTON_FONT_COLOR', Configuration::get('BUTTON_FONT_COLOR'))),
			'server_cc_dest'						=> Tools::safeOutput(Tools::getValue('SERVER_CC_DEST', 	Configuration::get('SERVER_CC_DEST'))),
			'notification_url' 					=> _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/jokulcc/request.php?task=notify'
		);
	}

	private function _displayBanner()
	{
		return $this->display(__FILE__, 'infos.tpl');
	}

	function getKey()
	{
		if (Configuration::get('SERVER_CC_DEST') == 0) {
			return Configuration::get('SHARED_CC_KEY_DEV');
		} else {
			return Configuration::get('SHARED_CC_KEY_PROD');
		}
	}

	function guidv4($data = null)
	{
		$data = $data ?? random_bytes(16);

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
