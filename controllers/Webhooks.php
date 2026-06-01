<?php

defined('BASEPATH') or exit('No direct script access allowed');

// Fail-safe direct includes — see comment in controllers/Postieri_api.php
// for the rationale. The namespaced classes live in src/ and the inline
// autoloader in postieri_api.php is only registered when the init file
// runs. To avoid a blank page on any race condition we include them here.
require_once __DIR__ . '/../src/Http/Response.php';

use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/webhooks/* — Manage webhook subscriptions.
 *
 * Scopes:
 *   webhooks:read   — list, get
 *   webhooks:write  — create, update, delete
 */
class Webhooks extends Api_v1
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('postieri_api/postieri_webhook_model');
    }

    /** GET /api/v1/webhooks */
    public function index(): void
    {
        if (!$this->requireScope('webhooks:read')) {
            return;
        }
        $rows = $this->postieri_webhook_model->all();
        Response::ok(array_map([$this, 'present'], $rows));
    }

    /** GET /api/v1/webhooks/{id} */
    public function show($id = null): void
    {
        if (!$this->requireScope('webhooks:read')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Webhook id is required');
            return;
        }
        $row = $this->postieri_webhook_model->find((int) $id);
        if (!$row) {
            Response::error(404, 'not_found', "Webhook {$id} not found");
            return;
        }
        Response::ok($this->present($row, true));
    }

    /** POST /api/v1/webhooks */
    public function create(): void
    {
        if (!$this->requireScope('webhooks:write')) {
            return;
        }
        $b = $this->jsonBody();
        $required = ['name', 'url', 'events'];
        $missing = array_values(array_filter($required, fn($k) => empty($b[$k])));
        if ($missing) {
            Response::error(422, 'validation_failed', 'Missing required fields', ['missing' => $missing]);
            return;
        }
        if (!is_array($b['events'])) {
            Response::error(422, 'validation_failed', 'events must be an array');
            return;
        }
        $id = $this->postieri_webhook_model->create([
            'name'       => $b['name'], 'url'        => $b['url'],
            'events'     => array_values($b['events']),
            'secret'     => $b['secret'] ?? bin2hex(random_bytes(32)),
            'is_active'  => isset($b['is_active']) ? (int) (bool) $b['is_active'] : 1,
            'created_by' => (int) $this->token['user_id'],
        ]);
        $row = $this->postieri_webhook_model->find($id);
        Response::created($this->present($row, true) + [
            'warning' => 'The secret is shown only on creation and update. Store it securely.',
        ]);
    }

    /** PUT /api/v1/webhooks/{id} */
    public function update($id = null): void
    {
        if (!$this->requireScope('webhooks:write')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Webhook id is required');
            return;
        }
        $row = $this->postieri_webhook_model->find((int) $id);
        if (!$row) {
            Response::error(404, 'not_found', "Webhook {$id} not found");
            return;
        }
        $b = $this->jsonBody();
        $patch = [];
        foreach (['name', 'url', 'events', 'is_active'] as $f) {
            if (array_key_exists($f, $b)) $patch[$f] = $b[$f];
        }
        if (!empty($patch)) {
            $this->postieri_webhook_model->update((int) $id, $patch);
        }
        $row = $this->postieri_webhook_model->find((int) $id);
        Response::ok($this->present($row, true));
    }

    /** DELETE /api/v1/webhooks/{id} */
    public function destroy($id = null): void
    {
        if (!$this->requireScope('webhooks:write')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Webhook id is required');
            return;
        }
        $ok = $this->postieri_webhook_model->delete((int) $id);
        if (!$ok) {
            Response::error(404, 'not_found', "Webhook {$id} not found");
            return;
        }
        Response::noContent();
    }

    /**
     * GET /api/v1/webhooks/{id}/deliveries
     */
    public function deliveries($id = null): void
    {
        if (!$this->requireScope('webhooks:read')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Webhook id is required');
            return;
        }
        $pag = $this->pagination();
        $rows = $this->postieri_webhook_model->deliveries((int) $id, $pag['per_page'], $pag['offset']);
        $total = $this->postieri_webhook_model->count_deliveries((int) $id);
        Response::ok($rows, [
            'pagination' => [
                'page'        => $pag['page'],
                'per_page'    => $pag['per_page'],
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $pag['per_page']),
            ],
        ]);
    }

    private function present(array $w, bool $withSecret = false): array
    {
        $out = [
            'id'         => (int) $w['id'],
            'name'       => $w['name'],
            'url'        => $w['url'],
            'events'     => json_decode($w['events'], true) ?: [],
            'is_active'  => (int) $w['is_active'],
            'created_by' => (int) $w['created_by'],
            'created_at' => $w['created_at'],
            'updated_at' => $w['updated_at'],
        ];
        if ($withSecret) {
            $out['secret'] = $w['secret'];
        }
        return $out;
    }
}
