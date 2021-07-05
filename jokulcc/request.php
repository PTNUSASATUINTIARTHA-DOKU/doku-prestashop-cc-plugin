<?php

require_once(dirname(__FILE__) . '/../../config/config.inc.php');
require_once(dirname(__FILE__) . '/../../init.php');
require_once(dirname(__FILE__) . '/jokulcc.php');

$jokulcc = new JokulCc();

$task = $_GET['task'];

$json_data_input = json_decode(file_get_contents('php://input'), true);

switch ($task) {
	case "notify":
		if (empty($json_data_input)) {
			http_response_code(404);
			die;
		} else {
			$jokulcc->doku_log($jokulcc, " CREDIT CARD NOTIF RAW POST DATA ".json_encode($json_data_input), $json_data_input['order']['invoice_number'], '../../');
			$trx = array();
			$config = $jokulcc->getServerConfig();
			
			$order_id_ref = $jokulcc->get_order_id_jokul($json_data_input['order']['invoice_number']);
			$order_id = $jokulcc->get_order_id($order_id_ref);
			$notifSuccess = true;

			if (!$order_id) {
				$notifSuccess = false;
			}

			$headers = getallheaders();
			$signature = generateSignature($headers, $jokulcc->getKey());
			
			if ($headers['Signature'] == $signature) {
				$jokulcc->doku_log($jokulcc, " VIRTUAL ACCOUNT NOTIF SIGNATURE SUCCESS ".$signature, $json_data_input['order']['invoice_number'], '../../');
				$trx['raw_post_data']         = file_get_contents('php://input');
				$trx['amount']                = $json_data_input['order']['amount'];
				$trx['invoice_number']        = $json_data_input['order']['invoice_number'];
				$rawPost       				  = file_get_contents('php://input');
				$dateTime      				  = gmdate("Y-m-d H:i:s");

				$result = $jokulcc->checkTrxNotify($trx);

				if ($result < 1) {
					http_response_code(404);
				} else {
					if (strtolower($json_data_input['transaction']['status']) == strtolower('SUCCESS')) {
						$trx['message'] = "Jokul Credit Card";
						$status         = "completed";
						$status_no      = $config['DOKU_CC_PAYMENT_RECEIVED'];
						$jokulcc->emptybag();

						if ($notifSuccess == true) {
							$jokulcc->set_order_status($order_id, $status_no);
							$jokulcc->update_notify($order_id_ref, $rawPost, $dateTime, '0', '0');
						} else {
							$jokulcc->update_notify($order_id_ref, $rawPost, $dateTime, '1', '0');
						}
					} else {
						$trx['message'] = "Jokul Credit Card";
						$status         = "completed";
						$status_no      = $config['DOKU_CC_FAILED_PAYMENT'];

						if ($notifSuccess == true) {
							$jokulcc->set_order_status($order_id, $status_no);
							$jokulcc->update_notify($order_id_ref, $rawPost, $dateTime, '0', '0');
						} else {
							http_response_code(404);
							$jokulcc->update_notify($order_id_ref, $rawPost, $dateTime, '1', '0');
						}
					}
				}
			} else {
				$jokulcc->doku_log($jokulcc, " VIRTUAL ACCOUNT NOTIF SIGNATURE FAILED ".$signature, $json_data_input['order']['invoice_number'], '../../');
				http_response_code(400);
			}
		}
		break;
	default:
		echo "Stop : Access Not Valid";
		die;
		break;
}

function generateSignature($headers, $secret)
{
	$digest = base64_encode(hash('sha256', file_get_contents('php://input'), true));
	$rawSignature = "Client-Id:" . $headers['Client-Id'] . "\n"
		. "Request-Id:" . $headers['Request-Id'] . "\n"
		. "Request-Timestamp:" . $headers['Request-Timestamp'] . "\n"
		. "Request-Target:" . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) . "\n"
		. "Digest:" . $digest;

	$signature = base64_encode(hash_hmac('sha256', $rawSignature, $secret, true));
	return 'HMACSHA256=' . $signature;
}