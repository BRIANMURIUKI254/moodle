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
    [
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback'  => '\local_btiportal\quiz_reopen_helper::attempt_graded',
    ],
    [
        'eventname' => '\mod_quiz\event\question_manually_graded',
        'callback'  => '\local_btiportal\quiz_reopen_helper::question_manually_graded',
    ],
];
