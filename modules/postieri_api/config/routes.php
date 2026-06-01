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
