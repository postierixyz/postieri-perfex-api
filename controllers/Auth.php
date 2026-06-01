<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Auth\TokenService;
use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/auth/* — token lifecycle.
 */
class Auth extends CI_Controller
{
    /**
     * POST /api/v1/auth/token
     *
     * Two flows:
     *   A) { "email": "...", "password": "..." }     — self-service login
     *   B) { "user_id": N, "name": "...", "scopes": [...] }  — admin-issued
     *
     * Returns 201 with the plain token (shown once).
     */
    public function create_token(): void
    {
        $input = json_decode($this->input->raw_input_stream, true) ?: [];
        $svc   = new TokenService($this->db);

        // --- Flow A: admin path ---
        if (!empty($input['user_id']) && !empty($input['name'])) {
            if (!is_admin()) {
                Response::error(403, 'forbidden', 'Only admins can issue tokens for other users');
                return;
            }
            $issued = $svc->issue(
                (int) $input['user_id'], $input['name'],
                $input['scopes'] ?? ['*'],
                $input['expires_at'] ?? null);
            Response::created([
                'id'         => $issued['id'],
                'token'      => $issued['token'],
                'user_id'    => (int) $input['user_id'],
                'scopes'     => $issued['scopes'],
                'expires_at' => $issued['expires_at'],
                'warning'    => 'Save this token now — it will not be shown again.',
            ]);
            return;
        }

        // --- Flow B: self-service (email + password) ---
        if (!empty($input['email']) && !empty($input['password'])) {
            $email    = $input['email'];
            $password = $input['password'];
            $name     = $input['name'] ?? 'API token';

            $user = $this->db->where('email', $email)->get(db_prefix() . 'staff')->row_array();
            if (!$user || !app_hasher()->CheckPassword($password, $user['password'])) {
                Response::error(401, 'invalid_credentials', 'Email or password is incorrect');
                return;
            }
            if ((int) $user['active'] !== 1) {
                Response::error(403, 'inactive_user', 'User account is not active');
                return;
            }

            $issued = $svc->issue(
                (int) $user['staffid'],
                $name,
                json_decode(get_option('postieri_api_default_token_scopes') ?: '["*"]', true) ?: ['*']
            );
            Response::created([
                'id'         => $issued['id'],
                'token'      => $issued['token'],
                'user_id'    => (int) $user['staffid'],
                'scopes'     => $issued['scopes'],
                'expires_at' => $issued['expires_at'],
                'warning'    => 'Save this token now — it will not be shown again.',
            ]);
            return;
        }

        Response::error(400, 'validation_failed', 'Provide either {user_id, name, scopes} (admin) or {email, password} (self-service)',
            ['required_any_of' => [
                ['user_id', 'name'],
                ['email', 'password'],
            ]]
        );
    }

    /**
     * DELETE /api/v1/auth/token/{id}
     */
    public function delete_token($id = null): void
    {
        if (!is_admin()) {
            Response::error(403, 'forbidden', 'Only admins can revoke tokens');
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Token id is required');
            return;
        }
        $svc = new TokenService($this->db);
        if ($svc->revoke((int) $id)) {
            Response::noContent();
        } else {
            Response::error(500, 'revoke_failed', 'Failed to revoke token');
        }
    }

    /**
     * GET /api/v1/auth/tokens
     */
    public function list_tokens(): void
    {
        if (!is_admin()) {
            Response::error(403, 'forbidden', 'Only admins can list tokens');
            return;
        }
        $svc = new TokenService($this->db);
        Response::ok($svc->listAll());
    }
}
