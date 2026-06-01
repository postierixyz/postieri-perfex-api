<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Postieri API
Description: REST API + webhooks for Perfex CRM, by Postieri XYZ
Version: 0.1.0
Requires at least: 3.2.*
Author: Postieri XYZ L.L.C.
Author URI: https://postieri.xyz
*/

// Module constants
define('POSTIERI_API_VERSION', '0.1.0');
define('POSTIERI_API_NAMESPACE', 'Perfexcrm\\Postieri\\Api');

// PSR-4 autoload for our internal classes (Perfex doesn't autoload our
// `src/` directory on its own).
//
// We do NOT require `vendor/autoload.php` because:
//   1. The module lives in perfex_crm/modules/postieri_api/ — its own
//      `__DIR__` has no vendor/ folder.
//   2. Going up two levels lands on Perfex's own vendor/, which is huge
//      and would re-load Guzzle, Symfony deps, etc. for no reason.
//   3. A future `composer install` inside this module is still supported
//      if you add the optional dev dependencies — we just don't pull them
//      into production.
//
// We register a slim PSR-4 autoloader for our namespace
// (Perfexcrm\Postieri\Api\) that points at `src/`. If you install the
// module's own vendor/ (composer install), it will take precedence
// because we register first.
if (!class_exists('Perfexcrm\\Postieri\\Api\\Http\\Response', false)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Perfexcrm\\Postieri\\Api\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Register module init / hooks
hooks()->add_action('admin_init', 'postieri_api_module_init');
hooks()->add_action('admin_init', 'postieri_api_register_settings');

// Activation / deactivation
hooks()->add_action('module_activate_postieri_api', 'postieri_api_activate');
hooks()->add_action('module_deactivate_postieri_api', 'postieri_api_deactivate');

// Cron
hooks()->add_action('after_cron_run', 'postieri_api_subscription_cron');

// Live event hooks — wire into Perfex events
// Perfex fires `invoice_status_changed` with payload ['invoice_id', 'status'].
// Status code 2 = Paid. We intercept and filter to "paid" transitions.
hooks()->add_action('invoice_status_changed', 'postieri_api_on_invoice_status_changed', 10, 1);

// Payment added (covers gateways like ideal/Stripe/Mollie, manual recording)
hooks()->add_action('after_payment_added', 'postieri_api_on_payment_added', 10, 1);

// Lead lifecycle
hooks()->add_action('lead_status_changed_to_junk', 'postieri_api_on_lead_lost', 10, 1);
hooks()->add_action('lead_created', 'postieri_api_on_lead_created', 10, 1);

// Subscription lifecycle (custom; Perfex doesn't fire these by default)
hooks()->add_action('postieri_subscription_created', 'postieri_api_on_subscription_created', 10, 1);

/**
 * Register admin sidebar menu.
 */
function postieri_api_module_init(): void
{
    $CI = &get_instance();
    if (!isset($CI->app_menu)) {
        return;
    }
    $CI->app_menu->add_sidebar_menu_item('postieri-api', [
        'name'     => _l('postieri_api'),
        'href'     => admin_url('postieri_api'),
        'icon'     => 'fa fa-plug',
        'position' => 50,
    ]);
}

/**
 * Register module settings under Setup → Settings → API.
 */
function postieri_api_register_settings(): void
{
    $CI = &get_instance();
    if (!isset($CI->app)) {
        return;
    }
    $CI->app->add_settings_section_child('api', 'postieri_api', [
        'name'     => _l('postieri_api'),
        'view'     => 'postieri_api/settings',
        'position' => 10,
        'icon'     => 'fa fa-plug',
    ]);
}

/**
 * Activation: create tables, set default options.
 */
function postieri_api_activate(): void
{
    $CI = &get_instance();
    require_once __DIR__ . '/config/install.php';
    postieri_api_install($CI->db);

    if (get_option('postieri_api_enabled') === false) {
        add_option('postieri_api_enabled', '1');
    }
    if (get_option('postieri_api_rate_limit_per_min') === false) {
        add_option('postieri_api_rate_limit_per_min', '100');
    }
    if (get_option('postieri_api_rate_limit_per_hour') === false) {
        add_option('postieri_api_rate_limit_per_hour', '1000');
    }
    if (get_option('postieri_api_default_token_scopes') === false) {
        add_option('postieri_api_default_token_scopes', '["*"]');
    }
}

/**
 * Deactivation: keep tables (safer), just turn the API off.
 */
function postieri_api_deactivate(): void
{
    update_option('postieri_api_enabled', '0');
}

/**
 * Daily cron: poll subscriptions for expiring/expired, retry failed webhooks.
 */
function postieri_api_subscription_cron(): void
{
    if (get_option('postieri_api_enabled') !== '1') {
        return;
    }
    $CI = &get_instance();
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch_subscription_expiring();
    $CI->webhook_dispatcher->retry_failed();
}

/**
 * Hook: invoice status changed.
 * Perfex fires this from application/helpers/invoices_helper.php:390
 * with payload: ['invoice_id' => int, 'status' => int]
 *
 * Status codes (Perfex Invoices_model):
 *   1 = Unpaid, 2 = Paid, 3 = Partially Paid,
 *   4 = Overdue, 5 = Cancelled, 6 = Draft
 *
 * @param array $data {invoice_id: int, status: int}
 */
function postieri_api_on_invoice_status_changed(array $data): void
{
    if (get_option('postieri_api_enabled') !== '1') return;
    if (empty($data['invoice_id']) || !isset($data['status'])) return;

    $status = (int) $data['status'];
    $invoiceId = (int) $data['invoice_id'];

    if ($status === 2) {
        $CI = &get_instance();
        $CI->load->model('invoices_model');
        $inv = $CI->invoices_model->get($invoiceId);
        if (!$inv) return;
        $CI->load->library('postieri_api/webhook_dispatcher');
        $CI->webhook_dispatcher->dispatch('invoice.paid', [
            'invoice_id'  => (int) $inv->id,
            'customer_id' => (int) $inv->clientid,
            'total'       => (float) $inv->total,
            'number'      => (string) $inv->number,
        ]);
        return;
    }

    // For other transitions, fire a generic event for completeness
    $CI = &get_instance();
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch('invoice.status_changed', [
        'invoice_id' => $invoiceId,
        'status'     => $status,
    ]);
}

/**
 * Hook: payment recorded against an invoice.
 * Fires from application/models/Payments_model.php on insert.
 * @param int $paymentId
 */
function postieri_api_on_payment_added(int $paymentId): void
{
    if (get_option('postieri_api_enabled') !== '1') return;
    $CI = &get_instance();
    $CI->load->model('payments_model');
    $payment = $CI->db->get_where(db_prefix() . 'invoicepaymentrecords', ['id' => $paymentId])->row();
    if (!$payment) return;
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch('payment.received', [
        'payment_id'  => (int) $payment->id,
        'invoice_id'  => (int) $payment->invoiceid,
        'amount'      => (float) $payment->amount,
        'date'        => (string) $payment->date,
    ]);
}

/**
 * Hook: lead status changed to "lost/junk".
 * @param int $leadId
 */
function postieri_api_on_lead_lost(int $leadId): void
{
    if (get_option('postieri_api_enabled') !== '1') return;
    $CI = &get_instance();
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch('lead.lost', [
        'lead_id' => (int) $leadId,
    ]);
}

/**
 * Hook: lead created.
 * @param int $leadId
 */
function postieri_api_on_lead_created(int $leadId): void
{
    if (get_option('postieri_api_enabled') !== '1') return;
    $CI = &get_instance();
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch('lead.created', [
        'lead_id' => (int) $leadId,
    ]);
}

/**
 * Hook: subscription created (custom event we fire from cron or controllers).
 * Perfex doesn't have a built-in subscription_created hook, so callers can
 * fire this manually after creating a subscription record.
 * @param int $subscriptionId
 */
function postieri_api_on_subscription_created(int $subscriptionId): void
{
    if (get_option('postieri_api_enabled') !== '1') return;
    $CI = &get_instance();
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch('subscription.created', [
        'subscription_id' => (int) $subscriptionId,
    ]);
}
