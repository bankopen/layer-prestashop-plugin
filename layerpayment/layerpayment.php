<?php 	
error_reporting(E_ALL);	

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
	exit;
}

class layerpayment extends PaymentModule
{
	private $_html = '';
	private $_postErrors = array();

	private $_title;
	
	private $layerpayment_sandbox;
	private $layerpayment_accesskey;
	private $layerpayment_secretkey;
	
	const BASE_URL_SANDBOX = "https://sandbox-icp-api.bankopen.co/api";
    const BASE_URL_UAT = "https://icp-api.bankopen.co/api";
	
	function __construct()
	{		
		$this->_title =	'Layer Payment';
		$this->name = 'layerpayment';		
		$this->tab = 'payments_gateways';		
		$this->version = 1.7;
		$this->author = 'Open';
		
		$this->bootstrap = true;			
		parent::__construct();		
			
		$this->displayName = $this->trans('Layer Payment Gateway',array(),'Modules.Layerpayment.Admin');
		$this->description = $this->trans('Configure Layer Payment Gateway Parameters',array(),'Modules.Layerpayment.Admin');
		$this->confirmUninstall = $this->trans('Are you sure you want to delete these details?', array(), 'Modules.Layerpayment.Admin');
		$this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
		
		$this->layerpayment_sandbox = Configuration::get('LAYERPAYMENT_SANDBOX');
		$this->layerpayment_accesskey = Configuration::get('LAYERPAYMENT_ACCESSKEY');
		$this->layerpayment_secretkey = Configuration::get('LAYERPAYMENT_SECRETKEY');
				
		$this->page = basename(__FILE__, '.php');		
					
	}	
	
	
	public function install()
	{
		Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_state` ( `invoice`, `send_email`, `color`, `unremovable`, `logable`, `delivery`, `module_name`)	VALUES	(0, 0, \'#33FF99\', 0, 1, 0, \'layerpayment\');');
		$id_order_state = (int) Db::getInstance()->Insert_ID();
		Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_state_lang` (`id_order_state`, `id_lang`, `name`, `template`) VALUES ('.$id_order_state.', 1, \'Payment accepted\', \'payment\')');
		Configuration::updateValue('LAYERPAYMENT_ID_ORDER_SUCCESS', $id_order_state);			
		unset($id_order_state);
				
		Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_state`( `invoice`, `send_email`, `color`, `unremovable`, `logable`, `delivery`, `module_name`) VALUES (0, 0, \'#ff3355\', 0, 1, 0, \'layerpayment\');');
		$id_order_state = (int) Db::getInstance()->Insert_ID();
		Db::getInstance()->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_state_lang` (`id_order_state`, `id_lang`, `name`, `template`) VALUES ('.$id_order_state.', 1, \'Payment Failed\', \'payment\')');
		Configuration::updateValue('LAYERPAYMENT_ID_ORDER_FAILED', $id_order_state);		
		unset($id_order_state);
		
		return parent::install() && $this->registerHook('header') && $this->registerHook('paymentOptions');	
	
	}

	public function uninstall()
	{
		
		Db::getInstance()->Execute('DELETE FROM `' . _DB_PREFIX_ . 'order_state_lang` WHERE id_order_state = '.Configuration::get('LAYERPAYMENT_ID_ORDER_SUCCESS').' and id_lang = 1' );
		Db::getInstance()->Execute('DELETE FROM `' . _DB_PREFIX_ . 'order_state_lang`  WHERE id_order_state = '.Configuration::get('LAYERPAYMENT_ID_ORDER_FAILED').' and id_lang = 1');
	
		return Configuration::deleteByName('LAYERPAYMENT_SANDBOX')
			&& Configuration::deleteByName('LAYERPAYMENT_ACCESSKEY') 
			&& Configuration::deleteByName('LAYERPAYMENT_SECRETKEY') 			
			&& parent::uninstall();
	}

