<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/customers/* — Customer CRUD.
 *
 * Backed by Perfex's native `tblclients` table via Clients_model.
 *
 * Scopes:
 *   customers:read   — list, get
 *   customers:write  — create, update, delete
 */
class Customers extends Api_v1
{
    private function model(): \Clients_model
    {
        $this->load->model('clients_model');
        return $this->clients_model;
    }

    /** GET /api/v1/customers */
    public function index(): void
    {
        if (!$this->requireScope('customers:read')) {
            return;
        }
        $pag = $this->pagination();
        $search = (string) $this->input->get('q', true);

        if ($search !== '') {
            $rows = $this->model()->search($search, $pag['offset'], $pag['per_page']);
            $total = $this->model()->count_search($search);
        } else {
            $rows = $this->model()->get('', ['limit' => $pag['per_page'], 'offset' => $pag['offset']]);
            $total = $this->model()->total_clients();
        }

        Response::ok($this, array_map([$this, 'present'], $rows), [
            'pagination' => [
                'page'        => $pag['page'],
                'per_page'    => $pag['per_page'],
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $pag['per_page']),
            ],
        ]);
    }

    /** GET /api/v1/customers/{id} */
    public function show($id = null): void
    {
        if (!$this->requireScope('customers:read')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Customer id is required');
            return;
        }
        $c = $this->model()->get((int) $id);
        if (!$c) {
            Response::error($this, 404, 'not_found', "Customer {$id} not found");
            return;
        }

        // Also include contacts
        $this->load->model('contacts_model');
        $contacts = $this->contacts_model->get_contacts($c->userid);

        Response::ok($this, $this->present($c, true) + [
            'contacts' => array_map(function ($c) {
                return [
                    'id'         => (int) $c['id'],
                    'firstname'  => $c['firstname'],
                    'lastname'   => $c['lastname'],
                    'email'      => $c['email'],
                    'phonenumber'=> $c['phonenumber'],
                    'is_primary' => (int) $c['is_primary'],
                ];
            }, $contacts),
        ]);
    }

    /** POST /api/v1/customers */
    public function create(): void
    {
        if (!$this->requireScope('customers:write')) {
            return;
        }
        $b = $this->jsonBody();
        $required = ['company', 'firstname', 'lastname', 'email', 'password'];
        $missing = array_values(array_filter($required, fn($k) => empty($b[$k])));
        if ($missing) {
            Response::error($this, 422, 'validation_failed', 'Missing required fields', ['missing' => $missing]);
            return;
        }

        $customer_id = $this->model()->add($b);
        if (!$customer_id) {
            Response::error($this, 422, 'create_failed', 'Failed to create customer', ['db_error' => $this->db->error()]);
            return;
        }
        $c = $this->model()->get($customer_id);
        Response::created($this, $this->present($c, true));
    }

    /** PUT /api/v1/customers/{id} */
    public function update($id = null): void
    {
        if (!$this->requireScope('customers:write')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Customer id is required');
            return;
        }
        if (!$this->model()->get((int) $id)) {
            Response::error($this, 404, 'not_found', "Customer {$id} not found");
            return;
        }
        $b = $this->jsonBody();
        $ok = $this->model()->update($b, (int) $id);
        if (!$ok) {
            Response::error($this, 422, 'update_failed', 'Failed to update customer');
            return;
        }
        $c = $this->model()->get((int) $id);
        Response::ok($this, $this->present($c, true));
    }

    /** DELETE /api/v1/customers/{id} */
    public function destroy($id = null): void
    {
        if (!$this->requireScope('customers:write')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Customer id is required');
            return;
        }
        if (!$this->model()->get((int) $id)) {
            Response::error($this, 404, 'not_found', "Customer {$id} not found");
            return;
        }
        $ok = $this->model()->delete((int) $id);
        if (!$ok) {
            Response::error($this, 422, 'delete_failed', 'Failed to delete customer');
            return;
        }
        Response::noContent($this);
    }

    /**
     * Slim representation: only fields we want to expose externally.
     */
    private function present(object $c, bool $full = false): array
    {
        $out = [
            'id'         => (int) $c->userid,
            'company'    => $c->company,
            'vat'        => $c->vat,
            'phonenumber'=> $c->phonenumber,
            'country'    => (int) $c->country,
            'city'       => $c->city,
            'zip'        => $c->zip,
            'state'      => $c->state,
            'address'    => $c->address,
            'website'    => $c->website,
            'active'     => (int) $c->active,
            'leadid'     => (int) $c->leadid,
            'datecreated'=> $c->datecreated,
        ];
        if ($full) {
            $out['billing_street']  = $c->billing_street ?? '';
            $out['billing_city']    = $c->billing_city ?? '';
            $out['billing_zip']     = $c->billing_zip ?? '';
            $out['billing_state']   = $c->billing_state ?? '';
            $out['billing_country'] = (int) ($c->billing_country ?? 0);
        }
        return $out;
    }
}
