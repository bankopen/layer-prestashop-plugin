<?php
/*
* 2020 Open
*
*  @author Open
*/

/**
 * @since 1.7.0
 */
class LayerpaymentValidationModuleFrontController extends ModuleFrontController
{
	public $warning = '';
	public $message = '';
	
	private $layerpayment_sandbox;
	private $layerpayment_accesskey;
	private $layerpayment_secretkey;
	
	const BASE_URL_SANDBOX = "https://sandbox-icp-api.bankopen.co/api";
    const BASE_URL_UAT = "https://icp-api.bankopen.co/api";
	
	public function initContent()
  	{  
		parent::initContent();
	
		$this->context->smarty->assign(array(
		  	'warning' => $this->warning,
			'message' => $this->message
        	));        	
	    
		$this->setTemplate('module:layerpayment/views/templates/front/validation.tpl');  
    	
    	
  	}
	
    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'layerpayment') {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
           $this->warning='This payment method is not available.';
		   $this->message='Contact Administrator for available payment methods.';
		   return;
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

		$currency = $this->context->currency;
		$orderAmount =number_format(Tools::convertPrice($this->context->cart->getOrderTotal(),$currency), 2, '.', '');
		
		$this->layerpayment_sandbox = Configuration::get('LAYERPAYMENT_SANDBOX');
		$this->layerpayment_accesskey = Configuration::get('LAYERPAYMENT_ACCESSKEY');
		$this->layerpayment_secretkey = Configuration::get('LAYERPAYMENT_SECRETKEY');	
		
		$responseMsg="";
		$flag=true;
		
		if($flag && !isset($_POST['layer_payment_id']) || empty($_POST['layer_payment_id']))
		{
			//invalid response	
			$responseMsg = "Error:: Invalid response...";
			$flag = false;	
			Tools::redirect('index.php?controller=order&step=1');			
			exit();
		}
		
		if($flag && empty($responseMsg))
		{
			$data = array(
                'layer_pay_token_id'    => $_POST['layer_pay_token_id'],
                'layer_order_amount'    => $_POST['layer_order_amount'],
                'woo_order_id'     		=> $_POST['woo_order_id'],
            );
							
			if($this->verify_hash($data,$_POST['hash']))
			{
				$responseMsg = "Thank you for shopping with us. However, the payment failed.";
				$flag=false;							
			}
			if ($flag) {
				$payment_data = $this->get_payment_details($_POST['layer_payment_id']);
				if(isset($payment_data['error'])){
					$flag=false;
					$responseMsg = $payment_data['error'];
				}
				if($flag && isset($payment_data['id']) && !empty($payment_data)){
                    if($payment_data['payment_token']['id'] != $data['layer_pay_token_id']){
                        $responseMsg = "Layer: received layer_pay_token_id and collected layer_pay_token_id doesnt match";
						$flag = false;
                    }
					if($data['layer_order_amount'] != $payment_data['amount'] || $orderAmount !=$payment_data['amount'] ){
                        $responseMsg = "Layer: received amount and collected amount doesnt match";
						$flag = false;
                    }
					if($flag && ($payment_data['status'] == 'authorized' || $payment_data['status'] == 'captured')) {
						$responseMsg = "Thank you for shopping with us. Your account has been charged and your transaction is successful.";
						$status = Configuration::get('LAYERPAYMENT_ID_ORDER_SUCCESS');						
					}
					elseif($flag && ($payment_data['status'] == 'failed' || $payment_data['status'] == 'cancelled')) {
						$responseMsg = "Thank you for shopping with us. However your payment is either cancelled or failed...";
						$status = Configuration::get('LAYERPAYMENT_ID_ORDER_FAILED');						
						$flag = false;
					}				
				}
				if($flag) {
					$this->module->validateOrder($cart->id, $status, (float)$orderAmount, 'Layer Payment',NULL,NULL,NULL,false,$customer->secure_key);
					//update transaction_id for future use with refund
					$isOrderX = Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'orders WHERE id_customer = '.$cart->id_customer.' ORDER BY id_order DESC ');
					if($isOrderX) {	
						$order = new Order((int)$isOrderX['id_order']);
						$payments = $order->getOrderPaymentCollection();
						$payments[0]->transaction_id = $_POST['layer_payment_id'];
						$payments[0]->update();
					}
					PrestaShopLogger::addLog("Layer: Created Order for Cartid-".$cart->id,1, null, 'Layer Payment', (int)$cart->id, true);
				}
			}
		}
			
			
		if ($flag)
		{
			$this->message = $responseMsg;
			Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
		}
		else
		{			
			$this->warning= $responseMsg;			
			Tools::redirect('index.php?controller=order&step=1');
		}
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
	
    public function get_payment_details($payment_id){

        if(empty($payment_id)){

            throw new Exception("payment_id cannot be empty");
        }

        try {

            return $this->http_get("payment/".$payment_id);

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
            'Authorization: Bearer '.$this->layerpayment_accesskey.':'.$this->layerpayment_secretkey',
            'X-O-Timestamp: '.$time_stamp
        );

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
}
