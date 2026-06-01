<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/leads/* — Lead CRUD + conversion.
 *
 * Scopes:
 *   leads:read   — list, get
 *   leads:write  — create, update, delete, convert
 */
class Leads extends Api_v1
{
    private function model(): \Leads_model
    {
        $this->load->model('leads_model');
        return $this->leads_model;
    }

    /** GET /api/v1/leads */
    public function index(): void
    {
        if (!$this->requireScope('leads:read')) {
            return;
        }
        $pag = $this->pagination();
        $search = (string) $this->input->get('q', true);
        $status = $this->input->get('status');

        $where = [];
        if ($status !== null) {
            $where['status'] = $status;
        }

        $this->db->select('*')->from(db_prefix() . 'leads');
        if (!empty($where)) {
            $this->db->where($where);
        }
        if ($search !== '') {
            $this->db->group_start()
                ->like('name', $search)
                ->or_like('company', $search)
                ->or_like('email', $search)
                ->or_like('phonenumber', $search)
                ->group_end();
        }
        $rows = $this->db
            ->order_by('id', 'DESC')
            ->limit($pag['per_page'], $pag['offset'])
            ->get()
            ->result_array();
        $total = $this->db->count_all_results(db_prefix() . 'leads', false);

        Response::ok(array_map([$this, 'present'], $rows), [
            'pagination' => [
                'page'        => $pag['page'],
                'per_page'    => $pag['per_page'],
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $pag['per_page']),
            ],
        ]);
    }

    /** GET /api/v1/leads/{id} */
    public function show($id = null): void
    {
        if (!$this->requireScope('leads:read')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Lead id is required');
            return;
        }
        $lead = $this->model()->get((int) $id);
        if (!$lead) {
            Response::error(404, 'not_found', "Lead {$id} not found");
            return;
        }
        Response::ok($this->present($lead, true));
    }

    /** POST /api/v1/leads */
    public function create(): void
    {
        if (!$this->requireScope('leads:write')) {
            return;
        }
        $b = $this->jsonBody();
        $required = ['name', 'email'];
        $missing = array_values(array_filter($required, fn($k) => empty($b[$k])));
        if ($missing) {
            Response::error(422, 'validation_failed', 'Missing required fields', ['missing' => $missing]);
            return;
        }
        $leadId = $this->model()->add($b);
        if (!$leadId) {
            Response::error(422, 'create_failed', 'Failed to create lead', ['db_error' => $this->db->error()]);
            return;
        }
        $this->dispatch('lead.created', ['lead_id' => $leadId, 'email' => $b['email']]);

        $lead = $this->model()->get($leadId);
        Response::created($this->present($lead, true));
    }

    /** PUT /api/v1/leads/{id} */
    public function update($id = null): void
    {
        if (!$this->requireScope('leads:write')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Lead id is required');
            return;
        }
        if (!$this->model()->get((int) $id)) {
            Response::error(404, 'not_found', "Lead {$id} not found");
            return;
        }
        $b = $this->jsonBody();
        $ok = $this->model()->update($b, (int) $id);
        if (!$ok) {
            Response::error(422, 'update_failed', 'Failed to update lead');
            return;
        }
        $lead = $this->model()->get((int) $id);
        Response::ok($this->present($lead, true));
    }

    /** DELETE /api/v1/leads/{id} */
    public function destroy($id = null): void
    {
        if (!$this->requireScope('leads:write')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Lead id is required');
            return;
        }
        if (!$this->model()->get((int) $id)) {
            Response::error(404, 'not_found', "Lead {$id} not found");
            return;
        }
        $ok = $this->model()->delete((int) $id, true);
        if (!$ok) {
            Response::error(422, 'delete_failed', 'Failed to delete lead');
            return;
        }
        Response::noContent();
    }

    /**
     * POST /api/v1/leads/{id}/convert
     * Converts a lead to a customer.
     */
    public function convert($id = null): void
    {
        if (!$this->requireScope('leads:write')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Lead id is required');
            return;
        }
        $lead = $this->model()->get((int) $id);
        if (!$lead) {
            Response::error(404, 'not_found', "Lead {$id} not found");
            return;
        }
        if ((int) $lead->is_lead == 0) {
            Response::error(409, 'already_converted', "Lead {$id} has already been converted", [
                'customer_id' => (int) $lead->client_id ?? null,
            ]);
            return;
        }

        $customerId = $this->model()->mark_as_customer((int) $id);
        if (!$customerId) {
            Response::error(422, 'conversion_failed', 'Failed to convert lead to customer');
            return;
        }
        $this->dispatch('lead.converted', [
            'lead_id'     => (int) $id,
            'customer_id' => (int) $customerId,
        ]);

        Response::ok([
            'lead_id'     => (int) $id, 'customer_id' => (int) $customerId,
            'status'      => 'converted',
        ]);
    }

    private function present($l, bool $full = false): array
    {
        $obj = is_object($l) ? $l : (object) $l;
        $out = [
            'id'          => (int) $obj->id,
            'name'        => $obj->name,
            'email'       => $obj->email,
            'company'     => $obj->company ?? null,
            'phonenumber' => $obj->phonenumber ?? null,
            'title'       => $obj->title ?? null,
            'country'     => (int) ($obj->country ?? 0),
            'status'      => $obj->status,
            'source'      => $obj->source,
            'assigned'    => (int) ($obj->assigned ?? 0),
            'dateadded'   => $obj->dateadded,
            'is_lead'     => (int) $obj->is_lead,
        ];
        if ($full) {
            $out['description']   = $obj->description ?? '';
            $out['address']       = $obj->address ?? '';
            $out['city']          = $obj->city ?? '';
            $out['state']         = $obj->state ?? '';
            $out['zip']           = $obj->zip ?? '';
            $out['website']       = $obj->website ?? '';
            $out['lastcontact']   = $obj->lastcontact ?? null;
            $out['date_converted']= $obj->date_converted ?? null;
        }
        return $out;
    }

    private function dispatch(string $event, array $payload): void
    {
        $this->load->library('postieri_api/webhook_dispatcher');
        $this->webhook_dispatcher->dispatch($event, $payload);
    }
}
