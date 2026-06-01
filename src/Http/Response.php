<?php

namespace Perfexcrm\Postieri\Api\Http;

/**
 * Standardised JSON response envelope.
 *
 *   { "status": true,  "data": ..., "meta": {...} }            // success
 *   { "status": false, "error": { "code": "...", ... } }        // error
 *
 * Returned as a value object from controllers; emit it with ->send()
 * (which writes the HTTP status header + JSON body via CodeIgniter's
 * output class). Tests can inspect the value object without needing a
 * real CI_Controller — the static ::setEmitter() hook lets them capture.
 */
final class Response
{
    public int $statusCode = 200;
    /** @var array{status:bool,data?:mixed,meta?:array,error?:array}|null */
    public ?array $body = null;

    /** @var callable|null Test hook: fn(self $r): void */
    public static $emitter = null;

    public static function ok(mixed $data, ?array $meta = null, int $status = 200): self
    {
        $r = new self();
        $r->statusCode = $status;
        $r->body = ['status' => true, 'data' => $data];
        if ($meta !== null) {
            $r->body['meta'] = $meta;
        }
        $r->emit();
        return $r;
    }

    public static function created(mixed $data, ?array $meta = null): self
    {
        return self::ok($data, $meta, 201);
    }

    public static function noContent(int $status = 204): self
    {
        $r = new self();
        $r->statusCode = $status;
        $r->body = null;
        $r->emit();
        return $r;
    }

    public static function error(
        int $httpStatus,
        string $code,
        string $message,
        ?array $details = null
    ): self {
        $r = new self();
        $r->statusCode = $httpStatus;
        $r->body = [
            'status' => false,
            'error'  => array_filter(
                [
                    'code'    => $code,
                    'message' => $message,
                    'details' => $details,
                ],
                static fn ($v) => $v !== null
            ),
        ];
        $r->emit();
        return $r;
    }

    /**
     * Emit the response. In production this writes to CodeIgniter's
     * Output class. In tests, capture via ::setEmitter().
     */
    public function emit(): void
    {
        if (self::$emitter !== null) {
            (self::$emitter)($this);
            return;
        }
        $CI = &get_instance();
        $CI->output->set_status_header($this->statusCode);
        if ($this->body !== null) {
            $CI->output
                ->set_content_type('application/json', 'utf-8')
                ->set_output(json_encode(
                    $this->body,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
        }
    }

    /** @internal Test seam. */
    public static function setEmitter(?callable $cb): void
    {
        self::$emitter = $cb;
    }
}