	public function hookHeader()
    {
		if (!$this->active) {
			return;
		}
		
        if (Tools::getValue('controller') == "order")
        {
			if($this->layerpayment_sandbox=='yes') {
				$remotescript = 'https://sandbox-payments.open.money/layer';
			}
			else
			{
				$remotescript = 'https://payments.open.money/layer';			
			}
            
			$this->context->controller->registerJavascript(
               'l1',$remotescript,
               ['server' => 'remote', 'position' => 'head', 'priority' => 20]
            );

            $this->context->controller->registerJavascript(
                'l2',
                'modules/' . $this->name . '/script.js',
                ['position' => 'bottom', 'priority' => 30]
            );
			
			$orderId = $this->context->cart->id;			
			$id_currency = intval(Configuration::get('PS_CURRENCY_DEFAULT'));
			$currency = new Currency(intval($id_currency));
			$currency_code =$currency->iso_code;
			$orderAmount =number_format(Tools::convertPrice($this->context->cart->getOrderTotal(),$currency), 2, '.', '');
			$address = new Address($this->context->cart->id_address_invoice);
			$customer = new Customer($this->context->cart->id_customer);
			
			$layer_payment_token_data = $this->create_payment_token([
                'amount' => $orderAmount,
                'currency' => $currency_code,
                'name'  => $address->firstname.' '.$address->lastname,
                'email_id' => $customer->email,
                'contact_number' => $address->phone
            ]);
		
			$error="";
			$payment_token_data = "";
		
			if(empty($error) && isset($layer_payment_token_data['error'])){
				$error = 'E55 Payment error. ' . $layer_payment_token_data['error'];          
			}

			if(empty($error) && (!isset($layer_payment_token_data["id"]) || empty($layer_payment_token_data["id"]))){				
				$error = 'Payment error. ' . 'Layer token ID cannot be empty';        
			}   
    
			if(empty($error))
				$payment_token_data = $this->get_payment_token($layer_payment_token_data["id"]);
    
			if(empty($error) && empty($payment_token_data))
				$error = 'Layer token data is empty...';
		
			if(empty($error) && isset($payment_token_data['error'])){
				$error = 'E56 Payment error. ' . $payment_token_data['error'];            
			}

			if(empty($error) && $payment_token_data['status'] == "paid"){
				$error = "Layer: this order has already been paid";            
			}
			
			if(empty($error) && $payment_token_data['amount'] != $orderAmount){
				$error = "Layer: an amount mismatch occurred";
			}
		
    
			if(empty($error) && !empty($payment_token_data)){		
        
				$hash = $this->create_hash(array(
				'layer_pay_token_id'    => $payment_token_data['id'],
				'layer_order_amount'    => $payment_token_data['amount'],
				'woo_order_id'    		=> $orderId,
				));
			}
			
            if(!$error)
			{  
				Media::addJsDef([
					'layer_checkout_vars'    =>  [						
						'token_id'          => $payment_token_data['id'],						
						'amount'            => $payment_token_data['amount'],
						'orderid'           => $orderId,
						'hash'      		=> $hash,
						'accesskey'        => $this->layerpayment_accesskey,						
					]
				]);
			}
			else {
				Logger::addLog("Layer Error: " . $error, 4);
			}
        }
    }

	public function hookPaymentOptions($params)
	{	
		$option = new PaymentOption();	
        $option->setModuleName($this->name)
                ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
                ->setCallToActionText('Pay by Layer Payment')
                ->setAdditionalInformation('<p>Pay using Credit/Debit Card, NetBanking, Wallets, or UPI</p>');
        return [
            $option,
        ];
	}
	
	
	public function flogger($message)
	{
		$logger = new FileLogger(0); //0 == debug level, logDebug() won’t work without this.
		$logger->setFilename(_PS_ROOT_DIR_.'/var/logs/LAYERdebug.log');
		$logger->logDebug($message);
		return true;
	}
	
