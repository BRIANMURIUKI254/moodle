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

    /**
     * Ensure every Moodle Site Administrator can sign in through login/token.php
     * against bti_portal_api using their existing Moodle username and password.
     *
     * Moodle core will not auto-create a web-service token for site admins
     * (errorcode cannotcreatetoken). If a valid permanent token already exists,
     * login/token.php returns it after validating credentials. This method only
     * creates that missing token on the Moodle side — it does not expose the
     * token or the administrator password.
     *
     * @return list<array{
     *     userid:int,
     *     username:string,
     *     authorised:bool,
     *     authorisation_created:bool,
     *     token_existed:bool,
     *     token_created:bool,
     *     error:string
     * }>
     */
    public static function ensure_site_admins_can_login(): array {
        global $DB;

        $service = $DB->get_record(
            'external_services',
            ['shortname' => self::PORTAL_SERVICE_SHORTNAME],
            '*',
            IGNORE_MISSING
        );
        if (!$service) {
            return [];
        }

        $results = [];
        foreach (get_admins() as $admin) {
            $userid = (int) $admin->id;
            if ($userid <= 0 || !empty($admin->deleted) || !empty($admin->suspended)) {
                continue;
            }

            $row = [
                'userid' => $userid,
                'username' => (string) $admin->username,
                'authorised' => false,
                'authorisation_created' => false,
                'token_existed' => false,
                'token_created' => false,
                'error' => '',
            ];

            try {
                $auth = self::ensure_portal_authorised($userid);
                $row['authorised'] = !empty($auth['authorised']);
                $row['authorisation_created'] = !empty($auth['created']);

                if (self::site_admin_has_valid_portal_token($userid, (int) $service->id)) {
                    $row['token_existed'] = true;
                    $results[] = $row;
                    continue;
                }

                \core_external\util::generate_token(
                    EXTERNAL_TOKEN_PERMANENT,
                    $service,
                    $userid,
                    \context_system::instance(),
                    0,
                    '',
                    'BTI Portal API (site administrator)'
                );
                $row['token_created'] = true;
            } catch (\Throwable $e) {
                $row['error'] = $e->getMessage();
            }

            $results[] = $row;
        }

        return $results;
    }

    /**
     * True when a non-expired permanent bti_portal_api token exists for the user.
     */
    public static function site_admin_has_valid_portal_token(int $userid, int $serviceid): bool {
        global $DB;

        $tokens = $DB->get_records('external_tokens', [
            'userid' => $userid,
            'externalserviceid' => $serviceid,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);
        $now = time();
        foreach ($tokens as $token) {
            if (!empty($token->sid) && !\core\session\manager::session_exists($token->sid)) {
                continue;
            }
            if (!empty($token->validuntil) && (int) $token->validuntil < $now) {
                continue;
            }
            if (!empty($token->token)) {
                return true;
            }
        }
        return false;
    }
}
