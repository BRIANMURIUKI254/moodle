<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig && isset($ADMIN) && $ADMIN->fulltree && isset($settings) && $settings) {
    $settings->add(new admin_setting_configtextarea(
        'local_btiportal/inactivealertadminemails',
        get_string('inactivealertadminemails', 'local_btiportal'),
        get_string('inactivealertadminemails_desc', 'local_btiportal'),
        '',
        PARAM_RAW_TRIMMED
    ));
}
