<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_btizoomapi_get_zoom_link' => [
        'classname'   => 'local_btizoomapi\\external\\get_zoom_link',
        'methodname'  => 'execute',
        'description' => 'Return the join URL for a Zoom meeting activity in a Moodle course / course-unit section.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'local_btizoomapi_ensure_zoom_link' => [
        'classname'   => 'local_btizoomapi\\external\\ensure_zoom_link',
        'methodname'  => 'execute',
        'description' => 'Find or create a Zoom meeting in a Moodle course-unit section and return its join URL.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];

$services = [
    'BTI Zoom API' => [
        'functions' => [
            'local_btizoomapi_get_zoom_link',
            'local_btizoomapi_ensure_zoom_link',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'btizoomapi_local',
    ],
];