	private function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit')) {
			if (!Tools::getValue('LAYERPAYMENT_SANDBOX')) {
				$this->_postErrors[] = $this->trans('Choice Required.', array(),'Modules.Layerpayment.Admin');
			} elseif (!Tools::getValue('LAYERPAYMENT_ACCESSKEY')) {
				$this->_postErrors[] = $this->trans('Access Key is required.', array(), 'Modules.Layerpayment.Admin');
			} elseif (!Tools::getValue('LAYERPAYMENT_SECRETKEY')) {
				$this->_postErrors[] = $this->trans('Secret Key is required.', array(), 'Modules.Layerpayment.Admin');		
			}
		}
	}

	private function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit')) {			
			Configuration::updateValue('LAYERPAYMENT_SANDBOX', Tools::getValue('LAYERPAYMENT_SANDBOX'));
			Configuration::updateValue('LAYERPAYMENT_ACCESSKEY', Tools::getValue('LAYERPAYMENT_ACCESSKEY'));
			Configuration::updateValue('LAYERPAYMENT_SECRETKEY', Tools::getValue('LAYERPAYMENT_SECRETKEY'));			
		}
		$this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Notifications.Success'));
	}
	
	public function getContent()
	{
		 $this->_html = '';

        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        }

        $this->_html .= $this->_displayCheck();
        $this->_html .= $this->renderForm();

		return $this->_html;
	}
	
	public function renderForm()
	{
		$options = array(
			array(
					'id_option' => 'yes', 
					'name' => 'Yes' 
					),
				array(
					'id_option' => 'no',
					'name' => 'No'
					),
				);
		$fields_form = array(
			'form' => array(
					'legend' => array(
						'title' => $this->trans('Configuration Parameters', array(), 'Modules.Layerpayment.Admin'),
						'icon' => 'icon-envelope'
						),
					'input' => array(						
						array(
							'type' => 'select',
							'label' => $this->trans('Sandbox', array(), 'Modules.Layerpayment.Admin'),
							'name' => 'LAYERPAYMENT_SANDBOX',
							'required' => true,
							'options' => array(
								'query' => $options,
								'id' => 'id_option', 
								'name' => 'name'
								)
							),
						array(
							'type' => 'text',
							'label' => $this->trans('Access Key', array(), 'Modules.Layerpayment.Admin'),
							'name' => 'LAYERPAYMENT_ACCESSKEY',
							'required' => true								
							),
						array(
							'type' => 'text',
							'label' => $this->trans('Secret Key', array(), 'Modules.Layerpayment.Admin'),
							'name' => 'LAYERPAYMENT_SECRETKEY',
							'required' => true
							)						
					),
					'submit' => array(
						'title' => $this->trans('Save', array(), 'Admin.Actions'),
						)
					),
				);
			

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			);

		$this->fields_form = array();

		return $helper->generateForm(array($fields_form));
	}

	public function getConfigFieldsValues()
	{
		return array(			
			'LAYERPAYMENT_SANDBOX' => Tools::getValue('LAYERPAYMENT_SANDBOX', Configuration::get('LAYERPAYMENT_SANDBOX')),
			'LAYERPAYMENT_ACCESSKEY' => Tools::getValue('LAYERPAYMENT_ACCESSKEY', Configuration::get('LAYERPAYMENT_ACCESSKEY')),
			'LAYERPAYMENT_SECRETKEY' => Tools::getValue('LAYERPAYMENT_SECRETKEY', Configuration::get('LAYERPAYMENT_SECRETKEY')),			
			);
	}
	
	public function create_hash($data){
		ksort($data);
		$hash_string = $this->layer_accesskey;
		foreach ($data as $key=>$value){
			$hash_string .= '|'.$value;
		}
		return hash_hmac("sha256",$hash_string,$this->layer_secretkey);
	}
	
	public function verify_hash($data,$rec_hash){
		$gen_hash = $this->create_hash($data);
		if($gen_hash === $rec_hash){
			return true;
		}
		return false;
	}
	
	public function create_payment_token($data){

        try {
            $pay_token_request_data = array(
                'amount'   			=> (isset($data['amount']))? $data['amount'] : NULL,
                'currency' 			=> (isset($data['currency']))? $data['currency'] : NULL,
                'name'     			=> (isset($data['name']))? $data['name'] : NULL,
                'email_id' 			=> (isset($data['email_id']))? $data['email_id'] : NULL,
                'contact_number' 	=> (isset($data['contact_number']))? $data['contact_number'] : NULL,
                'mtx'    			=> (isset($data['mtx']))? $data['mtx'] : NULL,
                'udf'    			=> (isset($data['udf']))? $data['udf'] : NULL,
            );

            $pay_token_data = $this->http_post($pay_token_request_data,"payment_token");

            return $pay_token_data;
        } catch (Exception $e){			
            return [
                'error' => $e->getMessage()
            ];

        } catch (Throwable $e){
			
			return [
                'error' => $e->getMessage()
            ];
        }
    }

    public function get_payment_token($payment_token_id){

        if(empty($payment_token_id)){

            throw new Exception("payment_token_id cannot be empty");
        }

        try {

            return $this->http_get("payment_token/".$payment_token_id);

        } catch (Exception $e){

            return [
                'error' => $e->getMessage()
            ];

        } catch (Throwable $e){

            return [
                'error' => $e->getMessage()
            ];
        }

    }   


    public function build_auth($body,$method){

        $time_stamp = trim(time());
        unset($body['udf']);

        if(empty($body)){

            $token_string = $time_stamp.strtoupper($method);

        } else {            
            $token_string = $time_stamp.strtoupper($method).json_encode($body);
        }

        $token = trim(hash_hmac("sha256",$token_string,$this->layerpayment_secretkey));

        return array(                       
            'Content-Type: application/json',                                 
            'Authorization: Bearer '.$this->layerpayment_accesskey.':'.$this->layerpayment_secretkey,
            'X-O-Timestamp: '.$time_stamp
        );

    }


    public function http_post($data,$route){

        foreach (@$data as $key=>$value){

            if(empty($data[$key])){

                unset($data[$key]);
            }
        }

        if($this->layerpayment_sandbox == 'yes'){
            $url = self::BASE_URL_SANDBOX."/".$route;
        } else {
            $url = self::BASE_URL_UAT."/".$route;
        }
		
        $header = $this->build_auth($data,"post");
		
        try
        {
            $curl = curl_init();
		    curl_setopt($curl, CURLOPT_URL, $url);
		    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		    curl_setopt($curl, CURLOPT_SSLVERSION, 6);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_MAXREDIRS,10);
		    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		    curl_setopt($curl, CURLOPT_ENCODING, '');		
		    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_HEX_APOS|JSON_HEX_QUOT ));
            
		    $response = curl_exec($curl);
            $curlerr = curl_error($curl);
            
            if($curlerr != '')
            {
                return [
                    "error" => "Http Post failed",
                    "error_data" => $curlerr,
                ];
            }
            return json_decode($response,true);
        }
        catch(Exception $e)
        {
            return [
                "error" => "Http Post failed",
                "error_data" => $e->getMessage(),
            ];
        }           
        
    }

    public function http_get($route){

        if($this->layerpayment_sandbox == 'yes'){
			$url = self::BASE_URL_SANDBOX."/".$route;
        } else {			
            $url = self::BASE_URL_UAT."/".$route;
		}

        $header = $this->build_auth($data = [],"get");

        try
        {           
            $curl = curl_init();
		    curl_setopt($curl, CURLOPT_URL, $url);
		    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		    curl_setopt($curl, CURLOPT_SSLVERSION, 6);
		    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		    curl_setopt($curl, CURLOPT_ENCODING, '');		
		    curl_setopt($curl, CURLOPT_TIMEOUT, 60);		   
            $response = curl_exec($curl);
            $curlerr = curl_error($curl);
            if($curlerr != '')
            {
                return [
                    "error" => "Http Get failed",
                    "error_data" => $curlerr,
                ];
            }
            return json_decode($response,true);
        }
        catch(Exception $e)
        {
            return [
                "error" => "Http Get failed",
                "error_data" => $e->getMessage(),
            ];
        }
    }
	
	private function _displayCheck()
	{
		return $this->display(__FILE__, './views/templates/hook/infos.tpl');
	}
	
	
}
?>
