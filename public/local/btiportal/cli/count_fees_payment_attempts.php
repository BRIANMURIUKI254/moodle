<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
echo (int)$DB->count_records('local_btiportal_fees_payment_attempt');