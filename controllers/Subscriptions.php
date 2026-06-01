<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

/**
 * /api/v1/subscriptions/* — Read-only subscription status.
 *
 * Scopes:
 *   subscriptions:read  — list, get
 */
class Subscriptions extends Api_v1
{
    /** GET /api/v1/subscriptions */
    public function index(): void
    {
        if (!$this->requireScope('subscriptions:read')) {
            return;
        }
        $pag = $this->pagination();
        $customerId = $this->input->get('customer_id');
        $status     = $this->input->get('status'); // "active" | "expiring" | "expired"

        $this->db->select('s.*, c.company as customer_company')
            ->from(db_prefix() . 'subscriptions s')
            ->join(db_prefix() . 'clients c', 'c.userid = s.clientid', 'left');

        if ($customerId) {
            $this->db->where('s.clientid', (int) $customerId);
        }

        $rows = $this->db
            ->order_by('s.id', 'DESC')
            ->limit($pag['per_page'], $pag['offset'])
            ->get()
            ->result_array();

        $total = $this->db->count_all_results(db_prefix() . 'subscriptions', false);

        $out = array_map(function ($r) use ($status) {
            $now = time();
            $end = strtotime($r['ends_at'] ?? '1970-01-01');
            $daysLeft = (int) ceil(($end - $now) / 86400);
            $r['days_until_renewal'] = $daysLeft;
            $r['is_active']  = $end > $now;
            $r['is_expiring'] = $end > $now && $daysLeft <= 14;
            $r['is_expired'] = $end <= $now;
            return $r;
        }, $rows);

        if ($status) {
            $out = array_values(array_filter($out, fn($r) => $r['is_' . $status] === true));
        }

        Response::ok($out, [
            'pagination' => [
                'page'        => $pag['page'],
                'per_page'    => $pag['per_page'],
                'total'       => (int) $total,
                'total_pages' => (int) ceil($total / $pag['per_page']),
            ],
        ]);
    }

    /** GET /api/v1/subscriptions/{id} */
    public function show($id = null): void
    {
        if (!$this->requireScope('subscriptions:read')) {
            return;
        }
        if (!$id) {
            Response::error(400, 'validation_failed', 'Subscription id is required');
            return;
        }
        $row = $this->db
            ->select('s.*, c.company as customer_company, c.userid as customer_id')
            ->from(db_prefix() . 'subscriptions s')
            ->join(db_prefix() . 'clients c', 'c.userid = s.clientid', 'left')
            ->where('s.id', (int) $id)
            ->get()
            ->row_array();
        if (!$row) {
            Response::error(404, 'not_found', "Subscription {$id} not found");
            return;
        }
        $end = strtotime($row['ends_at'] ?? '1970-01-01');
        $row['days_until_renewal'] = (int) ceil(($end - time()) / 86400);
        $row['is_active']    = $end > time();
        $row['is_expiring']  = $end > time() && $row['days_until_renewal'] <= 14;
        $row['is_expired']   = $end <= time();
        Response::ok($row);
    }
}
