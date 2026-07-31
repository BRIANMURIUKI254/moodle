<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_created',
        'callback'  => '\local_btiportal\observer::course_created',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\local_btiportal\observer::course_deleted',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_btiportal\observer::course_module_deleted',
    ],
];
