<?php
define('CLI_SCRIPT', true);
require '/var/www/html/config.php';
global $DB;
echo 'installed=' . $DB->get_field('config_plugins', 'value', [
    'plugin' => 'local_btiportal',
    'name' => 'version',
]) . PHP_EOL;
echo 'source_file_exists=' . (int)file_exists('/var/www/html/public/local/btiportal/version.php') . PHP_EOL;
$v = file_get_contents('/var/www/html/public/local/btiportal/version.php');
if (preg_match('/\$plugin->version\s*=\s*(\d+)/', $v, $m)) {
    echo 'source_version=' . $m[1] . PHP_EOL;
}
if (preg_match("/\\\$plugin->release\s*=\s*'([^']+)'/", $v, $m)) {
    echo 'source_release=' . $m[1] . PHP_EOL;
}
