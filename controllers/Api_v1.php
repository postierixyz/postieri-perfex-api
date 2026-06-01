<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Auth\TokenService;
use Perfexcrm\Postieri\Api\Http\Response;
use Perfexcrm\Postieri\Api\Http\RateLimiter;

/**
 * Base controller for all /api/v1/ endpoints.
 *
 * - Verifies Bearer token (or ?token= fallback for browser testing)
 * - Enforces rate limit
 * - Provides scope checks and pagination helpers
 */
class Api_v1 extends CI_Controller
{
    /** @var array<string, mixed> */
    protected array $token = [];

    /** @var array<int, string> */
    protected array $scopes = [];

    public function __construct()
    {
        parent::__construct();

        if (get_option('postieri_api_enabled') !== '1') {
            Response::error(503, 'api_disabled', 'Postieri API is disabled in settings');
            return;
        }

        $authHeader = $this->input->get_request_header('Authorization', true);
        $token = null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } else {
            // Fallback: ?token= for easy browser/curl testing
            $token = $this->input->get('token');
        }

        if (!$token) {
            Response::error(401, 'unauthorized', 'Missing Authorization: Bearer <token>');
            return;
        }

        $svc = new TokenService($this->db);
        $row = $svc->verify($token);
        if (!$row) {
            Response::error(401, 'invalid_token', 'Token is invalid, expired, or revoked');
            return;
        }

        $this->token  = $row;
        $this->scopes = $row['scopes'] ?? [];

        $ip       = $this->input->ip_address();
        $endpoint = $this->uri->uri_string();
        $method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $limiter = new RateLimiter(
            $this->db,
            (int) $row['id'],
            (int) get_option('postieri_api_rate_limit_per_min'),
            (int) get_option('postieri_api_rate_limit_per_hour')
        );
        $rl = $limiter->check($endpoint, $method, $ip);

        $this->output->set_header('X-RateLimit-Limit-Min: '      . get_option('postieri_api_rate_limit_per_min'));
        $this->output->set_header('X-RateLimit-Remaining-Min: '  . $rl['remaining_min']);
        $this->output->set_header('X-RateLimit-Limit-Hour: '     . get_option('postieri_api_rate_limit_per_hour'));
        $this->output->set_header('X-RateLimit-Remaining-Hour: ' . $rl['remaining_hour']);

        if (!$rl['allowed']) {
            $retryAfter = $rl['remaining_min'] === 0 ? 60 : 3600;
            $this->output->set_header('Retry-After: ' . $retryAfter);
            Response::error(429, 'rate_limited', "Rate limit exceeded. Retry after {$retryAfter} seconds.", [
                    'retry_after_seconds' => $retryAfter,
                    'limit_per_minute'    => (int) get_option('postieri_api_rate_limit_per_min'),
                    'limit_per_hour'      => (int) get_option('postieri_api_rate_limit_per_hour'),
                ]
            );
            return;
        }
    }

    /**
     * Does the current token have the given scope?
     *
     * Supports:
     *  - exact match: "customers:read"
     *  - wildcard "*":  matches everything
     *  - wildcard "resource:*": matches "resource:read", "resource:write", etc.
     */
    protected function hasScope(string $required): bool
    {
        foreach ($this->scopes as $s) {
            if (!is_string($s)) continue;
            if ($s === '*' || $s === '*:*' || $s === $required) {
                return true;
            }
            if (str_ends_with($s, ':*')) {
                $prefix = substr($s, 0, -2);
                if (str_starts_with($required, $prefix . ':')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Require a scope or 403. Returns true if allowed.
     */
    protected function requireScope(string $scope): bool
    {
        if (!$this->hasScope($scope)) {
            Response::error(403, 'insufficient_scope', "Missing required scope: {$scope}", [
                'required_scope' => $scope,
                'token_scopes'   => $this->scopes,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Parse pagination from query string with sane defaults + caps.
     *
     * @return array{page:int, per_page:int, offset:int}
     */
    protected function pagination(): array
    {
        $page    = max(1, (int) ($this->input->get('page') ?: 1));
        $perPage = min(100, max(1, (int) ($this->input->get('per_page') ?: 25)));
        return [
            'page'     => $page,
            'per_page' => $perPage,
            'offset'   => ($page - 1) * $perPage,
        ];
    }

    /**
     * Read JSON body.
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        $raw = $this->input->raw_input_stream;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
