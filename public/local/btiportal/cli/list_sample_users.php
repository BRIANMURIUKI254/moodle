<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
$users = $DB->get_records_select('user', 'deleted = 0 AND id > 1', [], 'id ASC', 'id,username', 0, 5);
foreach ($users as $u) {
    echo "USER id={$u->id} username={$u->username}\n";
}
