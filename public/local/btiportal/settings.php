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
    $settings->add(new admin_setting_configtext(
        'local_btiportal/portalurl',
        get_string('portalurl', 'local_btiportal'),
        get_string('portalurl_desc', 'local_btiportal'),
        'https://lms.bticollege.ac.ke',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_btiportal/feesgate_enabled',
        get_string('feesgate_enabled', 'local_btiportal'),
        get_string('feesgate_enabled_desc', 'local_btiportal'),
        0
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_btiportal/paystack_secret_key',
        get_string('paystack_secret_key', 'local_btiportal'),
        get_string('paystack_secret_key_desc', 'local_btiportal'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_btiportal/paystack_public_key',
        get_string('paystack_public_key', 'local_btiportal'),
        get_string('paystack_public_key_desc', 'local_btiportal'),
        '',
        PARAM_RAW_TRIMMED
    ));
    $settings->add(new admin_setting_configtext(
        'local_btiportal/admissions_fees_callback_url',
        get_string('admissions_fees_callback_url', 'local_btiportal'),
        get_string('admissions_fees_callback_url_desc', 'local_btiportal'),
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_btiportal/admissions_fees_callback_secret',
        get_string('admissions_fees_callback_secret', 'local_btiportal'),
        get_string('admissions_fees_callback_secret_desc', 'local_btiportal'),
        ''
    ));
}
