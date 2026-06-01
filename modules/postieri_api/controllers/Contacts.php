<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/contacts/* — Contact CRUD.
 *
 * Scopes:
 *   contacts:read   — list, get
 *   contacts:write  — create, update, delete
 */
class Contacts extends Api_v1
{
    private function model(): \Contacts_model
    {
        $this->load->model('contacts_model');
        return $this->contacts_model;
    }

    /** GET /api/v1/contacts */
    public function index(): void
    {
        if (!$this->requireScope('contacts:read')) {
            return;
        }
        $pag = $this->pagination();
        $customerId = $this->input->get('customer_id');
        $search     = (string) $this->input->get('q', true);

        $this->db->select('c.*, cl.company as customer_company')
            ->from(db_prefix() . 'contacts c')
            ->join(db_prefix() . 'clients cl', 'cl.userid = c.userid', 'left');
        if ($customerId) {
            $this->db->where('c.userid', (int) $customerId);
        }
        if ($search !== '') {
            $this->db->group_start()
                ->like('c.firstname', $search)
                ->or_like('c.lastname', $search)
                ->or_like('c.email', $search)
                ->or_like('c.phonenumber', $search)
                ->group_end();
        }
        $rows = $this->db
            ->order_by('c.id', 'DESC')
            ->limit($pag['per_page'], $pag['offset'])
            ->get()
            ->result_array();
        $total = $this->db->count_all_results(db_prefix() . 'contacts', false);

        Response::ok($this, array_map([$this, 'present'], $rows), [
            'pagination' => [
                'page'        => $pag['page'],
                'per_page'    => $pag['per_page'],
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $pag['per_page']),
            ],
        ]);
    }

    /** GET /api/v1/contacts/{id} */
    public function show($id = null): void
    {
        if (!$this->requireScope('contacts:read')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Contact id is required');
            return;
        }
        $c = $this->model()->get((int) $id);
        if (!$c) {
            Response::error($this, 404, 'not_found', "Contact {$id} not found");
            return;
        }
        Response::ok($this, $this->present($c));
    }

    /** POST /api/v1/contacts */
    public function create(): void
    {
        if (!$this->requireScope('contacts:write')) {
            return;
        }
        $b = $this->jsonBody();
        $required = ['customer_id', 'firstname', 'lastname', 'email', 'password'];
        $missing = array_values(array_filter($required, fn($k) => empty($b[$k])));
        if ($missing) {
            Response::error($this, 422, 'validation_failed', 'Missing required fields', ['missing' => $missing]);
            return;
        }
        $b['userid'] = (int) $b['customer_id'];
        $contactId = $this->model()->add($b);
        if (!$contactId) {
            Response::error($this, 422, 'create_failed', 'Failed to create contact');
            return;
        }
        $c = $this->model()->get($contactId);
        Response::created($this, $this->present($c));
    }

    /** PUT /api/v1/contacts/{id} */
    public function update($id = null): void
    {
        if (!$this->requireScope('contacts:write')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Contact id is required');
            return;
        }
        if (!$this->model()->get((int) $id)) {
            Response::error($this, 404, 'not_found', "Contact {$id} not found");
            return;
        }
        $b = $this->jsonBody();
        $ok = $this->model()->update($b, (int) $id);
        if (!$ok) {
            Response::error($this, 422, 'update_failed', 'Failed to update contact');
            return;
        }
        $c = $this->model()->get((int) $id);
        Response::ok($this, $this->present($c));
    }

    /** DELETE /api/v1/contacts/{id} */
    public function destroy($id = null): void
    {
        if (!$this->requireScope('contacts:write')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Contact id is required');
            return;
        }
        if (!$this->model()->get((int) $id)) {
            Response::error($this, 404, 'not_found', "Contact {$id} not found");
            return;
        }
        $ok = $this->model()->delete((int) $id);
        if (!$ok) {
            Response::error($this, 422, 'delete_failed', 'Failed to delete contact');
            return;
        }
        Response::noContent($this);
    }

    private function present($c): array
    {
        $row = is_object($c) ? (array) $c : $c;
        return [
            'id'          => (int) $row['id'],
            'customer_id' => (int) $row['userid'],
            'firstname'   => $row['firstname'],
            'lastname'    => $row['lastname'],
            'email'       => $row['email'],
            'phonenumber' => $row['phonenumber'] ?? '',
            'title'       => $row['title'] ?? '',
            'is_primary'  => (int) ($row['is_primary'] ?? 0),
            'active'      => (int) ($row['active'] ?? 1),
        ];
    }
}
