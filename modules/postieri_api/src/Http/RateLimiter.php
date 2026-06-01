<?php

namespace Perfexcrm\Postieri\Api\Http;

use CI_DB_driver;

/**
 * Sliding-window rate limiter, backed by MySQL.
 *
 * Logs every request and counts requests within the last minute and last hour
 * for the given token. Configurable limits via options.
 */
final class RateLimiter
{
    public function __construct(
        private CI_DB_driver $db,
        private int $tokenId,
        private int $perMinute,
        private int $perHour
    ) {}

    /**
     * Check rate limit. Returns:
     *   [
     *     'allowed'        => bool,
     *     'remaining_min'  => int,
     *     'remaining_hour' => int,
     *     'reset_min'      => int,   // seconds until window slides
     *     'reset_hour'     => int,
     *   ]
     */
    public function check(string $endpoint, string $method, string $ip): array
    {
        $now = date('Y-m-d H:i:s');
        $oneMinAgo = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $table = db_prefix() . 'postieri_api_rate_log';

        // Count requests in the last minute
        $this->db->where('token_id', $this->tokenId);
        $this->db->where('created_at >=', $oneMinAgo);
        $countMin = (int) $this->db->count_all_results($table);

        // Count requests in the last hour
        $this->db->where('token_id', $this->tokenId);
        $this->db->where('created_at >=', $oneHourAgo);
        $countHour = (int) $this->db->count_all_results($table);

        // Always log this request (even if rate-limited — useful for audit)
        $this->db->insert($table, [
            'token_id'   => $this->tokenId,
            'endpoint'   => substr($endpoint, 0, 255),
            'method'     => substr($method, 0, 10),
            'ip'         => substr($ip, 0, 45),
            'created_at' => $now,
        ]);

        $allowed = $countMin < $this->perMinute && $countHour < $this->perHour;

        return [
            'allowed'        => $allowed,
            'remaining_min'  => max(0, $this->perMinute - $countMin - 1),
            'remaining_hour' => max(0, $this->perHour - $countHour - 1),
            'reset_min'      => 60,
            'reset_hour'     => 3600,
        ];
    }
}
