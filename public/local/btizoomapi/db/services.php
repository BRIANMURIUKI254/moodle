<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_btizoomapi_get_zoom_link' => [
        'classname'   => 'local_btizoomapi\\external\\get_zoom_link',
        'methodname'  => 'execute',
        'description' => 'Return the join URL for a Zoom meeting activity in a Moodle course.',
        'type'        => 'read',
        'ajax'        => true,
    ],
];

$services = [
    'BTI Zoom API' => [
        'functions' => [
            'local_btizoomapi_get_zoom_link',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'btizoomapi_local',
    ],
];
