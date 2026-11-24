<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Ensure local_btiportal WS functions exist in {external_functions} and on portal services.
 */
class webservice_register_helper {

    /** @var list<string> Semester fees + cards WS added in 1.10.18+. */
    public const SEMESTER_FEES_FUNCTIONS = [
        'local_btiportal_get_my_fees_checkout',
        'local_btiportal_list_my_cards',
        'local_btiportal_remove_my_card',
        'local_btiportal_pay_with_saved_card',
        'local_btiportal_list_admin_semester_fees',
        'local_btiportal_upsert_admin_semester_fee',
        'local_btiportal_publish_semester_fees',
    ];

    /**
     * Sync services.php definitions into the DB and attach functions to portal services.
     *
     * @return array{registered:int,linked:int,missing:list<string>}
     */
    public static function ensure_portal_functions(?array $functionnames = null): array {
        global $DB;

        external_update_descriptions('local_btiportal');

        $names = $functionnames ?? self::SEMESTER_FEES_FUNCTIONS;
        $missing = [];
        $linked = 0;

        foreach ($names as $fn) {
            if (!$DB->record_exists('external_functions', ['name' => $fn])) {
                $missing[] = $fn;
                continue;
            }
            foreach (['bti_portal_api', 'btiportal_local'] as $shortname) {
                $service = $DB->get_record('external_services', ['shortname' => $shortname], '*', IGNORE_MISSING);
                if (!$service) {
                    continue;
                }
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $service->id,
                        'functionname' => $fn,
                    ]);
                    $linked++;
                }
            }
        }

        return [
            'registered' => count($names) - count($missing),
            'linked' => $linked,
            'missing' => $missing,
        ];
    }
}