<?php
/**
 * Set Admissions CMS fees callback URL + HMAC secret (production bootstrap).
 *
 * Prefer env on the server:
 *   BTI_ADMISSIONS_FEES_CALLBACK_URL
 *   BTI_ADMISSIONS_FEES_CALLBACK_SECRET
 *
 * Usage:
 *   php configure_admissions_fees_callback.php --url=https://api.bti.ac.ke/applications/fees-callback --secret=...
 *   php configure_admissions_fees_callback.php --show
 */
define('CLI_SCRIPT', true);

require __DIR__ . '/../../../config.php';
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'url' => '',
        'secret' => '',
        'show' => false,
    ],
    ['h' => 'help']
);

if ($options['help'] || $unrecognized) {
    echo "Options:\n";
    echo "  --url=     Callback URL (PARAM_URL)\n";
    echo "  --secret=  HMAC secret (stored in plugin config, not echoed with --show)\n";
    echo "  --show     Print current url and whether a secret is set\n";
    exit(0);
}

if ($options['show']) {
    $url = get_config('local_btiportal', 'admissions_fees_callback_url');
    $secret = get_config('local_btiportal', 'admissions_fees_callback_secret');
    cli_writeln('admissions_fees_callback_url=' . (string) $url);
    cli_writeln('admissions_fees_callback_secret=' . ($secret !== false && (string) $secret !== '' ? '[set]' : '[empty]'));
    $gate = get_config('local_btiportal', 'feesgate_enabled');
    cli_writeln('feesgate_enabled=' . ($gate ? '1' : '0'));
    exit(0);
}

$url = trim($options['url']);
$secret = trim($options['secret']);
if ($url !== '') {
    set_config('admissions_fees_callback_url', $url, 'local_btiportal');
    cli_writeln('Saved admissions_fees_callback_url');
}
if ($secret !== '') {
    set_config('admissions_fees_callback_secret', $secret, 'local_btiportal');
    cli_writeln('Saved admissions_fees_callback_secret');
}
if ($url === '' && $secret === '') {
    cli_error('Provide --url and/or --secret, or use --show');
}