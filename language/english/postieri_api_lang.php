<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * English (default) language strings for the Postieri API module.
 * Perfex falls back to this file when a translation for the current
 * admin language is missing, so we don't need to ship per-locale
 * copies to start with.
 *
 * To add a new locale, copy this file to:
 *   language/portuguese_br/postieri_api_lang.php
 *   language/german/postieri_api_lang.php
 *   ...
 * and translate only the values.
 */

$lang['postieri_api']                     = 'Postieri API';
$lang['postieri_api_tokens']              = 'Postieri API · Tokens';
$lang['postieri_api_token_name']          = 'Token name';
$lang['postieri_api_token_scopes']        = 'Scopes';
$lang['postieri_api_token_create']        = 'Create token';
$lang['postieri_api_token_created_once']  = 'Copy this token now — it will not be shown again.';
$lang['postieri_api_token_issued']        = 'New token issued successfully.';
$lang['postieri_api_token_issue_failed']  = 'Could not issue token. Please try again.';
$lang['postieri_api_token_revoked']       = 'Token revoked.';
$lang['postieri_api_token_revoke']        = 'Revoke';
$lang['postieri_api_token_created_at']    = 'Created';
$lang['postieri_api_token_last_used']     = 'Last used';
$lang['postieri_api_token_expires_at']    = 'Expires';
$lang['postieri_api_token_never']         = 'Never';
$lang['postieri_api_token_no_tokens']     = 'No tokens yet — create one above.';
$lang['postieri_api_token_copy']          = 'Copy';
$lang['postieri_api_token_copied']        = 'Copied!';
$lang['postieri_api_token_confirm_revoke']= 'Revoke this token? Existing integrations using it will lose access immediately.';
$lang['postieri_api_module']              = 'Postieri API';
$lang['postieri_api_version']             = 'Version';
$lang['postieri_api_intro']               = 'REST API + webhooks for Perfex CRM. Issue tokens below and use them in the Authorization: Bearer header against the /api/v1/ endpoints.';
$lang['postieri_api_scopes_help']         = 'Comma-separated list. Use * for full access, or a specific subset like customers:read,invoices:write.';
$lang['postieri_api_openapi_link']        = 'OpenAPI reference (docs/openapi.yaml in the repo)';
