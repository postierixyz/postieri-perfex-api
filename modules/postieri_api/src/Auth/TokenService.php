<?php

namespace Perfexcrm\Postieri\Api\Auth;

use CI_DB_driver;

/**
 * Manages API tokens: issue, verify, revoke, list.
 *
 * Tokens are stored hashed (Argon2id) — the plain text is shown to the user
 * exactly once, on creation, and never persisted.
 */
final class TokenService
{
    public function __construct(private CI_DB_driver $db) {}

    /**
     * Issue a new token.
     *
     * @param int          $userId
     * @param string       $name
     * @param array        $scopes
     * @param string|null  $expiresAt  MySQL DATETIME format, or null for never
     *
     * @return array{id:int, token:string, scopes:array, expires_at:?string}
     */
    public function issue(int $userId, string $name, array $scopes = [], ?string $expiresAt = null): array
    {
        $plain = bin2hex(random_bytes(32)); // 64-char hex
        $hash  = password_hash($plain, PASSWORD_ARGON2ID);

        $this->db->insert(db_prefix() . 'postieri_api_tokens', [
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => $hash,
            'scopes'     => json_encode(array_values($scopes)),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'id'         => (int) $this->db->insert_id(),
            'token'      => $plain,
            'scopes'     => $scopes,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify a Bearer token. Returns the token row (with user_id, scopes) on
     * success, or null if the token is invalid, expired, or revoked.
     *
     * Updates `last_used_at` on success (best-effort).
     */
    public function verify(string $plain): ?array
    {
        $table = db_prefix() . 'postieri_api_tokens';

        $rows = $this->db
            ->where('revoked_at IS NULL', null, false)
            ->where('(expires_at IS NULL OR expires_at > NOW())', null, false)
            ->get($table)
            ->result_array();

        foreach ($rows as $row) {
            if (password_verify($plain, $row['token_hash'])) {
                // Update last_used_at — best effort, don't fail the request
                $this->db->where('id', $row['id']);
                $this->db->update($table, ['last_used_at' => date('Y-m-d H:i:s')]);

                $row['scopes'] = json_decode($row['scopes'] ?? '[]', true) ?: [];
                return $row;
            }
        }

        return null;
    }

    /**
     * Revoke a token by id. Returns true on success.
     */
    public function revoke(int $tokenId): bool
    {
        return $this->db
            ->where('id', $tokenId)
            ->update(db_prefix() . 'postieri_api_tokens', [
                'revoked_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * List all tokens (admin view). Hashed values are stripped.
     *
     * @return array<array<string, mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->db
            ->order_by('created_at', 'DESC')
            ->get(db_prefix() . 'postieri_api_tokens')
            ->result_array();

        return array_map(function ($r) {
            unset($r['token_hash']);
            $r['scopes'] = json_decode($r['scopes'] ?? '[]', true) ?: [];
            return $r;
        }, $rows);
    }
}
