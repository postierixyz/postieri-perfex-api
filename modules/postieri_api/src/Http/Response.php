<?php

namespace Perfexcrm\Postieri\Api\Http;

use CI_Controller;

/**
 * Standardised JSON response envelope.
 *
 *   { "status": true,  "data": ..., "meta": {...} }            // success
 *   { "status": false, "error": { "code", "message", "details" } }  // error
 */
final class Response
{
    /**
     * 200 OK with optional data + meta.
     */
    public static function ok(CI_Controller $CI, mixed $data = null, array $meta = []): void
    {
        $CI->output
            ->set_status_header(200)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(self::encode(['status' => true, 'data' => $data, 'meta' => (object) $meta]));
    }

    /**
     * 201 Created with the created resource.
     */
    public static function created(CI_Controller $CI, mixed $data = null, array $meta = []): void
    {
        $CI->output
            ->set_status_header(201)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(self::encode(['status' => true, 'data' => $data, 'meta' => (object) $meta]));
    }

    /**
     * 202 Accepted (for async jobs).
     */
    public static function accepted(CI_Controller $CI, mixed $data = null): void
    {
        $CI->output
            ->set_status_header(202)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(self::encode(['status' => true, 'data' => $data]));
    }

    /**
     * 204 No Content (e.g. successful DELETE).
     */
    public static function noContent(CI_Controller $CI): void
    {
        $CI->output->set_status_header(204);
    }

    /**
     * Generic error response.
     *
     * @param int    $httpStatus HTTP status code (400, 401, 403, 404, 409, 422, 429, 500, 503)
     * @param string $code       machine-readable error code (e.g. "unauthorized")
     * @param string $message    human-readable message
     * @param array  $details    optional validation details / context
     */
    public static function error(
        CI_Controller $CI,
        int $httpStatus,
        string $code,
        string $message,
        array $details = []
    ): void {
        $CI->output
            ->set_status_header($httpStatus)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(self::encode([
                'status' => false,
                'error'  => [
                    'code'    => $code,
                    'message' => $message,
                    'details' => (object) $details,
                ],
            ]));
    }

    private static function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
