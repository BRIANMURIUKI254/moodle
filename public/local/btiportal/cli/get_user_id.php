<?php
define('CLI_SCRIPT', true);
require __DIR__ . '/../../../config.php';
$username = $argv[1] ?? '';
echo (int)$DB->get_field('user', 'id', ['username' => $username, 'deleted' => 0]);