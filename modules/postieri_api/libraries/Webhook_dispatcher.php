<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Webhook dispatcher.
 *
 * - `dispatch($event, $payload)` — synchronous fire-and-log for live events
 *   (called from the controllers or from Perfex hooks).
 * - `dispatch_subscription_expiring()` — daily cron: scan subscriptions
 *   ending in <= 14 days, emit `subscription.expiring` event.
 * - `retry_failed()` — daily cron: re-attempt failed deliveries that are due.
 *
 * Signing: every delivery is signed with HMAC-SHA256 over the JSON body,
 * using the webhook's secret. The signature goes into the
 * `X-Postieri-Signature` header.
 */
class Webhook_dispatcher
{
    private const TIMEOUT_SECONDS = 10;
    private const USER_AGENT      = 'Postieri-Perfex-Webhook/1.0';

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->model('postieri_api/postieri_webhook_model');
        $this->model = $this->CI->postieri_webhook_model;
    }

    /**
     * Dispatch a live event to all subscribed webhooks.
     */
    public function dispatch(string $event, array $payload): void
    {
        $subs = $this->model->for_event($event);
        if (!$subs) return;

        $body = json_encode(
            [
                'event'     => $event,
                'timestamp' => date('c'),
                'data'      => $payload,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        foreach ($subs as $sub) {
            $logId = $this->model->log_delivery((int) $sub['id'], $event, $body);
            $this->deliver((int) $sub['id'], $sub['url'], $sub['secret'], $body, $logId);
        }
    }

    /**
     * Cron: emit `subscription.expiring` for subscriptions ending in <= 14 days.
     */
    public function dispatch_subscription_expiring(): void
    {
        $rows = $this->CI->db
            ->select('id, clientid, name, ends_at')
            ->from(db_prefix() . 'subscriptions')
            ->where('ends_at <=', date('Y-m-d', strtotime('+14 days')))
            ->where('ends_at >', date('Y-m-d'))
            ->get()
            ->result_array();
        foreach ($rows as $r) {
            $this->dispatch('subscription.expiring', [
                'subscription_id'  => (int) $r['id'],
                'customer_id'      => (int) $r['clientid'],
                'name'             => $r['name'],
                'ends_at'          => $r['ends_at'],
                'days_until_renewal' => (int) ceil((strtotime($r['ends_at']) - time()) / 86400),
            ]);
        }

        // Expired
        $rows = $this->CI->db
            ->select('id, clientid, name, ends_at')
            ->from(db_prefix() . 'subscriptions')
            ->where('ends_at <=', date('Y-m-d'))
            ->get()
            ->result_array();
        foreach ($rows as $r) {
            $this->dispatch('subscription.expired', [
                'subscription_id' => (int) $r['id'],
                'customer_id'     => (int) $r['clientid'],
                'name'            => $r['name'],
                'ends_at'         => $r['ends_at'],
            ]);
        }
    }

    /**
     * Cron: retry failed deliveries that are due.
     */
    public function retry_failed(): void
    {
        $due = $this->model->due_for_retry();
        foreach ($due as $d) {
            $sub = $this->model->find((int) $d['webhook_id']);
            if (!$sub || (int) $sub['is_active'] !== 1) continue;
            $this->deliver(
                (int) $sub['id'],
                $sub['url'],
                $sub['secret'],
                $d['payload'],
                (int) $d['id']
            );
        }
    }

    /**
     * Send a single HTTP delivery and update its log row.
     */
    private function deliver(int $webhookId, string $url, string $secret, string $body, int $logId): void
    {
        $signature = hash_hmac('sha256', $body, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Postieri-Event: ' . $this->extract_event($body),
                'X-Postieri-Signature: sha256=' . $signature,
                'X-Postieri-Delivery-Id: ' . $logId,
                'User-Agent: ' . self::USER_AGENT,
            ],
        ]);
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        // 2xx = delivered; everything else = failed
        if ($response !== false && $code >= 200 && $code < 300) {
            $this->model->mark_delivered($logId, $code, (string) $response);
        } else {
            $body_out = $response === false ? "cURL error: {$err}" : (string) $response;
            $this->model->mark_failed($logId, $code ?: 0, $body_out);
        }
    }

    private function extract_event(string $body): string
    {
        $data = json_decode($body, true);
        return $data['event'] ?? 'unknown';
    }
}
