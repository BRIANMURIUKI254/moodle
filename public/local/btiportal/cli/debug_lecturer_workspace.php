<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');

$userid = isset($argv[1]) ? (int)$argv[1] : 27;
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
\core\session\manager::set_user($user);

echo "User {$user->id} {$user->username}\n";

try {
    $result = \local_btiportal\external\get_my_lecturer_workspace::execute(0, 0, true);
    echo "OK success=" . (!empty($result['success']) ? '1' : '0') . "\n";
    if (!empty($result['errors'])) {
        echo "Errors:\n";
        print_r($result['errors']);
    }
    $data = $result['data'] ?? null;
    if (is_array($data)) {
        echo 'courseunits=' . count($data['courseunits'] ?? []) . "\n";
        echo 'moodlecourses=' . count($data['moodlecourses'] ?? []) . "\n";
        echo 'departments=' . count($data['departments'] ?? []) . "\n";
        echo 'studenttotal=' . ($data['studenttotal'] ?? 0) . "\n";
    }
} catch (Throwable $e) {
    echo 'EX: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
