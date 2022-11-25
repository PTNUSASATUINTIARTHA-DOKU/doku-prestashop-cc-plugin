<?php

/*
    Plugin Name : Prestashop DOKU Jokul Credit Card Payment Gateway
    Plugin URI  : http://www.doku.com
    Description : DOKU Jokul Credit Card Payment Gateway for Prestashop 1.7
    Version     : 1.0.2
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
		$this->version          = '1.0.2';
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
			Configuration::deleteByName('set_sub_account_sub_account_cc');
			Configuration::deleteByName('set_sub_account_data_cc');
			Configuration::deleteByName('BUTTON_BACKGROUND_COLOR');
			Configuration::deleteByName('BUTTON_FONT_COLOR');
			Configuration::deleteByName('PAYMENT_CHANNEL');
			Configuration::deleteByName('PAYMENT_CODE');
			Configuration::deleteByName('PAYMENT_CHANNELS');
			Configuration::deleteByName('PAYMENT_CHANNELS_CC');
			Configuration::deleteByName('LIST_BANK_CC');

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
			`data_notif` TEXT COLLATE utf8_unicode_ci,  
			`notif` VARCHAR(2) COLLATE utf8_unicode_ci DEFAULT '0'
		)";

		$db->Execute($query);
	}

	private function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit')) {
			if (!Tools::getValue('mall_cc_id_dev'))
				$this->$_postErrors[] = $this->l('Client ID is required.');
			if (!Tools::getValue('shared_cc_key_dev'))
				$this->$_postErrors[] = $this->l('Secret Key is required.');
			if (!Tools::getValue('mall_cc_id_prod'))
				$this->$_postErrors[] = $this->l('Client ID is required.');
			if (!Tools::getValue('shared_cc_key_prod'))
				$this->$_postErrors[] = $this->l('Secret Key is required.');

			if (Tools::getValue('set_sub_account_sub_account_cc')){
				if (!Tools::getValue('set_sub_account_data_cc'))
				$this->$_postErrors[] = $this->l('Sub Account is required.');
			}
		} else if (Tools::isSubmit('btnSubmitAdd')) {
			if (!Tools::getValue('bank_account_add_config'))
				$this->$_postErrors[] = $this->l('Bank Account is required.');
			if (!Tools::getValue('value_account_add_config'))
				$this->$_postErrors[] = $this->l('Value must more than 0');


			$listContent = array();
			$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
			$trimspace = preg_replace('/\s+/', '', $listContent);
			$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);

			foreach($listDataBank as &$a){
				if ($a['bank_account_settlement_id'] == Tools::getValue('bank_account_add_config')) {
					$this->$_postErrors[] = $this->l('Bank account Already exist');
				}
			}
		} else if (Tools::isSubmit('btnSubmitUpdate')) {
			if (!Tools::getValue('bank_account_config'))
				$this->$_postErrors[] = $this->l('Bank Account is required.');
			if (Tools::getValue('value_account_config') < 1)
				$this->$_postErrors[] = $this->l('Value must more than 0');
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
			Configuration::updateValue('set_sub_account_sub_account_cc', 		trim(Tools::getValue('set_sub_account_sub_account_cc')));
			Configuration::updateValue('set_sub_account_data_cc', 				trim(Tools::getValue('set_sub_account_data_cc')));
		} else if (Tools::isSubmit('btnSubmitUpdate')) {
			$listContent = array();
			$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
			$trimspace = preg_replace('/\s+/', '', $listContent);
			$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);

			$bankIdConfig = Tools::safeOutput(Tools::getValue('ID_BANK_VA', Configuration::get('ID_BANK_VA')));
			foreach($listDataBank as &$a){
				if($a['bank_id'] == $bankIdConfig){
					$a['value'] = Tools::getValue('value_account_config');
					$a['bank_account_settlement_id'] = Tools::getValue('bank_account_config');
					$a['type'] = Tools::getValue('type_account_config');
				}
			}
			Configuration::updateValue('LIST_BANK_CC', json_encode($listDataBank));
		} else if (Tools::isSubmit('btnSubmitAdd')) {
			$listContent = array();
			$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
			$trimspace = preg_replace('/\s+/', '', $listContent);
			$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);

			$maxId = 0;
			foreach($listDataBank as &$a){
				if ($a['bank_id'] > $maxId) {
					$maxId = $a['bank_id'];
				}
			}

			$listContentAdd[] = array(
				'bank_id'							=> $maxId+1,
				'bank_account_settlement_id' 		=> Tools::getValue('bank_account_add_config'),
				'type' 								=> Tools::getValue('type_account_add_config'),
				'value'								=> Tools::getValue('value_account_add_config')
			);

			$listContentAddItem = array(
				'bank_id'							=> $maxId+1,
				'bank_account_settlement_id' 		=> Tools::getValue('bank_account_add_config'),
				'type' 								=> Tools::getValue('type_account_add_config'),
				'value'								=> Tools::getValue('value_account_add_config')
			);
			if (count($listDataBank) > 0) {
				array_push( $listDataBank, $listContentAddItem);
				Configuration::updateValue('LIST_BANK_CC', json_encode($listDataBank));
			} else {
				Configuration::updateValue('LIST_BANK_CC', json_encode($listContentAdd));
			}
		}
		Tools::redirectAdmin(
			$currentIndex.'index.php?tab=AdminModules&configure=' . $this->name . '&token='.Tools::getAdminTokenLite('AdminModules')
		);
	}

	public function getContent()
	{
		$updateBank = "updatelistBank";
		$deletebank = "deletelistBank";
		$addbank ="addlistBank";
		$full_url = $_SERVER['REQUEST_URI'];

		if(strpos($full_url, $updateBank) !== false) {
			$listContent = array();
			$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
			$trimspace = preg_replace('/\s+/', '', $listContent);
			$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);

			$parts = parse_url($full_url);
			parse_str($parts['query'], $query);
			$bankId = $query['bank_id']-1;

			Configuration::updateValue('ID_BANK_VA', json_encode($bankId+1));

			$this->_html .= '<br />';
			$this->_html .= $this->_displayBanner();
			$this->_html .= $this->renderUpdateBankForm($listDataBank[$bankId]);
		} else if(strpos($full_url, $deletebank) !== false) {
			$parts = parse_url($full_url);
			parse_str($parts['query'], $query);
			$bankId = $query['bank_id']-1;

			$listContent = array();
			$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
			$trimspace = preg_replace('/\s+/', '', $listContent);
			$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);
			unset($listDataBank[$bankId]);
			Configuration::updateValue('LIST_BANK_CC', json_encode($listDataBank));

			Tools::redirectAdmin(
				$currentIndex.'index.php?tab=AdminModules&configure=' . $this->name . '&token='.Tools::getAdminTokenLite('AdminModules')
			);
		} else if(strpos($full_url, $addbank) !== false) {
			$listContent = array();
			$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
			$trimspace = preg_replace('/\s+/', '', $listContent);
			$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);

			$this->_html .= '<br />';
			$this->_html .= $this->_displayBanner();
			$this->_html .= $this->renderAddBankForm();
		} else {
			$this->renderFormContent();
		}

		return $this->_html;
	}

	function renderFormContent() {
		if (Tools::isSubmit('btnSubmit')) {
			$this->_postValidation();
			if (!sizeof($this->$_postErrors)) {
				$this->_postProcess();
			} else {
				foreach ($this->$_postErrors as $err) {
					$this->_html .= '<div style="color:red" class="alert error">' . $err .'</div>';
				}
			}
			$this->_html .= '<br />';
			$this->_html .= $this->_displayBanner();
			$this->_html .= $this->renderForm();
		} else if (Tools::isSubmit('btnSubmitUpdate')) {
			$this->_postValidation();
			if (!sizeof($this->$_postErrors)) {
				$this->_postProcess();
			} else {
				foreach ($this->$_postErrors as $err) {
					$this->_html .= '<div style="color:red" class="alert error">' . $err . '</div>';
				}
			}
			$this->_html .= '<br />';
			$this->_html .= $this->_displayBanner();
			$this->_html .= $this->renderUpdateBankForm(null);
		} else if (Tools::isSubmit('btnSubmitAdd')) {
			$this->_postValidation();
			if (!sizeof($this->$_postErrors)) {
				$this->_postProcess();
			} else {
				foreach ($this->$_postErrors as $err) {
					$this->_html .= '<div style="color:red" class="alert error">' . $err . '</div>';
				}
			}
			$this->_html .= '<br />';
			$this->_html .= $this->_displayBanner();
			$this->_html .= $this->renderAddBankForm();
		} else {
			$this->_html .= '<br />';
			$this->_html .= $this->_displayBanner();
			$this->_html .= $this->renderForm();
		}
	}

	function getBankFormValues($listDataBank) {
		return array(
			'id_account_config' => $listDataBank['bank_id'],
			'bank_account_config' => $listDataBank['bank_account_settlement_id'],
			'type_account_config' => $listDataBank['type'],
			'value_account_config' => $listDataBank['value']
		);
	}

	public function renderAddBankForm() {
		$type_bank = [
			[
				'id_option' => 'PERCENTAGE',
				'name' 		=> 'PERCENTAGE',
			],

			[
				'id_option' => 'FIX',
				'name' 		=> 'FIX',
			],
		];

		$fields_form_update_bank = array(
			'form' => array(
				'legend' => [
					'title' => $this->l('Jokul - Bank Account Configuration'),
					'icon' => 'icon-cogs'
				],
				'input' => 	[

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Bank Account'),
						'name'  => 'bank_account_add_config',
						'hint'  => [
							$this->l('Bank Account')
						],
					],

					[
						'type'  => 'select',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Type'),
						'name'  => 'type_account_add_config',
						'hint'  => [
							$this->l('Type Account')
						],
						'options' 	=> [
							'query' => $type_bank,
							'id' 	=> 'id_option',
							'name' 	=> 'name'
						]
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Value'),
						'name'  => 'value_account_add_config',
						'hint'  => [
							$this->l('Value')
						],
					],
				],
				'submit' => array(
					'title' => $this->l('Save'),
					'type' => 'submit'
				),
				'buttons' => array(
					array(
						'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
						'title' => $this->l('Cancel'),
						'icon' => 'process-icon-cancel'
					)
				)
			),
		);

		$helper 				= new HelperForm();
		$helper->show_toolbar 	= false;
		$helper->table 			= $this->table;
		$lang 					= new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language 		= $lang->id;
		$helper->allow_employee_form_lang 	= Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form 		= array();
		$helper->id 			= (int)Tools::getValue('id_carrier');
		$helper->identifier 	= $this->identifier;
		$helper->submit_action 	= 'btnSubmitAdd';
		$helper->currentIndex 	= $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

		$helper->token 		= Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars 	= array(
			'languages' 	=> $this->context->controller->getLanguages(),
			'id_language' 	=> $this->context->language->id
		);

		$helperReturn = $helper->generateForm(array($fields_form_update_bank));
		$return = $helperReturn;
		
		return $return;
	}

	public function renderUpdateBankForm($listDataBank) {
		$type_bank = [
			[
				'id_option' => 'PERCENTAGE',
				'name' 		=> 'PERCENTAGE',
			],

			[
				'id_option' => 'FIX',
				'name' 		=> 'FIX',
			],
		];

		$fields_form_update_bank = array(
			'form' => array(
				'legend' => [
					'title' => $this->l('Jokul - Bank Account Configuration'),
					'icon' => 'icon-cogs'
				],
				'input' => 	[
					[
						'type'  => 'hidden',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Bank ID'),
						'name'  => 'id_account_config',
						'hint'  => [
							$this->l('Bank ID')
						],
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Bank Account'),
						'name'  => 'bank_account_config',
						'hint'  => [
							$this->l('Bank Account')
						],
					],

					[
						'type'  => 'select',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Type'),
						'name'  => 'type_account_config',
						'hint'  => [
							$this->l('Type Account')
						],
						'options' 	=> [
							'query' => $type_bank,
							'id' 	=> 'id_option',
							'name' 	=> 'name'
						]
					],

					[
						'type'  => 'text',
						'label' => '<span style="color:red"><b>*</b></span> ' . $this->l('Value'),
						'name'  => 'value_account_config',
						'hint'  => [
							$this->l('Value')
						],
					],
				],
				'submit' => array(
					'title' => $this->l('Save'),
					'type' => 'submit'
				),
				'buttons' => array(
					array(
						'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
						'title' => $this->l('Cancel'),
						'icon' => 'process-icon-cancel'
					)
				)
			),
		);

		$helper 				= new HelperForm();
		$helper->show_toolbar 	= false;
		$helper->table 			= $this->table;
		$lang 					= new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language 		= $lang->id;
		$helper->allow_employee_form_lang 	= Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form 		= array();
		$helper->id 			= (int)Tools::getValue('id_carrier');
		$helper->identifier 	= $this->identifier;
		$helper->submit_action 	= 'btnSubmitUpdate';
		$helper->currentIndex 	= $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;

		$helper->token 		= Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars 	= array(
			'fields_value' 	=> $this->getBankFormValues($listDataBank),
			'languages' 	=> $this->context->controller->getLanguages(),
			'id_language' 	=> $this->context->language->id
		);

		$helperReturn = $helper->generateForm(array($fields_form_update_bank));
		$return = $helperReturn;
		
		return $return;
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
				'id_option' => 0,
				'name' 		=> 'Sandbox',
			],

			[
				'id_option' => 1,
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

		$payment_sub_account = [
			[
				'id_option_sub_account'	 => 'sub_account_cc',
				'name_sub_account' 		 => 'Enable Your Sub Account in Prestashop',
			]
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

					[
						'type' 		=> 'checkbox',
						'label' 	=> $this->l('Enabling Sub Account :'),
						'name' 		=> 'set_sub_account',
						'multiple' 	=> true,

						'hint' 		=> [
							$this->l('Choose the payment channels that you can offer to the customers. The payment channels will be presented to the customer on the checkout page.')
						],

						'values' 	=> [
							'query'  => $payment_sub_account,
							'id' 	 => 'id_option_sub_account',
							'name' 	 => 'name_sub_account'
						]

					],

					[
						'type'  => 'text',
						'label' => '<label id ="label_set_sub_account_data"><span style="color:red">On Behalf Of: *</span></label>',
						'name'  => 'set_sub_account_data_cc',
						'desc' => '<label id ="label_desc_sub_account_data">Route to yout Jokul Sub Account ID. All transactions will be linked to this account</label>'
					],
				],
				'submit' => [
					'title' => $this->l('Save'),
				]
			]
		];

		?>
		<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script> 
        <script type="text/javascript">
            jQuery(document).ready(function($) {
				checkbox_sac_select();
                $('#set_sub_account_sub_account_cc').click(function() {
                    checkbox_sac_select();
                })

				function checkbox_sac_select() {
                    if($('#set_sub_account_sub_account_cc').is(':checked')) {
                        $('#set_sub_account_data_cc').fadeIn();
						$('#label_set_sub_account_data').fadeIn();
						$('#label_desc_sub_account_data').fadeIn();
                        $('#set_sub_account_sub_account_cc').prop('required',true);
                    } else {
						$('#set_sub_account_data_cc').val("");
						$('#set_sub_account_data_cc').fadeOut();
						$('#label_set_sub_account_data').fadeOut();
						$('#label_desc_sub_account_data').fadeOut();
                        $('#set_sub_account_sub_account_cc').prop('required',false);
                    }
                }; 
            })
        </script>
        <?php

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

		$helperReturn = $helper->generateForm(array($fields_form));
		$return = $helperReturn;
		$return .= $this->renderAdditionalOptionsList();
		
		return $return;
	}

	protected function renderAdditionalOptionsList() {
		$this->fields_list = array(
			'bank_id' => array(
				'title' => $this->l('No'),
				'orderby' => false,
				'class' => 'hidden',
				'type' => 'text',
				'visible'=>false,
				'search' => false,
			),
			'bank_account_settlement_id' => array(
				'width' => 'auto',
				'orderby' => false,
				'title' => $this->l('Bank Account'),
				'type' => 'text',
				'search' => false,
			),
			'type' => array(
				'type' => 'text',
				'title' => $this->l('Type'),
				'search' => false,
				'orderby' => false,
			),
			'value' => array(
				'title' => $this->l('Value'),
				'type' => 'text',
				'search' => false,
				'orderby' => false,
			)
		);

		$listContent = array();
		$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
		$trimspace = preg_replace('/\s+/', '', $listContent);
		$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);
		
		$helperList = new HelperList();
		$helperList->shopLinkType = '';
		$helperList->simple_header = false;
		$helperList->actions = array('edit', 'delete');
		$helperList->show_toolbar = false;
		$helperList->identifier = 'bank_id';
		$helperList->table = 'listBank';

		$helperList->toolbar_btn['new'] =  array(
			'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&add' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules').'&'.'addlistBank',
			'desc' => $this->l('Add')
		);

		$helperList->title = "List Bank Account";
		$helperList->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
		$helperList->token = Tools::getAdminTokenLite('AdminModules');

		$helperList->listTotal = count($listDataBank);
		return $helperList->generateList($listDataBank, $this->fields_list);
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
		$PHONE               			= trim($address->phone);
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

		$listContent = array();
		$listContent = Tools::safeOutput(Tools::getValue('LIST_BANK_CC', Configuration::get('LIST_BANK_CC')));
		$trimspace = preg_replace('/\s+/', '', $listContent);
		$listDataBank = json_decode(htmlspecialchars_decode($trimspace), true);
		$sub_account_data = Tools::safeOutput(Tools::getValue('set_sub_account_data_cc', Configuration::get('set_sub_account_data_cc')));

		if (count($listDataBank) > 0) {

    		foreach($listDataBank as $key => $item){
        		unset($listDataBank[$key]["bank_id"]);
    		}

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
				"additional_info" => $sub_account_data !== '' ? array(
					"integration" => array (
						"name" => "prestashop-plugin",
						"module-name" => "jokul-credit-card",
						"version" => "1.0.2"
					),
					"account" => array(
						"id" =>  $sub_account_data
					),
					"settlement"=> $listDataBank
				): array(
					"integration" => array (
						"name" => "prestashop-plugin",
						"module-name" => "jokul-credit-card",
						"version" => "1.0.2"
					),
					"settlement"=> $listDataBank
				)
			);
		} else {
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
				"additional_info" => $sub_account_data !== '' ? array(
					"integration" => array (
						"name" => "prestashop-plugin",
						"module-name" => "jokul-credit-card",
						"version" => "1.0.2"
					),
					"account" => array(
						"id" =>  $sub_account_data
					)
				): array(
					"integration" => array (
						"name" => "prestashop-plugin",
						"module-name" => "jokul-credit-card",
						"version" => "1.0.2"
					)
				)
			);
		}

		$bodyJson = json_encode($data);
		$this->doku_log($this, " CREDIT CARD REQUEST ".$bodyJson, $invoiceNumber, '');

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
		$this->doku_log($this, " URL WALLET TOKEN ".$getUrl['CC'].$requestTarget, $invoiceNumber, '');
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
			$this->doku_log($this, " CREDIT CARD RESPONSE ".json_encode($GETDATARESULT), $invoiceNumber, '');
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

		$dataTransactions = array();
		$dataTransactions = array('ip address' => $trx['ip_address'], 'process type' => $trx['process_type'], 'process datetime' => $trx['process_datetime'], 'order id' => $trx['order_id'], 'invoice number' => $trx['invoice_number'], 'amount' => $trx['amount'], 'message' => $trx['message']);
		$this->doku_log($this, " PARAMS WALLET TOKEN ADD DB ".json_encode($dataTransactions), $invoiceNumber, '');
		$trx['raw_post_data'] = json_encode($dataTransactions);
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
			if ($rawPost != '' or $rawPost != null) {
				$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', data_notif ='".$rawPost."' , process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
			} else {
				$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
			}
		} else {
			if ($state == 1) {
				if ($rawPost != '' or $rawPost != null) {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', data_notif ='".$rawPost."' , process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
				} else {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'NOTIFY', process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
				}
			} else {
				if ($rawPost != '' or $rawPost != null) {
					$query = "UPDATE " . $db_prefix . "jokulcc SET process_type  = 'PAYMENT_PENDING', data_notif ='".$rawPost."' , process_datetime  ='".$dateTime."' , notif  ='".$notif."' where order_id ='" . $cartId . "'";
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
			'server_cc_dest'					=> Tools::safeOutput(Tools::getValue('SERVER_CC_DEST', 	Configuration::get('SERVER_CC_DEST'))),
			'set_sub_account_sub_account_cc'	=> Tools::safeOutput(Tools::getValue('set_sub_account_sub_account_cc', Configuration::get('set_sub_account_sub_account_cc'))),
			'set_sub_account_data_cc'			=> Tools::safeOutput(Tools::getValue('set_sub_account_data_cc', Configuration::get('set_sub_account_data_cc'))),
			'notification_url' 					=> Tools::getHttpHost(true). __PS_BASE_URI__ . 'modules/jokulcc/request.php?task=notify'
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

	function doku_log($class, $log_msg, $invoiceNumber = "", $path)
    {
        $log_filename = "doku_log";
        $log_header = date(DATE_ATOM, time()) . ' ' . get_class($class) . '---> ' . $invoiceNumber;
        if (!file_exists($path.$log_filename)) {
            mkdir($path.$log_filename, 0777, true);
        }
        $log_file_data = $path.$log_filename . '/log_' . date('d-M-Y') . '.log';
        file_put_contents($log_file_data, $log_header . $log_msg . "\n", FILE_APPEND);
    }

	function guidv4($data = null)
	{
		$data = $data ?? random_bytes(16);

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
