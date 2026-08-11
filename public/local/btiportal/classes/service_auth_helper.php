<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Authorise Moodle users for the BTI Portal API web service.
 */
class service_auth_helper {
    public const PORTAL_SERVICE_SHORTNAME = 'bti_portal_api';

    /**
     * Ensure a user is on the Authorised users list for bti_portal_api
     * when that service has restrictedusers enabled.
     *
     * @return array{userid:int, serviceid:int, shortname:string, authorised:bool, created:bool, restricted:bool}
     */
    public static function ensure_portal_authorised(int $userid): array {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $service = $DB->get_record('external_services', ['shortname' => self::PORTAL_SERVICE_SHORTNAME], '*', MUST_EXIST);

        $restricted = (int) $service->restrictedusers === 1;
        if (!$restricted) {
            return [
                'userid' => (int) $user->id,
                'serviceid' => (int) $service->id,
                'shortname' => $service->shortname,
                'authorised' => true,
                'created' => false,
                'restricted' => false,
            ];
        }

        $exists = $DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]);

        if ($exists) {
            return [
                'userid' => (int) $user->id,
                'serviceid' => (int) $service->id,
                'shortname' => $service->shortname,
                'authorised' => true,
                'created' => false,
                'restricted' => true,
            ];
        }

        $webservicemanager = new \webservice();
        $serviceuser = (object) [
            'externalserviceid' => (int) $service->id,
            'userid' => (int) $user->id,
        ];
        $webservicemanager->add_ws_authorised_user($serviceuser);

        return [
            'userid' => (int) $user->id,
            'serviceid' => (int) $service->id,
            'shortname' => $service->shortname,
            'authorised' => true,
            'created' => true,
            'restricted' => true,
        ];
    }
}
