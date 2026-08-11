<?php
namespace local_btiportal;

defined('MOODLE_INTERNAL') || die();

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Standard envelope for new academic web services.
 *
 * Existing portal WS keep flat domain returns; only academic APIs use this shape.
 */
class academic_api_response {
    /**
     * @param array|null $data
     * @param list<array{field:string,message:string}> $errors
     * @return array{success:bool,data:?array,errors:list<array{field:string,message:string}>}
     */
    public static function ok(?array $data = null): array {
        return [
            'success' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * @param list<array{field:string,message:string}> $errors
     * @return array{success:bool,data:null,errors:list<array{field:string,message:string}>}
     */
    public static function fail(array $errors): array {
        return [
            'success' => false,
            'data' => null,
            'errors' => array_values($errors),
        ];
    }

    /**
     * @param string $field
     * @param string $message
     * @return array{success:bool,data:null,errors:list<array{field:string,message:string}>}
     */
    public static function fail_field(string $field, string $message): array {
        return self::fail([['field' => $field, 'message' => $message]]);
    }

    /**
     * Moodle external return structure. $datakeys describes fields inside data when present.
     *
     * List endpoints should put rows under data['items'] (and optional data['count']).
     */
    public static function returns_structure(array $datakeys = []): external_single_structure {
        $datafields = $datakeys;
        if (empty($datafields)) {
            // Opaque placeholder so execute_returns stays valid when data is null.
            $datafields = [
                'placeholder' => new external_value(PARAM_RAW, 'Unused', VALUE_OPTIONAL),
            ];
        }

        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True when the operation succeeded'),
            'data' => new external_single_structure($datafields, 'Payload', VALUE_OPTIONAL),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name or empty for general errors'),
                    'message' => new external_value(PARAM_TEXT, 'Human-readable error'),
                ]),
                'Validation or business-rule errors'
            ),
        ]);
    }

    /**
     * Ensure Moodle WS encoding accepts null data by omitting the key when null.
     *
     * @param array{success:bool,data:?array,errors:list} $envelope
     * @return array
     */
    public static function for_external(array $envelope): array {
        $out = [
            'success' => (bool)$envelope['success'],
            'errors' => $envelope['errors'] ?? [],
        ];
        if (array_key_exists('data', $envelope) && $envelope['data'] !== null) {
            $out['data'] = $envelope['data'];
        }
        return $out;
    }
}
