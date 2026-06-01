<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/invoices/* — Invoice CRUD + PDF download.
 *
 * Scopes:
 *   invoices:read   — list, get, pdf
 *   invoices:write  — create, update
 */
class Invoices extends Api_v1
{
    private function model(): \Invoices_model
    {
        $this->load->model('invoices_model');
        return $this->invoices_model;
    }

    /** GET /api/v1/invoices */
    public function index(): void
    {
        if (!$this->requireScope('invoices:read')) {
            return;
        }
        $pag = $this->pagination();
        $clientId = $this->input->get('customer_id');
        $status   = $this->input->get('status');

        $where = [];
        if ($clientId) $where['clientid'] = (int) $clientId;
        if ($status !== null) $where['status'] = (int) $status;

        $rows = $this->model()->get('', array_merge($where, [
            'limit'  => $pag['per_page'],
            'offset' => $pag['offset'],
            'order'  => 'id DESC',
        ]));
        $total = $this->db->where($where)->count_all_results(db_prefix() . 'invoices');

        Response::ok($this, array_map([$this, 'present'], $rows), [
            'pagination' => [
                'page'        => $pag['page'],
                'per_page'    => $pag['per_page'],
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $pag['per_page']),
            ],
        ]);
    }

    /** GET /api/v1/invoices/{id} */
    public function show($id = null): void
    {
        if (!$this->requireScope('invoices:read')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Invoice id is required');
            return;
        }
        $inv = $this->model()->get((int) $id);
        if (!$inv) {
            Response::error($this, 404, 'not_found', "Invoice {$id} not found");
            return;
        }
        $items = $this->model()->get_invoice_items($inv->id);
        $payments = $this->model()->get_invoice_payments($inv->id);
        Response::ok($this, $this->present($inv, true) + [
            'items'    => $items,
            'payments' => $payments,
        ]);
    }

    /** POST /api/v1/invoices */
    public function create(): void
    {
        if (!$this->requireScope('invoices:write')) {
            return;
        }
        $b = $this->jsonBody();
        $required = ['customer_id', 'date', 'due_date'];
        $missing = array_values(array_filter($required, fn($k) => empty($b[$k])));
        if ($missing) {
            Response::error($this, 422, 'validation_failed', 'Missing required fields', ['missing' => $missing]);
            return;
        }
        // Add items if provided
        if (!empty($b['items']) && is_array($b['items'])) {
            foreach ($b['items'] as $item) {
                $this->model()->add_invoice_item($b, $item);
            }
        }
        $invoiceId = $this->model()->add($b);
        if (!$invoiceId) {
            Response::error($this, 422, 'create_failed', 'Failed to create invoice', ['db_error' => $this->db->error()]);
            return;
        }

        // Dispatch webhook
        $this->dispatch('invoice.created', ['invoice_id' => $invoiceId, 'customer_id' => $b['customer_id']]);

        $inv = $this->model()->get($invoiceId);
        Response::created($this, $this->present($inv, true));
    }

    /** PUT /api/v1/invoices/{id} */
    public function update($id = null): void
    {
        if (!$this->requireScope('invoices:write')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Invoice id is required');
            return;
        }
        $inv = $this->model()->get((int) $id);
        if (!$inv) {
            Response::error($this, 404, 'not_found', "Invoice {$id} not found");
            return;
        }
        $b = $this->jsonBody();
        // Status change: detect transition to "paid" for webhook
        $wasStatus = (int) $inv->status;
        $ok = $this->model()->update($b, (int) $id);
        if (!$ok) {
            Response::error($this, 422, 'update_failed', 'Failed to update invoice');
            return;
        }
        // Re-read and detect status change
        $inv2 = $this->model()->get((int) $id);
        if ((int) $inv2->status === PerfexInvoiceStatus::PAID && $wasStatus !== PerfexInvoiceStatus::PAID) {
            $this->dispatch('invoice.paid', [
                'invoice_id'  => (int) $id,
                'customer_id' => (int) $inv2->clientid,
                'total'       => (float) $inv2->total,
            ]);
        }
        Response::ok($this, $this->present($inv2, true));
    }

    /**
     * GET /api/v1/invoices/{id}/pdf
     *
     * Streams the PDF using Perfex's native PDF generator.
     */
    public function pdf($id = null): void
    {
        if (!$this->requireScope('invoices:read')) {
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Invoice id is required');
            return;
        }
        $inv = $this->model()->get((int) $id);
        if (!$inv) {
            Response::error($this, 404, 'not_found', "Invoice {$id} not found");
            return;
        }
        try {
            $pdf = $this->model()->get_invoice_pdf((int) $id);
        } catch (\Throwable $e) {
            Response::error($this, 500, 'pdf_failed', $e->getMessage());
            return;
        }
        $this->output
            ->set_status_header(200)
            ->set_content_type('application/pdf')
            ->set_header("Content-Disposition: attachment; filename=\"invoice-{$id}.pdf\"")
            ->set_output($pdf);
    }

    private function present(object $i, bool $full = false): array
    {
        $out = [
            'id'         => (int) $i->id,
            'number'     => $i->number,
            'customer_id'=> (int) $i->clientid,
            'status'     => (int) $i->status,
            'status_name'=> $this->model()->get_invoice_status_string($i),
            'date'       => $i->date,
            'due_date'   => $i->duedate,
            'subtotal'   => (float) $i->subtotal,
            'total_tax'  => (float) ($i->total_tax ?? 0),
            'discount'   => (float) $i->discount_total,
            'total'      => (float) $i->total,
            'currency'   => $i->currency_name,
            'symbol'     => $i->symbol,
        ];
        if ($full) {
            $out['items_total_tax'] = (float) ($i->items_total_tax ?? 0);
            $out['adjustment']      = (float) ($i->adjustment ?? 0);
        }
        return $out;
    }

    /**
     * Helper: dispatch a webhook (if dispatcher is loaded).
     */
    private function dispatch(string $event, array $payload): void
    {
        $this->load->library('postieri_api/webhook_dispatcher');
        $this->webhook_dispatcher->dispatch($event, $payload);
    }
}

/**
 * Perfex invoice status constants (see application/helpers/invoices_helper.php).
 */
class PerfexInvoiceStatus
{
    public const UNPAID     = 1;
    public const PAID       = 2;
    public const PARTIALLY  = 3;
    public const OVERDUE    = 4;
    public const CANCELLED  = 5;
    public const DRAFT      = 6;
}
