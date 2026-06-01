<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Model for webhook subscriptions and their delivery log.
 *
 * Tables: tblpostieri_api_webhooks, tblpostieri_api_webhook_deliveries
 */
class Postieri_webhook_model extends App_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->table       = db_prefix() . 'postieri_api_webhooks';
        $this->table_logs  = db_prefix() . 'postieri_api_webhook_deliveries';
    }

    public function all(): array
    {
        return $this->db
            ->order_by('created_at', 'DESC')
            ->get($this->table)
            ->result_array();
    }

    public function find(int $id): ?array
    {
        $row = $this->db->where('id', $id)->get($this->table)->row_array();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->table, [
            'name'       => $data['name'],
            'url'        => $data['url'],
            'events'     => json_encode($data['events']),
            'secret'     => $data['secret'],
            'is_active'  => $data['is_active'] ?? 1,
            'created_by' => $data['created_by'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->db->insert_id();
    }

    public function update(int $id, array $data): bool
    {
        if (isset($data['events']) && is_array($data['events'])) {
            $data['events'] = json_encode($data['events']);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return (bool) $this->db->where('id', $id)->update($this->table, $data);
    }

    public function delete(int $id): bool
    {
        $this->db->where('id', $id)->delete($this->table_logs);
        return (bool) $this->db->where('id', $id)->delete($this->table);
    }

    /**
     * Active webhook subscribers for an event.
     */
    public function for_event(string $event): array
    {
        $rows = $this->db->where('is_active', 1)->get($this->table)->result_array();
        $out = [];
        foreach ($rows as $r) {
            $events = json_decode($r['events'], true) ?: [];
            if (in_array($event, $events, true) || in_array('*', $events, true)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public function deliveries(int $webhookId, int $limit = 25, int $offset = 0): array
    {
        return $this->db
            ->where('webhook_id', $webhookId)
            ->order_by('id', 'DESC')
            ->limit($limit, $offset)
            ->get($this->table_logs)
            ->result_array();
    }

    public function count_deliveries(int $webhookId): int
    {
        return (int) $this->db
            ->where('webhook_id', $webhookId)
            ->count_all_results($this->table_logs);
    }

    /**
     * Find failed deliveries that are ready for retry.
     */
    public function due_for_retry(): array
    {
        return $this->db
            ->where('delivered_at IS NULL', null, false)
            ->where('failed_at IS NOT NULL', null, false)
            ->where('attempt <', 5)
            ->where('(next_retry_at IS NULL OR next_retry_at <= NOW())', null, false)
            ->order_by('id', 'ASC')
            ->limit(50)
            ->get($this->table_logs)
            ->result_array();
    }

    public function log_delivery(int $webhookId, string $event, string $payload): int
    {
        $this->db->insert($this->table_logs, [
            'webhook_id' => $webhookId,
            'event'      => $event,
            'payload'    => $payload,
            'attempt'    => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->insert_id();
    }

    public function mark_delivered(int $logId, int $responseStatus, string $responseBody): void
    {
        $this->db->where('id', $logId)->update($this->table_logs, [
            'response_status' => $responseStatus,
            'response_body'   => substr($responseBody, 0, 65000),
            'delivered_at'    => date('Y-m-d H:i:s'),
            'next_retry_at'   => null,
            'failed_at'       => null,
        ]);
    }

    public function mark_failed(int $logId, int $responseStatus, string $responseBody): void
    {
        // Get current attempt
        $row = $this->db->where('id', $logId)->get($this->table_logs)->row_array();
        $attempt = (int) ($row['attempt'] ?? 1) + 1;
        $backoff = $this->backoff_seconds($attempt);
        $this->db->where('id', $logId)->update($this->table_logs, [
            'response_status' => $responseStatus,
            'response_body'   => substr($responseBody, 0, 65000),
            'attempt'         => $attempt,
            'failed_at'       => date('Y-m-d H:i:s'),
            'next_retry_at'   => $attempt < 5
                ? date('Y-m-d H:i:s', strtotime("+{$backoff} seconds"))
                : null,
        ]);
    }

    /**
     * Exponential backoff in seconds. Max 5 attempts.
     * Attempt 2 = 60s, 3 = 300s (5m), 4 = 1800s (30m), 5 = 7200s (2h).
     */
    private function backoff_seconds(int $attempt): int
    {
        return match ($attempt) {
            2 => 60,
            3 => 300,
            4 => 1800,
            5 => 7200,
            default => 43200, // 12h, capped
        };
    }
}
