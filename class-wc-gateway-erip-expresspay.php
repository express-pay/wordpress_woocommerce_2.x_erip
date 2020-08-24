<?php
/*
  Plugin Name: «Экспресс Платежи» для WooCommerce
  Plugin URI: https://express-pay.by/cms-extensions/wordpress
  Description: «Экспресс Платежи» - плагин для интеграции с сервисом «Экспресс Платежи» (express-pay.by) через API. Плагин позволяет выставить счет в системе ЕРИП, получить и обработать уведомление о платеже в системе ЕРИП, выставлять счета для оплаты банковскими картами, получать и обрабатывать уведомления о платеже по банковской карте. Описание плагина доступно по адресу: <a target="blank" href="https://express-pay.by/cms-extensions/wordpress">https://express-pay.by/cms-extensions/wordpress</a>
  Version: 2.5
  Author: ООО «ТриИнком»
  Author URI: https://express-pay.by/
 */

if(!defined('ABSPATH')) exit;

define("ERIP_EXPRESSPAY_VERSION", "2.5");

add_action('plugins_loaded', 'init_gateway', 0);

function add_wc_erip_expresspay($methods) {
	$methods[] = 'wc_erip_expresspay';

	return $methods;
}

function add_wc_card_expresspay($methods) {
	$methods[] = 'wc_card_expresspay';

	return $methods;
}

