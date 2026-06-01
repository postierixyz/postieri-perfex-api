<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Auth\TokenService;

/**
 * Admin controller for the Postieri API module.
 *
 * Scope: token issuance + revocation. Everything else (webhook management,
 * rate limit tuning, API usage logs) is exposed through the REST API.
 *
 * Two routes are wired up by Perfex automatically (controller name =
 * folder name, method name = last URI segment, lowercase):
 *   /admin/postieri_api           → Postieri_api::index
 *   /admin/postieri_api/tokens    → Postieri_api::tokens
 *   /admin/postieri_api/revoke/ID → Postieri_api::revoke/ID  (POST)
 */
class Postieri_api extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        if (staff_cant('view', 'settings')) {
            access_denied(_l('postieri_api'));
        }
    }

    /**
     * Module landing page — quick API info card pointing admins at the
     * OpenAPI reference.
     */
    public function index(): void
    {
        $data['title']   = _l('postieri_api');
        $data['version'] = POSTIERI_API_VERSION ?? '0.0.0';
        $this->load->view('postieri_api/index', $data);
    }

    /**
     * Token issuance page.
     *
     *   GET  → renders the form + the active tokens table.
     *   POST → validates input, issues a new token, and PRG-redirects so
     *          a browser refresh doesn't re-submit. The new token is
     *          stashed in flash data so the view can display it once.
     */
    public function tokens(): void
    {
        if (!is_admin()) {
            access_denied(_l('postieri_api_tokens'));
        }

        // --- Handle POST: issue a new token ---
        if ($this->input->post() && $this->input->post('name')) {
            $name   = trim((string) $this->input->post('name'));
            $scopes = $this->parseScopes((string) $this->input->post('scopes'));

            if ($name === '') {
                set_alert('danger', _l('postieri_api_token_issue_failed') . ' (name required)');
                redirect(admin_url('postieri_api/tokens'));
                return;
            }

            try {
                $svc   = new TokenService($this->db);
                $userId = (int) ($this->session->userdata('staff_user_id') ?? 0);
                if ($userId <= 0) {
                    // Fallback for CLI/seed — use the first admin in the system.
                    $userId = (int) $this->db
                        ->where('admin', 1)
                        ->order_by('staffid', 'ASC')
                        ->get(db_prefix() . 'staff')
                        ->row('staffid');
                }

                $issued = $svc->issue($userId, $name, $scopes);

                set_alert('success', _l('postieri_api_token_issued'));
                $this->session->set_flashdata('postieri_new_token', [
                    'name'    => $name,
                    'token'   => $issued['token'],
                    'scopes'  => $scopes,
                ]);
            } catch (\Throwable $e) {
                set_alert('danger', _l('postieri_api_token_issue_failed') . ' — ' . $e->getMessage());
            }

            redirect(admin_url('postieri_api/tokens'));
            return;
        }

        // --- Handle POST: revoke a token ---
        $revokeId = (int) $this->input->post('revoke_id');
        if ($revokeId > 0) {
            try {
                $svc = new TokenService($this->db);
                $svc->revoke($revokeId);
                set_alert('success', _l('postieri_api_token_revoked'));
            } catch (\Throwable $e) {
                set_alert('danger', $e->getMessage());
            }
            redirect(admin_url('postieri_api/tokens'));
            return;
        }

        // --- GET: render the page ---
        $data['title']        = _l('postieri_api_tokens');
        $data['new_token']    = $this->session->flashdata('postieri_new_token');
        $data['tokens']       = [];
        $data['revoke_url']   = admin_url('postieri_api/tokens');

        try {
            $svc          = new TokenService($this->db);
            $data['tokens'] = $svc->listAll();
        } catch (\Throwable $e) {
            // Table may not exist yet on first run — leave the list empty.
            log_message('error', 'postieri_api::tokens list failed: ' . $e->getMessage());
        }

        $this->load->view('postieri_api/tokens', $data);
    }

    /**
     * Parse a free-form scopes field (comma-separated) into a clean array.
     * "*" expands to a single wildcard marker — the verifier in TokenService
     * treats it as a match-all.
     */
    private function parseScopes(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '*') {
            return ['*'];
        }
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        return $parts === [] ? ['*'] : array_values($parts);
    }
}
