<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_btiportal\task\inactive_student_alerts',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '2',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => 'local_btiportal\task\sync_student_semesters',
        'blocking' => 0,
        'minute' => '*/30',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
