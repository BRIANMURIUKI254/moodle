<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
$fields = $DB->get_records('user_info_field');
echo "custom profile fields: " . count($fields) . "\n";
foreach ($fields as $f) {
    echo "{$f->shortname} | {$f->name} | {$f->datatype}\n";
}
$cols = $DB->get_columns('user');
foreach (['username','idnumber','firstname','lastname','email','phone1','phone2','address','city','country','department','institution','description','lang','timezone'] as $c) {
    echo (isset($cols[$c]) ? 'Y' : 'N') . " $c\n";
}
// Username validation: can admission BTI/2026/00001 work?
require_once($CFG->dirroot . '/user/lib.php');
echo "username pattern check for BTI/2026/00001: ";
$clean = core_user::clean_field('BTI/2026/00001', 'username');
echo "clean_field=>$clean\n";