function init_gateway() {
	if(!class_exists('WC_Payment_Gateway'))
		return;

	add_filter('woocommerce_payment_gateways', 'add_wc_erip_expresspay');
    add_filter('woocommerce_payment_gateways', 'add_wc_card_expresspay');

	load_plugin_textdomain("wc_erip_expresspay", false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');

	class WC_ERIP_EXPRESSPAY extends WC_Payment_Gateway {
		private $plugin_dir;

		public function __construct() {
			$this->id = "expresspay_erip";
            $this->method_title = __('Экспресс Платежи: ЕРИП');
            $this->method_description = __('Прием платежей в системе ЕРИП сервис «Экспресс Платежи»');
			$this->plugin_dir = plugin_dir_url(__FILE__);

			$this->init_form_fields();
			$this->init_settings();

			$this->title = __("Система \"Расчет\" ЕРИП", 'wc_erip_expresspay');
			$this->message_success = $this->get_option('message_success');
			$this->secret_word = $this->get_option('secret_key');
			$this->secret_key_notify = $this->get_option('secret_key_notify');
			$this->token = $this->get_option('token');
			$this->is_use_signature = ( $this->get_option('sign_invoices') == 'yes' ) ? true : false;
			$this->is_use_signature_notify = ( $this->get_option('sign_notify') == 'yes' ) ? true : false;
			$this->url = ( $this->get_option('test_mode') != 'yes' ) ? $this->get_option('url_api') : $this->get_option('url_sandbox_api');
			$this->url .= "/v1/invoices?token=" . $this->token;
			$this->name_editable = ( $this->get_option('name_editable') == 'yes' ) ? 1 : 0;
			$this->address_editable = ( $this->get_option('address_editable') == 'yes' ) ? 1 : 0;
			$this->amount_editable = ( $this->get_option('amount_editable') == 'yes' ) ? 1 : 0;
			$this->test_mode = ( $this->get_option('test_mode') == 'yes' ) ? 1 : 0;

			add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_wc_erip_expresspay', array($this, 'check_ipn_response'));
		}

		public function admin_options() {
			?>
			<h3><?php _e('«Экспресс Платежи: ЕРИП»', 'wc_erip_expresspay'); ?></h3>
            <div style="float: left; display: inline-block;">
                 <a target="_blank" href="https://express-pay.by"><img src="<?php echo $this->plugin_dir; ?>assets/images/erip_expresspay_big.png" width="270" height="91" alt="exspress-pay.by" title="express-pay.by"></a>
            </div>
            <div style="margin-left: 6px; margin-top: 15px; display: inline-block;">
				<?php _e('«Экспресс Платежи: ЕРИП» - плагин для интеграции с сервисом «Экспресс Платежи» (express-pay.by) через API. 
				<br/>Плагин позволяет выставить счет в системе ЕРИП, получить и обработать уведомление о платеже в системе ЕРИП.
				<br/>Описание плагина доступно по адресу: ', 'wc_erip_expresspay'); ?><a target="blank" href="https://express-pay.by/cms-extensions/wordpress#woocommerce_2_x">https://express-pay.by/cms-extensions/wordpress#woocommerce_2_x</a>
            </div>

			<table class="form-table">
				<?php		
					$this->generate_settings_html();
				?>
			</table>

			<div class="copyright" style="text-align: center;">
				<?php _e("© Все права защищены | ООО «ТриИнком»,", 'wc_erip_expresspay'); ?> 2013-<?php echo date("Y"); ?><br/>
				<?php echo __('Версия', 'wc_erip_expresspay') . " " . ERIP_EXPRESSPAY_VERSION ?>			
			</div>
			<?php
		}

		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __('Включить/Выключить', 'wc_erip_expresspay'),
					'type'    => 'checkbox',
					'default' => 'no'
				),
				'token' => array(
					'title'   => __('Токен', 'wc_erip_expresspay'),
					'type'    => 'text',
					'description' => __('Генерирутся в панели express-pay.by', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'handler_url' => array(
					'title'   => __('Адрес для уведомлений', 'wc_erip_expresspay'),
					'type'    => 'text',
					'css' => 'display: none;',
					'description' => get_site_url() . '/?wc-api=wc_erip_expresspay&action=notify'
				),
				'sign_invoices' => array(
					'title'   => __('Использовать цифровую подпись для API', 'wc_erip_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Параметр проверки запросов с использование цифровой подписи', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'secret_key' => array(
					'title'   => __('Секретное слово для подписи счетов', 'wc_erip_expresspay'),
					'type'    => 'text',
					'description' => __('Секретного слово, которое известно только серверу и клиенту. Используется для формирования цифровой подписи. Задается в панели express-pay.by', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'sign_notify' => array(
					'title'   => __('Использовать цифровую подпись для уведомлений', 'wc_erip_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Параметр проверки запросов с использование цифровой подписи', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'secret_key_norify' => array(
					'title'   => __('Секретное слово для подписи уведомлений', 'wc_erip_expresspay'),
					'type'    => 'text',
					'description' => __('Секретного слово, которое известно только серверу и клиенту. Используется для формирования цифровой подписи. Задается в панели express-pay.by', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'name_editable' => array(
					'title'   => __('Разрешено изменять ФИО плательщика', 'wc_erip_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Разрешается при оплате счета изменять ФИО плательщика', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'address_editable' => array(
					'title'   => __('Разрешено изменять адрес плательщика', 'wc_erip_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Разрешается при оплате счета изменять адрес плательщика', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'amount_editable' => array(
					'title'   => __('Разрешено изменять сумму оплаты', 'wc_erip_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Разрешается при оплате счета изменять сумму платежа', 'wc_erip_expresspay'),
					'desc_tip'    => true
				),
				'test_mode' => array(
					'title'   => __('Использовать тестовый режим', 'wc_erip_expresspay'),
					'type'    => 'checkbox'
				),
				'url_api' => array(
					'title'   => __('Адрес API', 'wc_erip_expresspay'),
					'type'    => 'text',
					'default' => 'https://api.express-pay.by'
				),
				'url_sandbox_api' => array(
					'title'   => __('Адрес тестового API', 'wc_erip_expresspay'),
					'type'    => 'text',
					'default' => 'https://sandbox-api.express-pay.by'
				),
				'message_success' => array(
					'title'   => __('Сообщение при успешном заказе', 'wc_erip_expresspay'),
					'type'    => 'textarea',
					'default' => __('Для оплаты заказа Вам необходимо перейти в раздел ЕРИП:

Интернет-магазины\Сервисы -> "Первая буква доменного имени интернет-магазина" -> "Доменное имя интернет-магазина"

Далее введите номер заказа "##order_id##" и нажмите "продолжить".

После поступления оплаты Ваш заказ поступит в обработку.', 'wc_erip_expresspay'),
					'css'	  => 'min-height: 160px;'
				)
			);
		}

		function process_payment($order_id) {
			$this->log_info('process_payment', 'beginning of the payment process');
			$order = new WC_Order($order_id);	

			if($order->get_status() == 'failed')
				$order->update_status('pending', __('Счет ожидает оплаты', 'wc_erip_expresspay'));

			return array(
				'result' => 'success',
				'redirect'	=> add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			);
		}

		function receipt_page($order_id) {
			$this->log_info('receipt_page', 'Initialization request for add invoice');
			$order = new WC_Order($order_id);

			$price = preg_replace('#[^\d.]#', '', $order->order_total);
			$price = str_replace('.', ',', $order->order_total);
            //$price = floatval($price);

			$currency = (date('y') > 16 || (date('y') >= 16 && date('n') >= 7)) ? '933' : '974';

	        $request_params = array(
	            "AccountNo" => $order_id,
	            "Amount" => $price,
	            "Currency" => $currency,
	            "Surname" => $order->billing_last_name,
	            "FirstName" => $order->billing_first_name,
	            "City" => $order->billing_city,
	            "IsNameEditable" => $this->name_editable,
	            "IsAddressEditable" => $this->address_editable,
	            "IsAmountEditable" => $this->amount_editable
	        );

        	if($this->is_use_signature)
        		$this->url .= "&signature=" . $this->compute_signature_add_invoice($request_params, $this->secret_word);

    		$request_params = http_build_query($request_params);

    		$this->log_info('receipt_page', 'Send POST request; ORDER ID - ' . $order_id . '; URL - ' . $this->url . '; REQUEST - ' . $request_params);

	        $response = "";

	        try {
		        $ch = curl_init();
		        curl_setopt($ch, CURLOPT_URL, $this->url);
		        curl_setopt($ch, CURLOPT_POST, 1);
		        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_params);
		        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		        $response = curl_exec($ch);
		        curl_close($ch);
	    	} catch (Exception $e) {
				$this->log_error_exception('receipt_page', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);

	    		$this->fail($order);
	    	}

	    	$this->log_info('receipt_page', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response);

			try {
	        	$response = json_decode($response);
	    	} catch (Exception $e) {
	    		$this->log_error_exception('receipt_page', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);

	    		$this->fail($order);
	    	}

	        if(isset($response->InvoiceNo))
	        	$this->success($order);
	        else
	        	$this->fail($order);
		}

		private function success($order) {
			global $woocommerce;

			$this->log_info('receipt_page', 'End request for add invoice');
			$this->log_info('success', 'Initialization render success page; ORDER ID - ' . $order->get_order_number());

			$woocommerce->cart->empty_cart();

			$order->update_status('processing', __('Счет успешно оплачен', 'wc_erip_expresspay'));

			echo '<h2>' . __('Счет добавлен в систему ЕРИП для оплаты', 'wc_erip_expresspay') . '</h2>';
			echo str_replace("##order_id##", $order->get_order_number(), nl2br($this->message_success, true));

			echo '<br/><br/><p class="return-to-shop"><a class="button wc-backward" href="' . get_permalink( woocommerce_get_page_id( "shop" ) ) . '">' . __('Продолжить', 'wc_erip_expresspay') . '</a></p>';

			$signature_success = $signature_cancel = "";

			if($this->is_use_signature_notify) {
				$signature_success = $this->compute_signature('{"CmdType": 1, "AccountNo": ' . $order->get_order_number() . '}', $this->secret_key_notify);
				$signature_cancel = $this->compute_signature('{"CmdType": 2, "AccountNo": ' . $order->get_order_number() . '}', $this->secret_key_notify);
			}

			if($this->test_mode) : ?>
				<div class="test_mode">
			        <?php _e('Тестовый режим:', 'wc_erip_expresspay'); ?> <br/>
    				<input type="button" style="margin: 6px 0;" class="button" id="send_notify_success" value="<?php _e('Отправить уведомление об успешной оплате', 'wc_erip_expresspay'); ?>" />
			        <input type="button" class="button" style="margin: 6px 0;" id="send_notify_cancel" class="btn btn-primary" value="<?php _e('Отправить уведомление об отмене оплаты', 'wc_erip_expresspay'); ?>" />

				      <script type="text/javascript">
				        jQuery(document).ready(function() {
				          jQuery('#send_notify_success').click(function() {
				            send_notify(1, '<?php echo $signature_success; ?>');
				          });

				          jQuery('#send_notify_cancel').click(function() {
				            send_notify(2, '<?php echo $signature_cancel; ?>');
				          });

				          function send_notify(type, signature) {
				            jQuery.post('<?php echo get_site_url() . "/?wc-api=wc_erip_expresspay&action=notify" ?>', 'Data={"CmdType": ' + type + ', "AccountNo": <?php echo $order->get_order_number(); ?>}&Signature=' + signature, function(data) {alert(data);})
				            .fail(function(data) {
				              alert(data.responseText);
				            });
				          }
				        });
				      </script>

		     	</div>
			<?php
			endif;

			$this->log_info('success', 'End render success page; ORDER ID - ' . $order->get_order_number());
		}

		private function fail($order) {
			global $woocommerce;

			$this->log_info('receipt_page', 'End request for add invoice');
			$this->log_info('fail', 'Initialization render fail page; ORDER ID - ' . $order->get_order_number());

			$order->update_status('failed', __('Платеж не оплачен', 'wc_erip_expresspay'));

			echo '<h2>' . __('Ошибка выставления счета в системе ЕРИП', 'wc_erip_expresspay') . '</h2>';
			echo __("При выполнении запроса произошла непредвиденная ошибка. Пожалуйста, повторите запрос позже или обратитесь в службу технической поддержки магазина", 'wc_erip_expresspay');

			echo '<br/><br/><p class="return-to-shop"><a class="button wc-backward" href="' . $woocommerce->cart->get_checkout_url() . '">' . __('Попробовать заново', 'wc_erip_expresspay') . '</a></p>';

			$this->log_info('fail', 'End render fail page; ORDER ID - ' . $order->get_order_number());

			die();
		}

		function check_ipn_response() {
			$this->log_info('check_ipn_response', 'Get notify from server; REQUEST METHOD - ' . $_SERVER['REQUEST_METHOD']);

			if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['action']) && $_REQUEST['action'] == 'notify') {
				$data = ( isset($_REQUEST['Data']) ) ? htmlspecialchars_decode($_REQUEST['Data']) : '';
				$data = stripcslashes($data);
				$signature = ( isset($_REQUEST['Signature']) ) ? $_REQUEST['Signature'] : '';

			    if($this->is_use_signature_notify) {
			    	if($signature == $this->compute_signature($data, $this->secret_key_notify))
				        $this->notify_success($data);
				    else  
				    	$this->notify_fail($data);
			    } else 
			    	$this->notify_success($data);
			}

			$this->log_info('check_ipn_response', 'End (Get notify from server); REQUEST METHOD - ' . $_SERVER['REQUEST_METHOD']);

			die();
		}

		private function notify_success($dataJSON) {
			global $woocommerce;

			try {
	        	$data = json_decode($dataJSON);
	    	} catch(Exception $e) {
				$this->log_error('notify_success', "Fail to parse the server response; RESPONSE - " . $dataJSON);

	    		$this->notify_fail($dataJSON);
	    	}

            $order = new WC_Order($data->AccountNo);

	        if(isset($data->CmdType)) {
	        	switch ($data->CmdType) {
	        		case '1':
	                    $order->update_status('completed', __('Счет успешно оплачен', 'wc_erip_expresspay'));
	                    $this->log_info('notify_success', 'Initialization to update status. STATUS ID - Счет успешно оплачен; RESPONSE - ' . $dataJSON);

	        			break;
	        		case '2':
						$order->update_status('cancelled', __('Платеж отменён', 'wc_erip_expresspay'));
						$this->log_info('notify_success', 'Initialization to update status. STATUS ID - Платеж отменён; RESPONSE - '. $dataJSON);

	        			break;
	        		default:
						$this->notify_fail($dataJSON);
						die();
	        	}

		    	header("HTTP/1.0 200 OK");
		    	echo 'SUCCESS';
	        } else
				$this->notify_fail($dataJSON);	
		}

		private function notify_fail($dataJSON) {
			$this->log_error('notify_fail', "Fail to update status; RESPONSE - " . $dataJSON);
			
			header("HTTP/1.0 400 Bad Request");
			echo 'FAILED | Incorrect digital signature';
		}

		private function compute_signature($json, $secret_word) {
		    $hash = NULL;
		    $secret_word = trim($secret_word);
		    
		    if(empty($secret_word))
				$hash = strtoupper(hash_hmac('sha1', $json, ""));
		    else
		        $hash = strtoupper(hash_hmac('sha1', $json, $secret_word));

		    return $hash;
		}	

	    private function compute_signature_add_invoice($request_params, $secret_word) {
	    	$secret_word = trim($secret_word);
	        $normalized_params = array_change_key_case($request_params, CASE_LOWER);
	        $api_method = array(
	                "accountno",
	                "amount",
	                "currency",
	                // "expiration",
	                // "info",
	                "surname",
	                "firstname",
	                // "patronymic",
	                "city",
	                // "street",
	                // "house",
	                // "building",
	                // "apartment",
	                "isnameeditable",
	                "isaddresseditable",
	                "isamounteditable"
	        );

	        $result = $this->token;

	        foreach ($api_method as $item)
	            $result .= ( isset($normalized_params[$item]) ) ? $normalized_params[$item] : '';

	        $hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

	        return $hash;
	    }

	    private function log_error_exception($name, $message, $e) {
	    	$this->log($name, "ERROR" , $message . '; EXCEPTION MESSAGE - ' . $e->getMessage() . '; EXCEPTION TRACE - ' . $e->getTraceAsString());
	    }

	    private function log_error($name, $message) {
	    	$this->log($name, "ERROR" , $message);
	    }

	    private function log_info($name, $message) {
	    	$this->log($name, "INFO" , $message);
	    }

	    private function log($name, $type, $message) {
			$log_url = wp_upload_dir();
			$log_url = $log_url['basedir'] . "/erip_expresspay";

			if(!file_exists($log_url)) {
				$is_created = mkdir($log_url, 0777);

				if(!$is_created)
					return;
			}

			$log_url .= '/express-pay-' . date('Y.m.d') . '.log';

			file_put_contents($log_url, $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';' . PHP_EOL, FILE_APPEND);
	    }
	}

    class WC_CARD_EXPRESSPAY extends WC_Payment_Gateway {
		private $plugin_dir;

		public function __construct() {
			$this->id = "expresspay_card";
            $this->method_title = __('Экспресс Платежи: Банковские карты');
            $this->method_description = __('Оплата по карте сервис «Экспресс Платежи»');
			$this->plugin_dir = plugin_dir_url(__FILE__);

			$this->init_form_fields();
			$this->init_settings();

			$this->title = __("Банковская карта", 'wc_card_expresspay');
            
            $this->token = $this->get_option('token');
            $this->is_use_signature = ( $this->get_option('sign_invoices') == 'yes' ) ? true : false;
			$this->secret_word = $this->get_option('secret_key');
			$this->is_use_signature_notify = ( $this->get_option('sign_notify') == 'yes' ) ? true : false;
			$this->secret_key_notify = $this->get_option('secret_key_notify');
			$this->session_timeout_secs = $this->get_option('session_timeout_secs');
			$this->message_success = $this->get_option('message_success');
			$this->message_fail = $this->get_option('message_fail');
			
			$this->url = ( $this->get_option('test_mode') != 'yes' ) ? $this->get_option('url_api') : $this->get_option('url_sandbox_api');
			$this->url .= "/v1/cardinvoices";
			$this->test_mode = ( $this->get_option('test_mode') == 'yes' ) ? 1 : 0;

			add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			add_action('woocommerce_api_wc_card_expresspay', array($this, 'check_ipn_response'));
		}

		public function admin_options() {
			?>
			<h3><?php _e('«Экспресс Платежи: Оплата по карте', 'wc_card_expresspay'); ?></h3>
            <div style="float: left; display: inline-block;">
                 <a target="_blank" href="https://express-pay.by"><img src="<?php echo $this->plugin_dir; ?>assets/images/erip_expresspay_big.png" width="270" height="91" alt="exspress-pay.by" title="express-pay.by"></a>
            </div>
            <div style="margin-left: 6px; margin-top: 15px; display: inline-block;">
				<?php _e('«Экспресс Платежи: Оплата по карте» - плагин для интеграции с сервисом «Экспресс Платежи» (express-pay.by) через API. 
				<br/>Плагин позволяет выставить счет для оплаты по карте, получить и обработать уведомление о платеже.
				<br/>Описание плагина доступно по адресу: ', 'wc_card_expresspay'); ?><a target="blank" href="https://express-pay.by/cms-extensions/wordpress#woocommerce_2_x">https://express-pay.by/cms-extensions/wordpress#woocommerce_2_x</a>
            </div>

			<table class="form-table">
				<?php		
					$this->generate_settings_html();
				?>
			</table>

			<div class="copyright" style="text-align: center;">
				<?php _e("© Все права защищены | ООО «ТриИнком»,", 'wc_card_expresspay'); ?> 2013-<?php echo date("Y"); ?><br/>
				<?php echo __('Версия', 'wc_card_expresspay') . " " . ERIP_EXPRESSPAY_VERSION ?>			
			</div>
			<?php
		}

		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title'   => __('Включить/Выключить', 'wc_card_expresspay'),
					'type'    => 'checkbox',
					'default' => 'no'
				),
				'token' => array(
					'title'   => __('Токен', 'wc_card_expresspay'),
					'type'    => 'text',
					'description' => __('Генерирутся в панели express-pay.by', 'wc_card_expresspay'),
					'desc_tip'    => true
				),
				'handler_url' => array(
					'title'   => __('Адрес для уведомлений', 'wc_card_expresspay'),
					'type'    => 'text',
					'css' => 'display: none;',
					'description' => get_site_url() . '/?wc-api=wc_erip_expresspay&action=notify'
				),
				'sign_invoices' => array(
					'title'   => __('Использовать цифровую подпись для API', 'wc_card_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Параметр проверки запросов с использование цифровой подписи', 'wc_card_expresspay'),
					'desc_tip'    => true
				),
				'secret_key' => array(
					'title'   => __('Секретное слово для подписи счетов', 'wc_card_expresspay'),
					'type'    => 'text',
					'description' => __('Секретного слово, которое известно только серверу и клиенту. Используется для формирования цифровой подписи. Задается в панели express-pay.by', 'wc_card_expresspay'),
					'desc_tip'    => true
				),
				'sign_notify' => array(
					'title'   => __('Использовать цифровую подпись для уведомлений', 'wc_card_expresspay'),
					'type'    => 'checkbox',
					'description' => __('Параметр проверки запросов с использование цифровой подписи', 'wc_card_expresspay'),
					'desc_tip'    => true
				),
				'secret_key_norify' => array(
					'title'   => __('Секретное слово для подписи уведомлений', 'wc_card_expresspay'),
					'type'    => 'text',
					'description' => __('Секретного слово, которое известно только серверу и клиенту. Используется для формирования цифровой подписи. Задается в панели express-pay.by', 'wc_card_expresspay'),
					'desc_tip'    => true
				),
				'session_timeout_secs' => array(
					'title'   => __('Продолжительность сессии', 'wc_card_expresspay'),
					'type'    => 'text',
					'description' => __('Временной промежуток указанный в секундах, за время которого клиент может совершить платеж (находится в промежутке от 600 секунд (10 минут) до 86400 секунд (1 сутки) ). По-умолчанию равен 1200 секунд (20 минут)', 'wc_card_expresspay'),
					'default' => '1200',
                    'desc_tip'    => true
				),
				'test_mode' => array(
					'title'   => __('Использовать тестовый режим', 'wc_card_expresspay'),
					'type'    => 'checkbox'
				),
				'url_api' => array(
					'title'   => __('Адрес API', 'wc_card_expresspay'),
					'type'    => 'text',
					'default' => 'https://api.express-pay.by'
				),
				'url_sandbox_api' => array(
					'title'   => __('Адрес тестового API', 'wc_card_expresspay'),
					'type'    => 'text',
					'default' => 'https://sandbox-api.express-pay.by'
				),
				'message_success' => array(
					'title'   => __('Сообщение при успешном заказе', 'wc_card_expresspay'),
					'type'    => 'textarea',
					'default' => __('Заказ номер "##order_id##" успешно оплачен. Нажмите "продолжить".', 'wc_card_expresspay'),
					'css'	  => 'min-height: 160px;'
				),
                'message_fail' => array(
					'title'   => __('Сообщение при ошибке заказа', 'wc_card_expresspay'),
					'type'    => 'textarea',
					'default' => __('При выполнении запроса произошла непредвиденная ошибка. Пожалуйста, повторите запрос позже или обратитесь в службу технической поддержки магазина', 'wc_card_expresspay'),
					'css'	  => 'min-height: 160px;'
				)
			);
		}

		function process_payment($order_id) {
            global $woocommerce;
        
            $this->log_info('process_payment', 'Initialization request for add invoice');
			$order = new WC_Order($order_id);	
            
            $add_url = $this->url . '?token=' . $this->token;
            
            $price = preg_replace('#[^\d.]#', '', $order->order_total);
			$price = str_replace('.', ',', $order->order_total);
            
            $currency = (date('y') > 16 || (date('y') >= 16 && date('n') >= 7)) ? '933' : '974';

	        $request_params = array(
                //"Token" => $this->token,
                "AccountNo" => $order_id,
                "Amount" => $price,
                "Currency" => $currency,
                "Info" => "Покупка в магазине " . get_site_url(),
                "ReturnUrl" => add_query_arg('status', 'success', add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))),
                "FailUrl" => add_query_arg('status', 'fail', add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))),
                "SessionTimeoutSecs" => intval($this->session_timeout_secs)
            );
            
            if($this->is_use_signature)
        		$add_url .= "&signature=" . $this->compute_signature_add_invoice($request_params, $this->secret_word);

    		$request_params = http_build_query($request_params);
            
            $this->log_info('process_payment', 'Send POST request; ORDER ID - ' . $order_id . '; URL - ' . $add_url . '; REQUEST - ' . $request_params);
            
            $response = "";
            
            try {
		        $ch = curl_init();
		        curl_setopt($ch, CURLOPT_URL, $add_url);
		        curl_setopt($ch, CURLOPT_POST, 1);
		        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_params);
		        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		        $response = curl_exec($ch);
		        curl_close($ch);
	    	} catch (Exception $e) {
				$this->log_error_exception('process_payment', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);

	    		return array(
                    'result'   => 'failure',
                    'messages' => $e
                );
	    	}
            
            $this->log_info('receipt_page', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response);
            
            try {
	        	$response = json_decode($response);
	    	} catch (Exception $e) {
	    		$this->log_error_exception('process_payment', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);

	    		return array(
                    'result'   => 'failure',
                    'messages' => $e
                );
	    	}

	        if(isset($response->Error['Code'])){
	        	return array(
                    'result'   => 'failure',
                    'messages' => $response->Error['Message']
                );
            }
            
            $request_params = array(
                "Token" => $this->token,
                "CardInvoiceNo" => $response->CardInvoiceNo
            );
            
            $form_url = $this->url . '/' . $response->CardInvoiceNo . '/payment?token=' . $this->token;
            if($this->is_use_signature)
        		$form_url .= "&signature=" . $this->compute_signature_get_form_url($request_params, $this->secret_word);
            
            //sleep(15); // задержка синхронизации 15 секунд
            
            $this->log_info('process_payment', 'Send GET request; ORDER ID - ' . $order_id . '; URL - ' . $form_url . '; REQUEST - ' . $response->CardInvoiceNo);
            
            $response = '';
            
            try {
		        $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $form_url);
                curl_setopt($ch, CURLOPT_POST, 0);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                curl_close($ch);
	    	} catch (Exception $e) {
				$this->log_error_exception('process_payment', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);

	    		return array(
                    'result'   => 'failure',
                    'messages' => $e
                );
	    	}
            $this->log_info('receipt_page', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response);
            try {
	        	$response = json_decode($response);
	    	} catch (Exception $e) {
	    		$this->log_error_exception('process_payment', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);

	    		return array(
                    'result'   => 'failure',
                    'messages' => $e
                );
	    	}
            
            if(isset($response->Error['Code'])){
	        	return array(
                    'result'   => 'failure',
                    'messages' => $response->Error['Message']
                );
            }
            $returnUrl = str_replace("https://192.168.10.95","https://192.168.10.95:9090",$response->FormUrl);
			return array(
				'result' => 'success',
				'redirect'	=> $returnUrl
			);
		}

		function receipt_page($order_id) {
			$this->log_info('receipt_page', 'Initialization request for add invoice');
			$order = new WC_Order($order_id);
            if($_GET["status"]==="success"){
                $this->success($order);
            }else{
                $this->fail($order);
            }
		}

		private function success($order) {
			global $woocommerce;

			$this->log_info('receipt_page', 'End request for add invoice');
			$this->log_info('success', 'Initialization render success page; ORDER ID - ' . $order->get_order_number());

			$woocommerce->cart->empty_cart();

			$order->update_status('processing', __('Счет успешно оплачен', 'wc_card_expresspay'));

			echo '<h2>' . __('Счет успешно оплачен', 'wc_card_expresspay') . '</h2>';
			echo str_replace("##order_id##", $order->get_order_number(), nl2br($this->message_success, true));

			echo '<br/><br/><p class="return-to-shop"><a class="button wc-backward" href="' . get_permalink( woocommerce_get_page_id( "shop" ) ) . '">' . __('Продолжить', 'wc_card_expresspay') . '</a></p>';

			$signature_success = $signature_cancel = "";

			if($this->is_use_signature_notify) {
				$signature_success = $this->compute_signature('{"CmdType": 1, "AccountNo": ' . $order->get_order_number() . '}', $this->secret_key_notify);
				$signature_cancel = $this->compute_signature('{"CmdType": 2, "AccountNo": ' . $order->get_order_number() . '}', $this->secret_key_notify);
			}

			if($this->test_mode) : ?>
				<div class="test_mode">
			        <?php _e('Тестовый режим:', 'wc_erip_expresspay'); ?> <br/>
    				<input type="button" style="margin: 6px 0;" class="button" id="send_notify_success" value="<?php _e('Отправить уведомление об успешной оплате', 'wc_card_expresspay'); ?>" />
			        <input type="button" class="button" style="margin: 6px 0;" id="send_notify_cancel" class="btn btn-primary" value="<?php _e('Отправить уведомление об отмене оплаты', 'wc_card_expresspay'); ?>" />

				      <script type="text/javascript">
				        jQuery(document).ready(function() {
				          jQuery('#send_notify_success').click(function() {
				            send_notify(1, '<?php echo $signature_success; ?>');
				          });

				          jQuery('#send_notify_cancel').click(function() {
				            send_notify(2, '<?php echo $signature_cancel; ?>');
				          });

				          function send_notify(type, signature) {
				            jQuery.post('<?php echo get_site_url() . "/?wc-api=wc_erip_expresspay&action=notify" ?>', 'Data={"CmdType": ' + type + ', "AccountNo": <?php echo $order->get_order_number(); ?>}&Signature=' + signature, function(data) {alert(data);})
				            .fail(function(data) {
				              alert(data.responseText);
				            });
				          }
				        });
				      </script>

		     	</div>
			<?php
			endif;

			$this->log_info('success', 'End render success page; ORDER ID - ' . $order->get_order_number());
		}

		private function fail($order) {
			global $woocommerce;

			$this->log_info('receipt_page', 'End request for add invoice');
			$this->log_info('fail', 'Initialization render fail page; ORDER ID - ' . $order->get_order_number());

			$order->update_status('failed', __('Платеж не оплачен', 'wc_card_expresspay'));

			echo '<h2>' . __('Ошибка оплаты заказа по банковской карте', 'wc_card_expresspay') . '</h2>';
			echo str_replace("##order_id##", $order->get_order_number(), nl2br($this->message_fail, true));

			echo '<br/><br/><p class="return-to-shop"><a class="button wc-backward" href="' . $woocommerce->cart->get_checkout_url() . '">' . __('Попробовать заново', 'wc_card_expresspay') . '</a></p>';

			$this->log_info('fail', 'End render fail page; ORDER ID - ' . $order->get_order_number());

			//die();
		}

		function check_ipn_response() {
			$this->log_info('check_ipn_response', 'Get notify from server; REQUEST METHOD - ' . $_SERVER['REQUEST_METHOD']);

			if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['action']) && $_REQUEST['action'] == 'notify') {
				$data = ( isset($_REQUEST['Data']) ) ? htmlspecialchars_decode($_REQUEST['Data']) : '';
				$data = stripcslashes($data);
				$signature = ( isset($_REQUEST['Signature']) ) ? $_REQUEST['Signature'] : '';

			    if($this->is_use_signature_notify) {
			    	if($signature == $this->compute_signature($data, $this->secret_key_notify))
				        $this->notify_success($data);
				    else  
				    	$this->notify_fail($data);
			    } else 
			    	$this->notify_success($data);
			}

			$this->log_info('check_ipn_response', 'End (Get notify from server); REQUEST METHOD - ' . $_SERVER['REQUEST_METHOD']);

			die();
		}

		private function notify_success($dataJSON) {
			global $woocommerce;

			try {
	        	$data = json_decode($dataJSON);
	    	} catch(Exception $e) {
				$this->log_error('notify_success', "Fail to parse the server response; RESPONSE - " . $dataJSON);

	    		$this->notify_fail($dataJSON);
	    	}

            $order = new WC_Order($data->AccountNo);

	        if(isset($data->CmdType)) {
	        	switch ($data->CmdType) {
	        		case '1':
	                    $order->update_status('completed', __('Счет успешно оплачен', 'wc_card_expresspay'));
	                    $this->log_info('notify_success', 'Initialization to update status. STATUS ID - Счет успешно оплачен; RESPONSE - ' . $dataJSON);

	        			break;
	        		case '2':
						$order->update_status('cancelled', __('Платеж отменён', 'wc_card_expresspay'));
						$this->log_info('notify_success', 'Initialization to update status. STATUS ID - Платеж отменён; RESPONSE - '. $dataJSON);

	        			break;
	        		default:
						$this->notify_fail($dataJSON);
						die();
	        	}

		    	header("HTTP/1.0 200 OK");
		    	echo 'SUCCESS';
	        } else
				$this->notify_fail($dataJSON);	
		}

		private function notify_fail($dataJSON) {
			$this->log_error('notify_fail', "Fail to update status; RESPONSE - " . $dataJSON);
			
			header("HTTP/1.0 400 Bad Request");
			echo 'FAILED | Incorrect digital signature';
		}

		private function compute_signature($json, $secret_word) {
		    $hash = NULL;
		    $secret_word = trim($secret_word);
		    
		    if(empty($secret_word))
				$hash = strtoupper(hash_hmac('sha1', $json, ""));
		    else
		        $hash = strtoupper(hash_hmac('sha1', $json, $secret_word));

		    return $hash;
		}	

	    private function compute_signature_add_invoice($request_params, $secret_word) {
	    	$secret_word = trim($secret_word);
	        $normalized_params = array_change_key_case($request_params, CASE_LOWER);
	        $api_method = array(
	                "token",
                    "accountno",                 
                    "expiration",             
                    "amount",                  
                    "currency",
                    "info",      
                    "returnurl",
                    "failurl",
                    "language",
                    "pageview",
                    "sessiontimeoutsecs",
                    "expirationdate"
	        );

	        $result = $this->token;

	        foreach ($api_method as $item)
	            $result .= ( isset($normalized_params[$item]) ) ? $normalized_params[$item] : '';

	        $hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

	        return $hash;
	    }
        
        private function compute_signature_get_form_url($request_params, $secret_word) {
	    	$secret_word = trim($secret_word);
            $normalized_params = array_change_key_case($request_params, CASE_LOWER);
	        $api_method = array(
	                "token",
                    "cardinvoiceno"
	        );

	        $result = "";

	        foreach ($api_method as $item)
	            $result .= ( isset($normalized_params[$item]) ) ? $normalized_params[$item] : '';

	        $hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

	        return $hash;
	    }

	    private function log_error_exception($name, $message, $e) {
	    	$this->log($name, "ERROR" , $message . '; EXCEPTION MESSAGE - ' . $e->getMessage() . '; EXCEPTION TRACE - ' . $e->getTraceAsString());
	    }

	    private function log_error($name, $message) {
	    	$this->log($name, "ERROR" , $message);
	    }

	    private function log_info($name, $message) {
	    	$this->log($name, "INFO" , $message);
	    }

	    private function log($name, $type, $message) {
			$log_url = wp_upload_dir();
			$log_url = $log_url['basedir'] . "/erip_expresspay";

			if(!file_exists($log_url)) {
				$is_created = mkdir($log_url, 0777);

				if(!$is_created)
					return;
			}

			$log_url .= '/express-pay-' . date('Y.m.d') . '.log';

			file_put_contents($log_url, $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';' . PHP_EOL, FILE_APPEND);
	    }
	}
}

?>