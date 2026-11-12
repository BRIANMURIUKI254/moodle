<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

/**
 * Live plugin identity so the portal can tell which local_btiportal is on the server.
 */
class plugin_build_helper {
    /**
     * @return array{pluginversion:string,pluginrelease:string,gitsha:string}
     */
    public static function marker(): array {
        global $CFG;
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $plugin = new \stdClass();
        include $CFG->dirroot . '/local/btiportal/version.php';
        $sha = trim((string)get_config('local_btiportal', 'buildsha'));
        if ($sha === '') {
            $sha = self::read_git_sha();
            set_config('buildsha', $sha, 'local_btiportal');
        }

        $cached = [
            'pluginversion' => (string)((int)($plugin->version ?? 0)),
            'pluginrelease' => (string)($plugin->release ?? ''),
            'gitsha' => $sha !== '' ? $sha : 'unknown',
        ];
        return $cached;
    }

    public static function refresh_git_sha(): string {
        $sha = self::read_git_sha();
        if ($sha === '') {
            $sha = 'unknown';
        }
        set_config('buildsha', $sha, 'local_btiportal');
        return $sha;
    }

    public static function read_git_sha(): string {
        global $CFG;
        $heads = [
            dirname($CFG->dirroot) . '/.git/HEAD',
            $CFG->dirroot . '/.git/HEAD',
        ];
        foreach ($heads as $head) {
            if (!is_readable($head)) {
                continue;
            }
            $content = trim((string)file_get_contents($head));
            if (str_starts_with($content, 'ref:')) {
                $ref = trim(substr($content, 4));
                $refpath = dirname($head) . '/' . $ref;
                if (is_readable($refpath)) {
                    return substr(trim((string)file_get_contents($refpath)), 0, 12);
                }
            } else if (preg_match('/^[0-9a-f]{7,40}$/i', $content)) {
                return substr($content, 0, 12);
            }
        }
        return 'unknown';
    }
}
