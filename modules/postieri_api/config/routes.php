<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Postieri API — public route table.
 * Only API routes live here. Admin pages (/admin/postieri_api/...) are
 * handled by the Postieri_api admin controller, routed by Perfex's
 * admin_url() helper.
 */

// --- /api/v1/auth/* — token lifecycle ---
$route['api/v1/auth/token']        = 'postieri_api/auth/create_token';
$route['api/v1/auth/token/(:num)'] = 'postieri_api/auth/delete_token/$1';
$route['api/v1/auth/tokens']       = 'postieri_api/auth/list_tokens';

// --- /api/v1/customers/* ---
$route['api/v1/customers']                = 'postieri_api/customers/index';
$route['api/v1/customers/create']         = 'postieri_api/customers/create';
$route['api/v1/customers/(:num)']         = 'postieri_api/customers/show/$1';
$route['api/v1/customers/(:num)/update']  = 'postieri_api/customers/update/$1';
$route['api/v1/customers/(:num)/delete']  = 'postieri_api/customers/destroy/$1';

// --- /api/v1/contacts/* ---
$route['api/v1/contacts']                = 'postieri_api/contacts/index';
$route['api/v1/contacts/create']         = 'postieri_api/contacts/create';
$route['api/v1/contacts/(:num)']         = 'postieri_api/contacts/show/$1';
$route['api/v1/contacts/(:num)/update']  = 'postieri_api/contacts/update/$1';
$route['api/v1/contacts/(:num)/delete']  = 'postieri_api/contacts/destroy/$1';

// --- /api/v1/invoices/* ---
$route['api/v1/invoices']                = 'postieri_api/invoices/index';
$route['api/v1/invoices/create']         = 'postieri_api/invoices/create';
$route['api/v1/invoices/(:num)']         = 'postieri_api/invoices/show/$1';
$route['api/v1/invoices/(:num)/update']  = 'postieri_api/invoices/update/$1';
$route['api/v1/invoices/(:num)/pdf']     = 'postieri_api/invoices/pdf/$1';

// --- /api/v1/subscriptions/* ---
$route['api/v1/subscriptions']            = 'postieri_api/subscriptions/index';
$route['api/v1/subscriptions/(:num)']     = 'postieri_api/subscriptions/show/$1';

// --- /api/v1/leads/* ---
$route['api/v1/leads']                        = 'postieri_api/leads/index';
$route['api/v1/leads/create']                 = 'postieri_api/leads/create';
$route['api/v1/leads/(:num)']                 = 'postieri_api/leads/show/$1';
$route['api/v1/leads/(:num)/update']          = 'postieri_api/leads/update/$1';
$route['api/v1/leads/(:num)/delete']          = 'postieri_api/leads/destroy/$1';
$route['api/v1/leads/(:num)/convert']         = 'postieri_api/leads/convert/$1';

// --- /api/v1/webhooks/* ---
$route['api/v1/webhooks']                          = 'postieri_api/webhooks/index';
$route['api/v1/webhooks/create']                   = 'postieri_api/webhooks/create';
$route['api/v1/webhooks/(:num)']                   = 'postieri_api/webhooks/show/$1';
$route['api/v1/webhooks/(:num)/update']            = 'postieri_api/webhooks/update/$1';
$route['api/v1/webhooks/(:num)/delete']            = 'postieri_api/webhooks/destroy/$1';
$route['api/v1/webhooks/(:num)/deliveries']        = 'postieri_api/webhooks/deliveries/$1';
