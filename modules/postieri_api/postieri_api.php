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

// PSR-4 autoload for module classes (Perfex doesn't autoload our src/ by default)
require_once __DIR__ . '/../../vendor/autoload.php';

// Register module init / hooks
hooks()->add_action('admin_init', 'postieri_api_module_init');
hooks()->add_action('admin_init', 'postieri_api_register_settings');

// Activation / deactivation
hooks()->add_action('module_activate_postieri_api', 'postieri_api_activate');
hooks()->add_action('module_deactivate_postieri_api', 'postieri_api_deactivate');

// Cron
hooks()->add_action('after_cron_run', 'postieri_api_subscription_cron');

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
