<?php
// Public Paystack webhook (no Moodle login). Configure URL in Paystack dashboard.
require_once(__DIR__ . '/../../config.php');

use local_btiportal\fees_payment_helper;

$raw = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null;

$result = fees_payment_helper::process_webhook_payload((string)$raw, $signature);
$http = (int)($result['http'] ?? ($result['ok'] ? 200 : 400));
http_response_code($http);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => $result['ok'] ? 'ok' : 'error', 'message' => $result['message'] ?? '']);