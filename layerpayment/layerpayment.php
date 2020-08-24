<?php
defined('_JEXEC') or die('Restricted access');

/**

 */
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentLayerpayment extends vmPSPlugin {

    // instance of class
    public static $_this = false;
	const BASE_URL_SANDBOX = "https://sandbox-icp-api.bankopen.co/api";
    const BASE_URL_UAT = "https://icp-api.bankopen.co/api";
	
    function __construct(& $subject, $config) {
		//if (self::$_this)
		//   return self::$_this;
		parent::__construct($subject, $config);
	
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; 
		$this->_tableId = 'id'; 
		$varsToPush = array(
			'accesskey' => array('','char'),
			'secretkey' => array('','char'),
			'environment' => array('','char'),
			'description' => array('','text'),		    
		    'status_pending' => array('', 'char'),
		    'status_success' => array('', 'char'),
		    'status_canceled' => array('', 'char'),
		    'secure_post' => array('', 'int'),
		    'ipn_test' => array('', 'int')		    
		);
	
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	
		//self::$_this = $this;
    }
    
 	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Layer Table');
    }
    
	function getTableSQLFields() {
		$SQLfields = array(
		    'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
		    'virtuemart_order_id' => 'int(1) UNSIGNED',
		    'order_number' => ' char(64)',
		    'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
		    'payment_name' => 'varchar(5000)',
			'layer_custom' => ' varchar(255)',
		    'amount' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'status' => 'varchar(225)',
			'environment'=> 'varchar(225)',
			'paymentid' => 'varchar(255)',
			'tokenid' => 'varchar(255)',
		);
	return $SQLfields;
	}
	
	function plgVmConfirmedOrder($cart, $order) {		
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		if (!class_exists('VirtueMartModelCurrency'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');		
		    
		if (!class_exists('TableVendors'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		$vendorModel = VmModel::getModel('Vendor');
		$vendorModel->setId(1);
		$vendor = $vendorModel->getVendor();
		$vendorModel->addImages($vendor, 1);

		$accesskey = $method->accesskey;
		$secretkey = $method->secretkey;
		$environment = $method->environment;
		$description = $method->description;
		$return_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id.'&DR={DR}');
		
		$currency_code_3 = shopFunctions::getCurrencyByID ($order['details']['BT']->order_currency, 'currency_code_3');
			
		$layer_payment_token_data = $this->create_payment_token([
                'amount' => round($order['details']['BT']->order_total,2),
                'currency' => $currency_code_3,
                'name'  =>  trim(substr($order['details']['BT']->first_name.' '.$order['details']['BT']->last_name, 0, 20)),
                'email_id' => trim(substr($order['details']['BT']->email, 0, 255)),
                'contact_number' => (!empty($order['details']['BT']->phone_2))? $order['details']['BT']->phone_2 : $order['details']['BT']->phone_1,
				'mtx' => $order['details']['BT']->order_number
            ],$accesskey,$secretkey,$environment);
		
		if(isset($layer_payment_token_data['error'])){
			$error = 'E55 Payment error. ' . ucfirst($layer_payment_token_data['error']);  
			if(isset($layer_payment_token_data['error_data']))
			{
				foreach($layer_payment_token_data['error_data'] as $d)
					$error .= " ".ucfirst($d[0]);
			}
			vmInfo ($error);
            return FALSE;
        }
		
        if(!isset($layer_payment_token_data["id"]) || empty($layer_payment_token_data["id"])){
			vmInfo('Payment error. Layer token ID cannot be empty.');						
            return FALSE;
        }
			
		if(!empty($layer_payment_token_data["id"]))
			$payment_token_data = $this->get_payment_token($layer_payment_token_data["id"],$accesskey,$secretkey,$environment);
	    
		if(empty($payment_token_data)){    
			vmInfo("Transaction failed...");
			return FALSE;
		}
		$error = "";
		if(isset($layer_payment_token_data['error'])){
			$error = 'E56 Payment error. ' . $payment_token_data['error'];            
		}
		if(empty($error) && $payment_token_data['status'] == "paid"){
			$error = "this order has already been paid.";            
		}
		if(empty($error) && round($payment_token_data['amount'],2) != round($order['details']['BT']->order_total,2)){
			$error = "an amount mismatch occurred.";
		}
		if(!empty($error)) {
			vmInfo($error);
			return FALSE;
		}
		
		$remote_script='<script type="application/javascript" id="open_money_layer" src="https://sandbox-payments.open.money/layer"></script>';
		if( $environment == "live")
			$remote_script='<script type="application/javascript" id="open_money_layer" src="https://payments.open.money/layer"></script>';

		$jsdata['payment_token_id'] = html_entity_decode((string) $payment_token_data['id'],ENT_QUOTES,'UTF-8');
		$jsdata['accesskey']  = html_entity_decode((string) $accesskey,ENT_QUOTES,'UTF-8');
        
		$hash = $this->create_hash(array(
			'layer_pay_token_id'    => $payment_token_data['id'],
			'layer_order_amount'    => $payment_token_data['amount'],
			'ordernumber'    => $order['details']['BT']->order_number
		),$accesskey,$secretkey);
        
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['layer_custom'] = $currency_code_3;
		$dbValues['amount'] = round($payment_token_data['amount'],2);
		$dbValues['environment'] = $environment;		
		$dbValues['description'] = $description;//$description;				
		
		$this->storePSPluginInternalData($dbValues);	
		
		$html =  "<form action='".$return_url."' method='post' style='display: none' name='layer_payment_int_form'>
					<input type='hidden' name='layer_pay_token_id' value='".$payment_token_data['id']."'>
					<input type='hidden' name='ordernumber' value='".$order['details']['BT']->order_number."'>
					<input type='hidden' name='layer_order_amount' value='".$payment_token_data['amount']."'>
					<input type='hidden' id='layer_payment_id' name='layer_payment_id' value=''>
					<input type='hidden' id='fallback_url' name='fallback_url' value=''>
					<input type='hidden' name='hash' value='".$hash."'>
					</form>";
		$html .= $remote_script;
		$html .= "<script>";
		$html .= "var layer_params = " . json_encode( $jsdata ) . ';'; 
		$html .="</script>";
		$html .= "<script type='text/javascript'>";
		$html .= "function trigger_layer() {
							Layer.checkout(
							{
								token: layer_params.payment_token_id,
								accesskey: layer_params.accesskey								
							},
							function (response) {
								console.log(response)
								if(response !== null || response.length > 0 ){
									if(response.payment_id !== undefined && response.status != 'cancelled' ){
										document.getElementById('layer_payment_id').value = response.payment_id;
										document.layer_payment_int_form.submit();										
									}
									else if(response.payment_id !== undefined && response.status == 'cancelled') {
										Layer.cancel;
									}
								}								
							},
							function (err) {
								alert(err.message);
							});
						}
						var checkExist = setInterval(function() {
							if (typeof Layer !== 'undefined') {
								console.log('Layer Loaded...');
								clearInterval(checkExist);
								trigger_layer();
							}
							else {
								console.log('Layer undefined...');
							}
						}, 1000);";
		$html .= "</script>";
				
	
		// 	2 = don't delete the cart, don't send email and don't redirect
		//$cart->_confirmDone = false;
		$cart->_dataValidated = true;
		//$cart->setCartIntoSession();
		//JRequest::setVar('html', $html);
		return $this->processConfirmedOrderPaymentResponse (0, $cart, $order, $html, '', '');
    }
    
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnPaymentResponseReceived(&$html) {
		if (!class_exists('VirtueMartCart'))
	    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
		if (!class_exists('shopFunctionsF'))
		    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$order_number = JRequest::getString('on', 0);	
		
		$vendorId = 0;
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return null;
		}	
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
		    return null;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id) )) {
		    // JError::raiseWarning(500, $db->getErrorMsg());
		    return '';
		}
		
		$new_status = '';
		$payment_name = $this->renderPluginName($method);
	    $response = array();
        $response = $_POST;
		$error = "";
		
		if(isset($response['layer_payment_id']) && !empty($response['layer_payment_id']))
		{
			$data = array(
			'layer_pay_token_id'    => $response['layer_pay_token_id'],
			'layer_order_amount'    => $response['layer_order_amount'],
			'ordernumber'  			=> $response['ordernumber'],
			);
	
			if(!$this->verify_hash($data,$response['hash'],$method->accesskey,$method->secretkey) && empty($data['ordernumber']))
				$error = "Invalid transaction...";				
			else {				
				$payment_data = $this->get_payment_details($response['layer_payment_id'],$method->accesskey,$method->secretkey,$method->environment);
				if(isset($payment_data['error']))
					$error = "Layer: an error occurred E14".$payment_data['error'];		
		
				if(!$error && $payment_data['payment_token']['id'] != $data['layer_pay_token_id'])
					$error = "Layer: received layer_pay_token_id and collected layer_pay_token_id doesnt match";
				elseif(!$error && round($data['layer_order_amount'],2) != round($payment_data['amount'],2))
					$error = "Layer: received amount and collected amount doesnt match";
			}
			if($error)
			{	
				vmInfo($error);
				$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.	$virtuemart_paymentmethod_id);
				$html= ' <script type="text/javascript">';
				$html.= 'window.location = "'.$cancel_return.'"';
				$html.= ' </script>';
				JRequest::setVar('html', $html);
				return true;
			}
			$response_fields['order_number'] = $response['ordernumber'];
			$response_fields['amount'] = $payment_data['amount'];
			$response_fields['status'] = $payment_data['status'];
			$response_fields['paymentid'] = $payment_data['id'];
			$response_fields['tokenid'] = $payment_data['payment_token']['id'];
			
    		if($payment_data['status'] == 'authorized' || $payment_data['status'] == 'captured'){				
				$new_status = $method->status_success;
				// print_r($new_status);die;
				$modelOrder = VmModel::getModel('orders');
				$order['order_status'] = $new_status;
		        //print_r($order);die;
				$order['customer_notified'] = 1;
				$order['comments'] = '';
				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
       		    //print_r($_POST);die;
       
				$this->_storeLayerInternalData($method, $response_fields, $virtuemart_order_id,$payment_data['currency']);	
				$html = $this->_getPaymentResponseHtml($paymentTable, $payment_name, $response);
			}
			else if ( $payment_data['status'] == 'failed' || $payment_data['status'] == 'cancelled' ){
				$new_status = $method->status_canceled;	
				$modelOrder = VmModel::getModel('orders');
				$order['order_status'] = $new_status;
		        //print_r($order);die;
				$order['customer_notified'] = 1;
				$order['comments'] = '';
				$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
       		    //print_r($_POST);die;
       
				$this->_storeLayerInternalData($method, $response_fields, $virtuemart_order_id,$payment_data['currency']);	
				$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.	$virtuemart_paymentmethod_id);
				$html= ' <script type="text/javascript">';
				$html.= 'window.location = "'.$cancel_return.'"';
				$html.= ' </script>';

			}
            JRequest::setVar('html', $html);
		}
		else {
			$new_status = $method->status_canceled;	
			$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.	$virtuemart_paymentmethod_id);
			$html= ' <script type="text/javascript">';
			$html.= 'window.location = "'.$cancel_return.'"';
			$html.= ' </script>';
			JRequest::setVar('html', $html);
		}
		// get the correct cart / session
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return true;
    }
    
	function _getPaymentResponseHtml($paymentTable, $payment_name, $response) {
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('LAYER_PAYMENT_NAME', $payment_name);		
		if (!empty($paymentTable)) {		    
            $html .= $this->getHtmlRow('LAYER_VIRTUEMART_ORDER_ID', $paymentTable->virtuemart_order_id);
		}
		
		
		$tot_amount = round($paymentTable->amount,2).' '.$paymentTable->layer_custom;
		$html .= $this->getHtmlRow('LAYER_AMOUNT', $tot_amount);
		
	
		return $html;
    }
    
	function _storeLayerInternalData($method, $response, $virtuemart_order_id,$custom) {
      
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$response_fields['order_number'] = $response['order_number'];
		$response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
		$response_fields['payment_name'] = $this->renderPluginName($method);	
		$response_fields['layer_custom'] = $custom;
		$response_fields['amount'] = $response['amount'];
		$response_fields['status'] = $response['status'];
		$response_fields['environment'] = $method->environment;
		$response_fields['paymentid'] = $response['payment_id'];
		$response_fields['tokenid'] = $response['payment_token']['id'] ;			
  		
		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
    }
    
 	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
		$order_number = JRequest::getString('on', '');
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', '');
		if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
		    return null;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return null;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
		    return null;
		}
	
		VmInfo(Jtext::_('VMPAYMENT_LAYER_PAYMENT_CANCELLED'));
		$session = JFactory::getSession();
		$return_context = $session->getId();
		if (strcmp($paymentTable->layer_custom, $return_context) === 0) {
		    $this->handlePaymentUserCancel($virtuemart_order_id);
		}
		return true;
    }
    
	
	
    
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {
		if (!$this->selectedThisByMethodId($payment_method_id)) {
		    return null; // Another method was selected, do nothing
		}
		if (!($paymentTable = $this->_getLayerInternalData($virtuemart_order_id) )) {
		    // JError::raiseWarning(500, $db->getErrorMsg());
		    return '';
		}
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->billing_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('LAYER_PAYMENT_NAME', $paymentTable->payment_name);		
		//echo "<pre>";print_r($paymentTable);echo "</pre>";
		$html .= $this->getHtmlRowBE('LAYER_VIRTUEMART_ORDER_ID', $paymentTable->virtuemart_order_id);
		$html .= $this->getHtmlRowBE('LAYER_RESPONSE_MESSAGE', $paymentTable->status);
		$html .= $this->getHtmlRowBE('LAYER_PAYMENT_ID', $paymentTable->paymentid);
		$html .= $this->getHtmlRowBE('LAYER_AMOUNT', round($paymentTable->amount,2).' '.$paymentTable->layer_custom);
		$html .= $this->getHtmlRowBE('LAYER_MODE', $paymentTable->environment);
		$html .= $this->getHtmlRowBE('LAYER_PAYMENT_DATE', $paymentTable->modified_on);
		$html .= '</table>' . "\n";
		return $html;
    }

    function _getLayerInternalData($virtuemart_order_id, $order_number = '') {
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
		    $q .= " `order_number` = '" . $order_number . "'";
		} else {
		    $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
		    // JError::raiseWarning(500, $db->getErrorMsg());
		    return '';
		}
		return $paymentTable;
    } 
	
    
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		if (preg_match('/%$/', $method->cost_percent_total)) {
		    $cost_percent_total = substr($method->cost_percent_total, 0, -1);
		} else {
		    $cost_percent_total = $method->cost_percent_total;
		}
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }
    
	protected function checkConditions($cart, $method, $cart_prices) {
		$this->convert($method);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0) ));
		$countries = array();
		if (!empty($method->countries)) {
		    if (!is_array($method->countries)) {
			$countries[0] = $method->countries;
		    } 
                    else {
			$countries = $method->countries;
		    }
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
		    $address = array();
		    $address['virtuemart_country_id'] = 0;
		}
		if (!isset($address['virtuemart_country_id']))
		    $address['virtuemart_country_id'] = 0;
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
		    if ($amount_cond) {
			return true;
		    }
		}
		return false;
    }
    
 	function convert($method) {
		$method->min_amount = (float) $method->min_amount;
		$method->max_amount = (float) $method->max_amount;
    }
    
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
    }
    
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
    
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(),   &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices,  $paymentCounter);
    }
    
 	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
 	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
    }
    
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
    }
    
    
    function plgVmDeclarePluginParamsPaymentVM3( &$data) {
            return $this->declarePluginParams('payment', $data);
    }    

	function create_payment_token($data,$accesskey,$secretkey,$environment){

        try {
            $pay_token_request_data = array(
                'amount'   			=> ($data['amount'])? $data['amount'] : NULL,
                'currency' 			=> ($data['currency'])? $data['currency'] : NULL,
                'name'     			=> ($data['name'])? $data['name'] : NULL,
                'email_id' 			=> ($data['email_id'])? $data['email_id'] : NULL,
                'contact_number' 	=> ($data['contact_number'])? $data['contact_number'] : NULL,
                'mtx'    			=> ($data['mtx'])? $data['mtx'] : NULL,
                'udf'    			=> ($data['udf'])? $data['udf'] : NULL,
            );

            $pay_token_data = $this->http_post($pay_token_request_data,"payment_token",$accesskey,$secretkey,$environment);

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

    function get_payment_token($payment_token_id,$accesskey,$secretkey,$environment){

        if(empty($payment_token_id)){

            throw new Exception("payment_token_id cannot be empty");
        }

        try {

            return $this->http_get("payment_token/".$payment_token_id,$accesskey,$secretkey,$environment);

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

    public function get_payment_details($payment_id,$accesskey,$secretkey,$environment){

        if(empty($payment_id)){

            throw new Exception("payment_id cannot be empty");
        }

        try {

            return $this->http_get("payment/".$payment_id,$accesskey,$secretkey,$environment);

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


    function build_auth($accesskey,$secretkey){

         return array(                       
            'Content-Type: application/json',                                 
            'Authorization: Bearer '.$accesskey.':'.$secretkey            
        );

    }


    function http_post($data,$route,$accesskey,$secretkey,$environment){

        foreach (@$data as $key=>$value){

            if(empty($data[$key])){

                unset($data[$key]);
            }
        }

        if($environment == 'live'){

            $url = self::BASE_URL_UAT."/".$route;

        } else {

            $url = self::BASE_URL_SANDBOX."/".$route;
        }

        $header = $this->build_auth($accesskey,$secretkey);

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
		    curl_setopt($curl, CURLOPT_TIMEOUT, 120);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_HEX_APOS|JSON_HEX_QUOT ));
            
		    $response = curl_exec($curl);
            $curlerr = curl_error($curl);

            if($curlerr != '')
            {
                return [
                    "error" => "Http Post failed.",
                    "error_data" => $curlerr,
                ];
            }
            return json_decode($response,true);
        }
        catch(Exception $e)
        {
            return [
                "error" => "Http Post failed.",
                "error_data" => $e->getMessage(),
            ];
        } 
    }

    function http_get($route,$accesskey,$secretkey,$environment){

        if($environment == 'live'){

            $url = self::BASE_URL_UAT."/".$route;

        } else {

            $url = self::BASE_URL_SANDBOX."/".$route;
        }


        $header = $this->build_auth($accesskey,$secretkey);
			
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
                    "error" => "Http Get failed.",
                    "error_data" => $curlerr,
                ];
            }
            return json_decode($response,true);
        }
        catch(Exception $e)
        {
            return [
                "error" => "Http Get failed.",
                "error_data" => $e->getMessage(),
            ];
        }

    }

	function create_hash($data,$accesskey,$secretkey){

        ksort($data);
        $hash_string = $accesskey;
        foreach ($data as $key=>$value){
			$hash_string .= '|'.$value;
        }
        return hash_hmac("sha256",$hash_string,$secretkey);
    }

    function verify_hash($data,$rec_hash,$accesskey,$secretkey){

        $gen_hash = $this->create_hash($data,$accesskey,$secretkey);

        if($gen_hash === $rec_hash){
            return true;
        }
        return false;
    }
}
