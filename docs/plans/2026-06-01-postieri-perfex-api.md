# Postieri Perfex API — Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to execute task-by-task.
> **Prereq:** Read `docs/adr/0001-auth-routing-versioning.md` first.

**Goal:** Ship a production-ready REST API + webhooks module for Perfex CRM 3.4.1 that Postieri XYZ can use for email automation, invoice PDF generation, and subscription expiry alerts, and that is productizable as a public CodeCanyon/GitHub offering.

**Architecture:** HMVC module at `modules/postieri_api/` inside Perfex 3.4.1. PHP 8.1+, CodeIgniter 3, MySQL, no external infrastructure. Token auth + scopes + rate limiting. Webhook dispatcher with retry + cron-polled subscription events.

**Tech Stack:**
- PHP 8.1+ (Perfex requirement)
- CodeIgniter 3.2+ (HMVC, Perfex core)
- MySQL 5.7+ / MariaDB 10.3+
- Guzzle 7 (already in Perfex main `composer.json`)
- Argon2id for token hashing (`password_hash` native PHP)
- PHPUnit 10 (for unit tests of pure-PHP services)

**Module location in production:** `<perfex-root>/modules/postieri_api/`
**Dev source:** `/workspace/company/postieri-perfex-api/` (this repo) — module files are developed here, then copied to dev Perfex instance for testing.

---

## Phase 0 — Project Setup

### Task 0.1: Create module skeleton directories in this repo

**Files (create empty):**
- `modules/postieri_api/postieri_api.php` (main file)
- `modules/postieri_api/config/.gitkeep`
- `modules/postieri_api/controllers/.gitkeep`
- `modules/postieri_api/models/.gitkeep`
- `modules/postieri_api/views/.gitkeep`
- `modules/postieri_api/language/english/.gitkeep`
- `modules/postieri_api/src/Auth/.gitkeep`
- `modules/postieri_api/src/Http/.gitkeep`
- `modules/postieri_api/src/Webhooks/.gitkeep`
- `modules/postieri_api/hooks/.gitkeep`
- `tests/.gitkeep`

**Step 1:** `mkdir -p` the directories.
**Step 2:** Add `.gitkeep` to each empty dir.
**Step 3:** Commit.

```bash
cd /workspace/company/postieri-perfex-api
git add . && git commit -m "chore: create module skeleton directory structure"
```

---

### Task 0.2: Add `.gitignore`

**File:** `.gitignore` (project root)

**Content:**
```
/vendor/
/.idea/
/.vscode/
*.log
*.swp
*.swo
.DS_Store
.env
.phpunit.cache/
.phpunit.result.cache
tests/coverage/
```

Commit: `chore: add .gitignore`

---

### Task 0.3: Add `composer.json` for module dependencies

**File:** `composer.json` (project root)

**Content:**
```json
{
    "name": "postierixyz/postieri-perfex-api",
    "description": "REST API + webhooks module for Perfex CRM",
    "type": "perfex-module",
    "license": "MIT",
    "require": {
        "php": "^8.1",
        "ext-json": "*",
        "ext-pdo": "*",
        "guzzlehttp/guzzle": "^7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "Perfexcrm\\Postieri\\Api\\": "modules/postieri_api/src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Perfexcrm\\Postieri\\Api\\Tests\\": "tests/"
        }
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": true
        }
    }
}
```

**Step 1:** `composer install`
**Expected:** vendor/ created, no errors.
**Step 2:** Commit composer.json + composer.lock.

---

### Task 0.4: Add `phpunit.xml`

**File:** `phpunit.xml` (project root)

**Content:**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Commit: `chore: add phpunit configuration`

---

## Phase 1 — Module Entry Point

### Task 1.1: Create main module file with header

**File:** `modules/postieri_api/postieri_api.php`

**Content:**
```php
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

// PSR-4 autoload for module classes (Perfex doesn't autoload our src/ by default)
require_once __DIR__ . '/../../vendor/autoload.php';

// Register module init
hooks()->add_action('admin_init', 'postieri_api_module_init');
hooks()->add_action('admin_init', 'postieri_api_register_settings');

// Activation hook (creates DB tables)
hooks()->add_action('module_activate_postieri_api', 'postieri_api_activate');

// Cron hook for subscription polling
hooks()->add_action('after_cron_run', 'postieri_api_subscription_cron');

/**
 * Register module settings + menu
 */
function postieri_api_module_init(): void
{
    $CI = &get_instance();

    // Register admin menu link under Setup
    $CI->app_menu->add_sidebar_menu_item('postieri-api', [
        'name'     => 'Postieri API',
        'href'     => admin_url('postieri_api'),
        'icon'     => 'fa fa-plug',
        'position' => 50,
    ]);
}

/**
 * Register module settings in Setup → Settings
 */
function postieri_api_register_settings(): void
{
    $CI = &get_instance();
    $CI->app->add_settings_section_child('api', 'postieri_api', [
        'name'     => 'Postieri API',
        'view'     => 'postieri_api/settings',
        'position' => 10,
        'icon'     => 'fa fa-plug',
    ]);
}

/**
 * Activation: create tables, set default options
 */
function postieri_api_activate(): void
{
    $CI = &get_instance();
    require_once __DIR__ . '/config/install.php';
    postieri_api_install($CI->db);
    add_option('postieri_api_enabled', '1');
    add_option('postieri_api_rate_limit_per_min', '100');
    add_option('postieri_api_rate_limit_per_hour', '1000');
}

/**
 * Daily cron: poll subscriptions for expiring
 */
function postieri_api_subscription_cron(): void
{
    $CI = &get_instance();
    if (get_option('postieri_api_enabled') !== '1') {
        return;
    }
    $CI->load->library('postieri_api/webhook_dispatcher');
    $CI->webhook_dispatcher->dispatch_subscription_expiring();
}
```

**Step 1:** Create file.
**Step 2:** Commit: `feat(module): add postieri_api main file with hooks and module header`

---

### Task 1.2: Create admin controller stub

**File:** `modules/postieri_api/controllers/Postieri_api.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Postieri_api extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        // Permission check helper from Perfex
        if (staff_cant('view', 'settings')) {
            access_denied('Postieri API');
        }
    }

    /**
     * Settings page (tokens, webhooks, rate limits overview)
     */
    public function index(): void
    {
        $data['title'] = 'Postieri API';
        $this->load->view('postieri_api/index', $data);
    }

    /**
     * Token management page
     */
    public function tokens(): void
    {
        $data['title'] = 'API Tokens';
        $this->load->view('postieri_api/tokens', $data);
    }

    /**
     * Webhook subscriptions page
     */
    public function webhooks(): void
    {
        $data['title'] = 'Webhook Subscriptions';
        $this->load->view('postieri_api/webhooks', $data);
    }
}
```

Commit: `feat(module): add admin controller stub with index/tokens/webhooks pages`

---

## Phase 2 — Database Schema

### Task 2.1: Create install.php with all 4 tables

**File:** `modules/postieri_api/config/install.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Install/upgrade: creates module tables. Idempotent — safe to re-run.
 */
function postieri_api_install($db): void
{
    $prefix = db_prefix();

    // Tokens
    if (!$db->table_exists($prefix . 'postieri_api_tokens')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_tokens` (
            `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) UNSIGNED NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `token_hash` VARCHAR(255) NOT NULL,
            `scopes` TEXT,
            `last_used_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_token_hash` (`token_hash`(64))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Webhook subscribers
    if (!$db->table_exists($prefix . 'postieri_api_webhooks')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_webhooks` (
            `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `url` VARCHAR(500) NOT NULL,
            `events` TEXT NOT NULL,
            `secret` VARCHAR(64) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT(11) UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            INDEX `idx_is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Webhook delivery log
    if (!$db->table_exists($prefix . 'postieri_api_webhook_deliveries')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_webhook_deliveries` (
            `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `webhook_id` INT(11) UNSIGNED NOT NULL,
            `event` VARCHAR(100) NOT NULL,
            `payload` LONGTEXT NOT NULL,
            `response_status` INT(11) NULL,
            `response_body` TEXT NULL,
            `attempt` INT(11) NOT NULL DEFAULT 1,
            `delivered_at` DATETIME NULL,
            `next_retry_at` DATETIME NULL,
            `failed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_webhook_id` (`webhook_id`),
            INDEX `idx_event` (`event`),
            INDEX `idx_next_retry_at` (`next_retry_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }

    // Rate limit log
    if (!$db->table_exists($prefix . 'postieri_api_rate_log')) {
        $db->query("CREATE TABLE `{$prefix}postieri_api_rate_log` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `token_id` INT(11) UNSIGNED NOT NULL,
            `endpoint` VARCHAR(255) NOT NULL,
            `method` VARCHAR(10) NOT NULL,
            `ip` VARCHAR(45) NOT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_token_id_created` (`token_id`, `created_at`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    }
}
```

**Step 1:** Create file.
**Step 2:** Copy module to dev Perfex, activate via `Setup → Modules`, verify 4 tables exist in MySQL.
**Step 3:** Commit: `feat(db): add install.php with 4 tables (tokens, webhooks, deliveries, rate_log)`

---

## Phase 3 — Response + Rate Limiter Infrastructure

### Task 3.1: Create Response class (envelope)

**File:** `modules/postieri_api/src/Http/Response.php`

**Content:**
```php
<?php

namespace Perfexcrm\Postieri\Api\Http;

use CI_Controller;

class Response
{
    public static function ok(CI_Controller $CI, mixed $data = null, array $meta = []): void
    {
        $CI->output
            ->set_status_header(200)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode([
                'status' => true,
                'data'   => $data,
                'meta'   => $meta,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function created(CI_Controller $CI, mixed $data = null): void
    {
        $CI->output
            ->set_status_header(201)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode([
                'status' => true,
                'data'   => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function noContent(CI_Controller $CI): void
    {
        $CI->output->set_status_header(204);
    }

    public static function error(
        CI_Controller $CI,
        int $httpStatus,
        string $code,
        string $message,
        array $details = []
    ): void {
        $CI->output
            ->set_status_header($httpStatus)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode([
                'status' => false,
                'error'  => [
                    'code'    => $code,
                    'message' => $message,
                    'details' => (object) $details,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
```

Commit: `feat(http): add Response class with ok/created/noContent/error methods`

---

### Task 3.2: Create RateLimiter class

**File:** `modules/postieri_api/src/Http/RateLimiter.php`

**Content:**
```php
<?php

namespace Perfexcrm\Postieri\Api\Http;

use CI_Controller;
use CI_DB_driver;

class RateLimiter
{
    public function __construct(
        private CI_DB_driver $db,
        private int $tokenId,
        private int $perMinute,
        private int $perHour
    ) {}

    /**
     * Check rate limit. Returns ['allowed' => bool, 'remaining_min' => int, 'reset_min' => int].
     * Logs the request regardless.
     */
    public function check(string $endpoint, string $method, string $ip): array
    {
        $now = date('Y-m-d H:i:s');
        $oneMinAgo = date('Y-m-d H:i:s', strtotime('-1 minute'));
        $oneHourAgo = date('Y-m-d H:i:s', strtotime('-1 hour'));

        // Count requests in last minute
        $this->db->where('token_id', $this->tokenId);
        $this->db->where('created_at >=', $oneMinAgo);
        $countMin = $this->db->count_all_results(db_prefix() . 'postieri_api_rate_log');

        $this->db->where('token_id', $this->tokenId);
        $this->db->where('created_at >=', $oneHourAgo);
        $countHour = $this->db->count_all_results(db_prefix() . 'postieri_api_rate_log');

        // Log this request
        $this->db->insert(db_prefix() . 'postieri_api_rate_log', [
            'token_id'   => $this->tokenId,
            'endpoint'   => $endpoint,
            'method'     => $method,
            'ip'         => $ip,
            'created_at' => $now,
        ]);

        $allowed = $countMin < $this->perMinute && $countHour < $this->perHour;

        return [
            'allowed'      => $allowed,
            'remaining_min' => max(0, $this->perMinute - $countMin - 1),
            'remaining_hour' => max(0, $this->perHour - $countHour - 1),
            'reset_min'    => 60,
            'reset_hour'   => 3600,
        ];
    }
}
```

Commit: `feat(rate-limit): add RateLimiter class with sliding window check`

---

### Task 3.3: Create TokenService class

**File:** `modules/postieri_api/src/Auth/TokenService.php`

**Content:**
```php
<?php

namespace Perfexcrm\Postieri\Api\Auth;

use CI_DB_driver;

class TokenService
{
    public function __construct(private CI_DB_driver $db) {}

    /**
     * Issue a new token. Returns ['token' => '...', 'id' => N, 'expires_at' => '...'].
     * The plain token is shown to the user ONCE.
     */
    public function issue(int $userId, string $name, array $scopes = [], ?string $expiresAt = null): array
    {
        $plain = bin2hex(random_bytes(32)); // 64 hex chars
        $hash = password_hash($plain, PASSWORD_ARGON2ID);

        $now = date('Y-m-d H:i:s');
        $this->db->insert(db_prefix() . 'postieri_api_tokens', [
            'user_id'    => $userId,
            'name'       => $name,
            'token_hash' => $hash,
            'scopes'     => json_encode($scopes),
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return [
            'id'         => $this->db->insert_id(),
            'token'      => $plain,
            'expires_at' => $expiresAt,
            'scopes'     => $scopes,
        ];
    }

    /**
     * Verify a Bearer token. Returns token row (with user_id, scopes) or null.
     */
    public function verify(string $plain): ?array
    {
        // Fetch all non-revoked, non-expired tokens. With modest scale this is fine;
        // for very large deployments, switch to a token-id prefix lookup.
        $rows = $this->db
            ->where('revoked_at IS NULL', null, false)
            ->where('(expires_at IS NULL OR expires_at > NOW())', null, false)
            ->get(db_prefix() . 'postieri_api_tokens')
            ->result_array();

        foreach ($rows as $row) {
            if (password_verify($plain, $row['token_hash'])) {
                // Update last_used_at (best-effort, don't fail the request if this errors)
                $this->db->where('id', $row['id']);
                $this->db->update(db_prefix() . 'postieri_api_tokens', [
                    'last_used_at' => date('Y-m-d H:i:s'),
                ]);
                $row['scopes'] = json_decode($row['scopes'] ?? '[]', true) ?: [];
                return $row;
            }
        }

        return null;
    }

    /**
     * Revoke a token by id.
     */
    public function revoke(int $tokenId): bool
    {
        return $this->db
            ->where('id', $tokenId)
            ->update(db_prefix() . 'postieri_api_tokens', [
                'revoked_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * List all tokens (admin view). Hashed values are stripped.
     */
    public function listAll(): array
    {
        $rows = $this->db
            ->order_by('created_at', 'DESC')
            ->get(db_prefix() . 'postieri_api_tokens')
            ->result_array();
        return array_map(function ($r) {
            unset($r['token_hash']);
            $r['scopes'] = json_decode($r['scopes'] ?? '[]', true) ?: [];
            return $r;
        }, $rows);
    }
}
```

Commit: `feat(auth): add TokenService with issue/verify/revoke/listAll`

---

### Task 3.4: Create API base controller

**File:** `modules/postieri_api/controllers/Api_v1.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Auth\TokenService;
use Perfexcrm\Postieri\Api\Http\Response;
use Perfexcrm\Postieri\Api\Http\RateLimiter;

class Api_v1 extends CI_Controller
{
    protected array $token = [];
    protected array $scopes = [];

    public function __construct()
    {
        parent::__construct();

        if (get_option('postieri_api_enabled') !== '1') {
            Response::error($this, 503, 'api_disabled', 'Postieri API is disabled in settings');
            return;
        }

        // Auth: Bearer token (header takes priority, then ?token= query param for compat)
        $authHeader = $this->input->get_request_header('Authorization', true);
        $token = null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } else {
            $token = $this->input->get('token'); // fallback for browser testing
        }

        if (!$token) {
            Response::error($this, 401, 'unauthorized', 'Missing Authorization: Bearer <token>');
            return;
        }

        $svc = new TokenService($this->db);
        $row = $svc->verify($token);
        if (!$row) {
            Response::error($this, 401, 'invalid_token', 'Token is invalid, expired, or revoked');
            return;
        }

        $this->token = $row;
        $this->scopes = $row['scopes'] ?? [];

        // Rate limit
        $ip = $this->input->ip_address();
        $endpoint = $this->uri->uri_string();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $limiter = new RateLimiter(
            $this->db,
            (int) $row['id'],
            (int) get_option('postieri_api_rate_limit_per_min'),
            (int) get_option('postieri_api_rate_limit_per_hour')
        );
        $rl = $limiter->check($endpoint, $method, $ip);

        $this->output->set_header('X-RateLimit-Limit-Min: ' . get_option('postieri_api_rate_limit_per_min'));
        $this->output->set_header('X-RateLimit-Remaining-Min: ' . $rl['remaining_min']);
        $this->output->set_header('X-RateLimit-Limit-Hour: ' . get_option('postieri_api_rate_limit_per_hour'));
        $this->output->set_header('X-RateLimit-Remaining-Hour: ' . $rl['remaining_hour']);

        if (!$rl['allowed']) {
            $retryAfter = $rl['remaining_min'] === 0 ? 60 : 3600;
            $this->output->set_header('Retry-After: ' . $retryAfter);
            Response::error($this, 429, 'rate_limited', "Rate limit exceeded. Retry after {$retryAfter}s");
            return;
        }
    }

    /**
     * Check that the current token has the given scope. Returns true/false.
     */
    protected function hasScope(string $required): bool
    {
        foreach ($this->scopes as $s) {
            if ($s === $required || $s === '*' || $s === '*:*') {
                return true;
            }
            // Wildcard match: e.g. "customers:*" matches "customers:read"
            if (str_ends_with($s, ':*')) {
                $prefix = substr($s, 0, -2);
                if (str_starts_with($required, $prefix . ':')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Require a scope or 403.
     */
    protected function requireScope(string $scope): bool
    {
        if (!$this->hasScope($scope)) {
            Response::error($this, 403, 'insufficient_scope', "Missing required scope: {$scope}");
            return false;
        }
        return true;
    }

    /**
     * Parse pagination from query string with defaults + caps.
     */
    protected function pagination(): array
    {
        $page = max(1, (int) ($this->input->get('page') ?: 1));
        $perPage = min(100, max(1, (int) ($this->input->get('per_page') ?: 25)));
        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];
    }
}
```

Commit: `feat(api): add Api_v1 base controller with auth, rate limit, scope check, pagination`

---

## Phase 4 — Auth Endpoint

### Task 4.1: Create Auth controller

**File:** `modules/postieri_api/controllers/Auth.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Auth\TokenService;
use Perfexcrm\Postieri\Api\Http\Response;

class Auth extends CI_Controller
{
    /**
     * POST /api/v1/auth/token
     * Body: { "email": "...", "password": "..." } OR { "user_id": N, "name": "...", "scopes": [...] } (admin only)
     * Returns: { "status": true, "data": { "token": "plain-text-once", "id": N, "expires_at": "..." } }
     */
    public function create_token(): void
    {
        $input = json_decode($this->input->raw_input_stream, true) ?: [];
        $svc = new TokenService($this->db);

        // Admin path: explicit user_id
        if (!empty($input['user_id']) && !empty($input['name'])) {
            if (!is_admin()) {
                Response::error($this, 403, 'forbidden', 'Only admins can issue tokens for other users');
                return;
            }
            $userId = (int) $input['user_id'];
            $name = $input['name'];
            $scopes = $input['scopes'] ?? ['*'];
            $expires = $input['expires_at'] ?? null;
            $issued = $svc->issue($userId, $name, $scopes, $expires);
            Response::created($this, [
                'id'         => $issued['id'],
                'token'      => $issued['token'],
                'user_id'    => $userId,
                'scopes'     => $issued['scopes'],
                'expires_at' => $issued['expires_at'],
                'warning'    => 'Save this token now — it will not be shown again.',
            ]);
            return;
        }

        // Self-service path: email + password (login as a Perfex user, get a token)
        if (!empty($input['email']) && !empty($input['password'])) {
            $email = $input['email'];
            $password = $input['password'];
            $name = $input['name'] ?? 'API token';

            $user = $this->db->where('email', $email)->get(db_prefix() . 'staff')->row_array();
            if (!$user || !app_hasher()->CheckPassword($password, $user['password'])) {
                Response::error($this, 401, 'invalid_credentials', 'Email or password is incorrect');
                return;
            }
            if ($user['active'] != 1) {
                Response::error($this, 403, 'inactive_user', 'User account is not active');
                return;
            }

            $issued = $svc->issue((int) $user['staffid'], $name, ['*']);
            Response::created($this, [
                'id'         => $issued['id'],
                'token'      => $issued['token'],
                'user_id'    => (int) $user['staffid'],
                'scopes'     => $issued['scopes'],
                'expires_at' => $issued['expires_at'],
                'warning'    => 'Save this token now — it will not be shown again.',
            ]);
            return;
        }

        Response::error($this, 400, 'validation_failed', 'Provide either {user_id, name, scopes} or {email, password}', [
            'required' => ['(user_id + name) OR (email + password)'],
        ]);
    }

    /**
     * DELETE /api/v1/auth/token/{id}
     * Revokes a token. Admin only.
     */
    public function delete_token($id = null): void
    {
        if (!is_admin()) {
            Response::error($this, 403, 'forbidden', 'Only admins can revoke tokens');
            return;
        }
        if (!$id) {
            Response::error($this, 400, 'validation_failed', 'Token id is required');
            return;
        }
        $svc = new TokenService($this->db);
        if ($svc->revoke((int) $id)) {
            Response::noContent($this);
        } else {
            Response::error($this, 500, 'revoke_failed', 'Failed to revoke token');
        }
    }

    /**
     * GET /api/v1/auth/tokens
     * List all tokens (admin only). Hashed values are not returned.
     */
    public function list_tokens(): void
    {
        if (!is_admin()) {
            Response::error($this, 403, 'forbidden', 'Only admins can list tokens');
            return;
        }
        $svc = new TokenService($this->db);
        Response::ok($this, $svc->listAll());
    }
}
```

**File:** `modules/postieri_api/config/routes.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

// API v1 routes
$route['api/v1/auth/token']           = 'postieri_api/auth/create_token';
$route['api/v1/auth/token/(:num)']    = 'postieri_api/auth/delete_token/$1';
$route['api/v1/auth/tokens']          = 'postieri_api/auth/list_tokens';
```

Commit: `feat(auth): add Auth controller (create/delete/list tokens) + routes`

---

### Task 4.2: Manual test — issue and use a token

**Step 1:** Copy module to dev Perfex:
```bash
rsync -av /workspace/company/postieri-perfex-api/modules/postieri_api/ \
  /path/to/perfex-dev/modules/postieri_api/
```

**Step 2:** Activate module in Perfex admin (Setup → Modules → Postieri API → Activate).

**Step 3:** Issue a token:
```bash
curl -s -X POST http://localhost/perfex/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@yourcompany.com","password":"admin_password"}' | python3 -m json.tool
```
**Expected:** 201 response with `data.token` (64 hex chars).

**Step 4:** Use the token:
```bash
TOKEN="paste_from_above"
curl -s -X GET "http://localhost/perfex/api/v1/auth/tokens?token=$TOKEN" | python3 -m json.tool
```
**Expected:** 200 with the list of tokens (empty array or just the one we created).

If both work, **Phase 4 complete**. Commit any test artifacts: `test: verify token issue + use flow end-to-end`.

---

## Phase 5 — Customers CRUD

### Task 5.1: Create Customers controller

**File:** `modules/postieri_api/controllers/Customers.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

class Customers extends Api_v1
{
    /**
     * GET /api/v1/customers?page=1&per_page=25&search=foo
     */
    public function index(): void
    {
        if (!$this->requireScope('customers:read')) return;

        $pag = $this->pagination();
        $search = $this->input->get('search');

        $this->db->select('userid, company, vat, phonenumber, country, city, zip, state, address, website, datecreated, active');
        if ($search) {
            $this->db->group_start()
                ->like('company', $search)
                ->or_like('vat', $search)
                ->or_like('phonenumber', $search)
                ->group_end();
        }
        $total = $this->db->count_all_results(db_prefix() . 'clients');

        $this->db->select('userid, company, vat, phonenumber, country, city, zip, state, address, website, datecreated, active');
        if ($search) {
            $this->db->group_start()
                ->like('company', $search)
                ->or_like('vat', $search)
                ->or_like('phonenumber', $search)
                ->group_end();
        }
        $this->db->order_by('company', 'ASC');
        $this->db->limit($pag['per_page'], $pag['offset']);
        $rows = $this->db->get(db_prefix() . 'clients')->result_array();

        Response::ok($this, $rows, [
            'page'        => $pag['page'],
            'per_page'    => $pag['per_page'],
            'total'       => $total,
            'total_pages' => (int) ceil($total / $pag['per_page']),
        ]);
    }

    /**
     * GET /api/v1/customers/{id}
     */
    public function show($id = null): void
    {
        if (!$this->requireScope('customers:read')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Customer id is required'); return; }

        $row = $this->db->where('userid', (int) $id)->get(db_prefix() . 'clients')->row_array();
        if (!$row) { Response::error($this, 404, 'not_found', "Customer {$id} not found"); return; }

        // Include contacts
        $contacts = $this->db->where('userid', (int) $id)->get(db_prefix() . 'contacts')->result_array();
        $row['contacts'] = $contacts;

        Response::ok($this, $row);
    }

    /**
     * POST /api/v1/customers
     * Body: { "company": "...", "vat": "...", "phonenumber": "...", "country": N, ... }
     */
    public function create(): void
    {
        if (!$this->requireScope('customers:write')) return;

        $input = json_decode($this->input->raw_input_stream, true) ?: [];
        if (empty($input['company'])) {
            Response::error($this, 400, 'validation_failed', 'company is required', ['company' => ['required']]);
            return;
        }

        $data = [
            'company'     => $input['company'],
            'vat'         => $input['vat'] ?? '',
            'phonenumber' => $input['phonenumber'] ?? '',
            'country'     => (int) ($input['country'] ?? 0),
            'city'        => $input['city'] ?? '',
            'zip'         => $input['zip'] ?? '',
            'state'       => $input['state'] ?? '',
            'address'     => $input['address'] ?? '',
            'website'     => $input['website'] ?? '',
            'active'      => 1,
            'datecreated' => date('Y-m-d H:i:s'),
        ];
        $this->db->insert(db_prefix() . 'clients', $data);
        $id = $this->db->insert_id();

        // Perfex hook for downstream listeners
        hooks()->do_action('customer_created', $id);

        Response::created($this, ['id' => $id] + $data);
    }

    /**
     * PUT /api/v1/customers/{id}
     */
    public function update($id = null): void
    {
        if (!$this->requireScope('customers:write')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Customer id is required'); return; }

        $input = json_decode($this->input->raw_input_stream, true) ?: [];
        $allowed = ['company','vat','phonenumber','country','city','zip','state','address','website','active'];
        $data = array_intersect_key($input, array_flip($allowed));
        if (empty($data)) {
            Response::error($this, 400, 'validation_failed', 'No updatable fields provided', ['allowed' => $allowed]);
            return;
        }

        $this->db->where('userid', (int) $id)->update(db_prefix() . 'clients', $data);
        hooks()->do_action('customer_updated', (int) $id);

        Response::ok($this, ['id' => (int) $id, 'updated' => array_keys($data)]);
    }

    /**
     * DELETE /api/v1/customers/{id}
     */
    public function delete($id = null): void
    {
        if (!$this->requireScope('customers:write')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Customer id is required'); return; }

        $this->db->where('userid', (int) $id)->delete(db_prefix() . 'clients');
        if ($this->db->affected_rows() === 0) {
            Response::error($this, 404, 'not_found', "Customer {$id} not found");
            return;
        }
        Response::noContent($this);
    }
}
```

**File:** `modules/postieri_api/config/routes.php` (append)

```php
$route['api/v1/customers']            = 'postieri_api/customers/index';
$route['api/v1/customers/(:num)']     = 'postieri_api/customers/show/$1';
$route['api/v1/customers/create']     = 'postieri_api/customers/create';
$route['api/v1/customers/update/(:num)'] = 'postieri_api/customers/update/$1';
$route['api/v1/customers/delete/(:num)'] = 'postieri_api/customers/delete/$1';
$route['api/v1/customers/search/(:any)'] = 'postieri_api/customers/index';
```

Commit: `feat(customers): full CRUD with pagination + search + scope checks`

---

### Task 5.2: Manual test — customers CRUD

**Step 1:** Issue admin token (Phase 4.2) and export `TOKEN`.

**Step 2:** Create:
```bash
curl -s -X POST "http://localhost/perfex/api/v1/customers/create?token=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"company":"Test Co","vat":"AL12345","country":1,"city":"Pristina"}' | python3 -m json.tool
```
**Expected:** 201 with `data.id`.

**Step 3:** List:
```bash
curl -s "http://localhost/perfex/api/v1/customers?token=$TOKEN&per_page=5" | python3 -m json.tool
```
**Expected:** 200, includes the customer just created.

**Step 4:** Show, update, delete — repeat for the other endpoints.

If all work, **Phase 5 complete**.

---

## Phase 6 — Invoices + PDF

### Task 6.1: Create Invoices controller

**File:** `modules/postieri_api/controllers/Invoices.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

class Invoices extends Api_v1
{
    /**
     * GET /api/v1/invoices?page=1&per_page=25&status=Unpaid&customer_id=N
     */
    public function index(): void
    {
        if (!$this->requireScope('invoices:read')) return;

        $pag = $this->pagination();
        $status = $this->input->get('status');
        $customerId = $this->input->get('customer_id');

        $this->db->select('id, clientid, number, date, duedate, total, subtotal, status');
        $this->db->from(db_prefix() . 'invoices');
        if ($status) $this->db->where('status', $status);
        if ($customerId) $this->db->where('clientid', (int) $customerId);
        $total = $this->db->count_all_results();

        $this->db->select('id, clientid, number, date, duedate, total, subtotal, status');
        $this->db->from(db_prefix() . 'invoices');
        if ($status) $this->db->where('status', $status);
        if ($customerId) $this->db->where('clientid', (int) $customerId);
        $this->db->order_by('id', 'DESC');
        $this->db->limit($pag['per_page'], $pag['offset']);
        $rows = $this->db->get()->result_array();

        Response::ok($this, $rows, [
            'page'        => $pag['page'],
            'per_page'    => $pag['per_page'],
            'total'       => $total,
            'total_pages' => (int) ceil($total / $pag['per_page']),
        ]);
    }

    /**
     * GET /api/v1/invoices/{id}
     */
    public function show($id = null): void
    {
        if (!$this->requireScope('invoices:read')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Invoice id is required'); return; }

        $row = $this->db->where('id', (int) $id)->get(db_prefix() . 'invoices')->row_array();
        if (!$row) { Response::error($this, 404, 'not_found', "Invoice {$id} not found"); return; }

        $items = $this->db->where('rel_id', (int) $id)
            ->where('rel_type', 'invoice')
            ->get(db_prefix() . 'itemable')->result_array();
        $row['items'] = $items;

        Response::ok($this, $row);
    }

    /**
     * POST /api/v1/invoices
     * Body: full Perfex invoice structure
     */
    public function create(): void
    {
        if (!$this->requireScope('invoices:write')) return;
        $input = json_decode($this->input->raw_input_stream, true) ?: [];

        // Delegate to Perfex's own Invoices_model for validation
        $this->load->model('invoices_model');
        $id = $this->invoices_model->add($input);
        if (!$id) {
            Response::error($this, 422, 'validation_failed', 'Invoice could not be created. Check required fields.', [
                'hint' => 'Required Perfex fields: clientid, date, currency, newitems[]',
            ]);
            return;
        }
        hooks()->do_action('invoice_created', $id);
        Response::created($this, ['id' => $id]);
    }

    /**
     * GET /api/v1/invoices/{id}/pdf
     * Streams the PDF generated by Perfex's own logic.
     */
    public function pdf($id = null): void
    {
        if (!$this->requireScope('invoices:read')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Invoice id is required'); return; }

        $invoice = $this->db->where('id', (int) $id)->get(db_prefix() . 'invoices')->row_array();
        if (!$invoice) { Response::error($this, 404, 'not_found', "Invoice {$id} not found"); return; }

        // Reuse Perfex's PDF generator
        $this->load->model('invoices_model');
        $pdfPath = FCPATH . 'temp/invoice_' . $id . '.pdf';
        $this->invoices_model->generate_pdf((int) $id, $pdfPath);

        if (!file_exists($pdfPath)) {
            Response::error($this, 500, 'pdf_failed', 'PDF generation failed');
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="invoice_' . $invoice['number'] . '.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }
}
```

**File:** `modules/postieri_api/config/routes.php` (append)

```php
$route['api/v1/invoices']                  = 'postieri_api/invoices/index';
$route['api/v1/invoices/create']           = 'postieri_api/invoices/create';
$route['api/v1/invoices/(:num)/pdf']       = 'postieri_api/invoices/pdf/$1';
$route['api/v1/invoices/(:num)']           = 'postieri_api/invoices/show/$1';
```

Commit: `feat(invoices): list/show/create + PDF streaming using Perfex's PDF generator`

---

## Phase 7 — Subscriptions + Leads (read-focused for v1)

### Task 7.1: Create Subscriptions controller

**File:** `modules/postieri_api/controllers/Subscriptions.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

class Subscriptions extends Api_v1
{
    public function index(): void
    {
        if (!$this->requireScope('subscriptions:read')) return;
        $pag = $this->pagination();
        $customerId = $this->input->get('customer_id');
        $status = $this->input->get('status'); // 'active', 'expired', etc.

        $this->db->from(db_prefix() . 'subscriptions');
        if ($customerId) $this->db->where('clientid', (int) $customerId);
        if ($status) $this->db->where('status', $status);
        $total = $this->db->count_all_results();

        $this->db->from(db_prefix() . 'subscriptions');
        if ($customerId) $this->db->where('clientid', (int) $customerId);
        if ($status) $this->db->where('status', $status);
        $this->db->order_by('id', 'DESC');
        $this->db->limit($pag['per_page'], $pag['offset']);
        $rows = $this->db->get()->result_array();

        Response::ok($this, $rows, [
            'page'        => $pag['page'],
            'per_page'    => $pag['per_page'],
            'total'       => $total,
            'total_pages' => (int) ceil($total / $pag['per_page']),
        ]);
    }

    public function show($id = null): void
    {
        if (!$this->requireScope('subscriptions:read')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Subscription id is required'); return; }
        $row = $this->db->where('id', (int) $id)->get(db_prefix() . 'subscriptions')->row_array();
        if (!$row) { Response::error($this, 404, 'not_found', "Subscription {$id} not found"); return; }
        Response::ok($this, $row);
    }
}
```

**File:** `modules/postieri_api/config/routes.php` (append)

```php
$route['api/v1/subscriptions']            = 'postieri_api/subscriptions/index';
$route['api/v1/subscriptions/(:num)']     = 'postieri_api/subscriptions/show/$1';
```

Commit: `feat(subscriptions): list + show with status/customer filters`

---

### Task 7.2: Create Leads controller

**File:** `modules/postieri_api/controllers/Leads.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

class Leads extends Api_v1
{
    public function index(): void
    {
        if (!$this->requireScope('leads:read')) return;
        $pag = $this->pagination();
        $status = $this->input->get('status');
        $source = $this->input->get('source');

        $this->db->from(db_prefix() . 'leads');
        if ($status) $this->db->where('status', $status);
        if ($source) $this->db->where('source', $source);
        $total = $this->db->count_all_results();

        $this->db->select('id, name, title, company, email, phonenumber, country, city, status, source, dateadded, is_lead_value, lead_value, junk, lost');
        $this->db->from(db_prefix() . 'leads');
        if ($status) $this->db->where('status', $status);
        if ($source) $this->db->where('source', $source);
        $this->db->order_by('id', 'DESC');
        $this->db->limit($pag['per_page'], $pag['offset']);
        $rows = $this->db->get()->result_array();

        Response::ok($this, $rows, [
            'page' => $pag['page'], 'per_page' => $pag['per_page'],
            'total' => $total, 'total_pages' => (int) ceil($total / $pag['per_page']),
        ]);
    }

    public function show($id = null): void
    {
        if (!$this->requireScope('leads:read')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Lead id is required'); return; }
        $row = $this->db->where('id', (int) $id)->get(db_prefix() . 'leads')->row_array();
        if (!$row) { Response::error($this, 404, 'not_found', "Lead {$id} not found"); return; }
        Response::ok($this, $row);
    }

    public function create(): void
    {
        if (!$this->requireScope('leads:write')) return;
        $input = json_decode($this->input->raw_input_stream, true) ?: [];
        if (empty($input['name'])) {
            Response::error($this, 400, 'validation_failed', 'name is required');
            return;
        }
        $data = [
            'name'      => $input['name'],
            'title'     => $input['title'] ?? '',
            'company'   => $input['company'] ?? '',
            'email'     => $input['email'] ?? '',
            'phonenumber' => $input['phonenumber'] ?? '',
            'country'   => (int) ($input['country'] ?? 0),
            'city'      => $input['city'] ?? '',
            'status'    => $input['status'] ?? 2, // default "New" status id (Perfex-dependent)
            'source'    => $input['source'] ?? 1,
            'dateadded' => date('Y-m-d H:i:s'),
        ];
        $this->db->insert(db_prefix() . 'leads', $data);
        $id = $this->db->insert_id();
        hooks()->do_action('lead_created', $id);
        Response::created($this, ['id' => $id] + $data);
    }

    /**
     * POST /api/v1/leads/{id}/convert
     * Convert a lead to a customer. Delegates to Perfex's Leads_model.
     */
    public function convert($id = null): void
    {
        if (!$this->requireScope('leads:write')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Lead id is required'); return; }

        $this->load->model('leads_model');
        $this->load->model('clients_model');

        $lead = $this->db->where('id', (int) $id)->get(db_prefix() . 'leads')->row_array();
        if (!$lead) { Response::error($this, 404, 'not_found', "Lead {$id} not found"); return; }

        // Perfex's lead conversion is complex; do a simplified version
        $clientData = [
            'company'     => $lead['company'] ?: $lead['name'],
            'country'     => $lead['country'],
            'city'        => $lead['city'],
            'phonenumber' => $lead['phonenumber'],
            'datecreated' => date('Y-m-d H:i:s'),
            'active'      => 1,
        ];
        $this->db->insert(db_prefix() . 'clients', $clientData);
        $clientId = $this->db->insert_id();

        // Mark lead as converted
        $this->db->where('id', (int) $id)->update(db_prefix() . 'leads', [
            'lead_converted_to_client' => $clientId,
            'status' => 5, // Perfex "Converted" status id
        ]);

        hooks()->do_action('lead_converted_to_customer', $clientId, (int) $id);
        Response::ok($this, [
            'lead_id'   => (int) $id,
            'client_id' => $clientId,
        ]);
    }
}
```

**File:** `modules/postieri_api/config/routes.php` (append)

```php
$route['api/v1/leads']                    = 'postieri_api/leads/index';
$route['api/v1/leads/create']             = 'postieri_api/leads/create';
$route['api/v1/leads/(:num)/convert']     = 'postieri_api/leads/convert/$1';
$route['api/v1/leads/(:num)']             = 'postieri_api/leads/show/$1';
```

Commit: `feat(leads): list/show/create/convert with hooks integration`

---

## Phase 8 — Webhooks (the differentiator)

### Task 8.1: Create WebhookDispatcher library

**File:** `modules/postieri_api/libraries/Webhook_dispatcher.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Webhook_dispatcher
{
    private Client $http;
    private array $retryDelays = [60, 300, 1800, 7200, 43200]; // 1m, 5m, 30m, 2h, 12h

    public function __construct()
    {
        $this->http = new Client(['timeout' => 10, 'http_errors' => false]);
    }

    /**
     * Dispatch an event to all active subscribers.
     */
    public function dispatch(string $event, array $payload): void
    {
        $subscribers = $this->db
            ->where('is_active', 1)
            ->like('events', '"' . $event . '"')
            ->get(db_prefix() . 'postieri_api_webhooks')
            ->result_array();

        foreach ($subscribers as $sub) {
            $this->send($sub, $event, $payload);
        }
    }

    private function send(array $sub, string $event, array $payload): void
    {
        $body = json_encode([
            'event'      => $event,
            'data'       => $payload,
            'timestamp'  => date('c'),
            'webhook_id' => $sub['id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = hash_hmac('sha256', $body, $sub['secret']);

        try {
            $response = $this->http->post($sub['url'], [
                'headers' => [
                    'Content-Type'        => 'application/json',
                    'X-Postieri-Event'    => $event,
                    'X-Postieri-Signature' => $signature,
                    'User-Agent'          => 'PostieriPerfexAPI/0.1',
                ],
                'body' => $body,
            ]);
            $status = $response->getStatusCode();
            $respBody = (string) $response->getBody();

            $delivered = $status >= 200 && $status < 300;
            $this->db->insert(db_prefix() . 'postieri_api_webhook_deliveries', [
                'webhook_id'       => $sub['id'],
                'event'            => $event,
                'payload'          => $body,
                'response_status'  => $status,
                'response_body'    => substr($respBody, 0, 1000),
                'attempt'          => 1,
                'delivered_at'     => $delivered ? date('Y-m-d H:i:s') : null,
                'next_retry_at'    => $delivered ? null : $this->nextRetryTime(1),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (RequestException $e) {
            $this->db->insert(db_prefix() . 'postieri_api_webhook_deliveries', [
                'webhook_id'       => $sub['id'],
                'event'            => $event,
                'payload'          => $body,
                'response_status'  => 0,
                'response_body'    => $e->getMessage(),
                'attempt'          => 1,
                'next_retry_at'    => $this->nextRetryTime(1),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function nextRetryTime(int $attempt): string
    {
        $idx = min($attempt - 1, count($this->retryDelays) - 1);
        return date('Y-m-d H:i:s', time() + $this->retryDelays[$idx]);
    }

    /**
     * Daily cron: poll subscriptions expiring in <7 days.
     */
    public function dispatch_subscription_expiring(): void
    {
        $cutoff = date('Y-m-d', strtotime('+7 days'));
        $today  = date('Y-m-d');

        $subs = $this->db
            ->where('status', 'active')
            ->where('next_billing_cycle <=', $cutoff)
            ->where('next_billing_cycle >=', $today)
            ->get(db_prefix() . 'subscriptions')
            ->result_array();

        foreach ($subs as $sub) {
            $this->dispatch('subscription.expiring', (array) $sub);
        }

        // Also emit subscription.expired for any that have passed
        $expired = $this->db
            ->where('status', 'active')
            ->where('next_billing_cycle <', $today)
            ->get(db_prefix() . 'subscriptions')
            ->result_array();

        foreach ($expired as $sub) {
            $this->db->where('id', $sub['id'])->update(db_prefix() . 'subscriptions', ['status' => 'expired']);
            $this->dispatch('subscription.expired', (array) $sub);
        }
    }

    /**
     * Retry failed deliveries whose next_retry_at has passed.
     * Called from the daily cron.
     */
    public function retry_failed(): void
    {
        $rows = $this->db
            ->where('delivered_at IS NULL', null, false)
            ->where('next_retry_at <=', date('Y-m-d H:i:s'))
            ->where('attempt <', count($this->retryDelays))
            ->get(db_prefix() . 'postieri_api_webhook_deliveries')
            ->result_array();

        foreach ($rows as $r) {
            $sub = $this->db->where('id', $r['webhook_id'])->get(db_prefix() . 'postieri_api_webhooks')->row_array();
            if (!$sub || !$sub['is_active']) continue;

            $signature = hash_hmac('sha256', $r['payload'], $sub['secret']);
            try {
                $response = $this->http->post($sub['url'], [
                    'headers' => [
                        'Content-Type'         => 'application/json',
                        'X-Postieri-Event'     => $r['event'],
                        'X-Postieri-Signature' => $signature,
                    ],
                    'body' => $r['payload'],
                ]);
                $status = $response->getStatusCode();
                $delivered = $status >= 200 && $status < 300;
                $this->db->where('id', $r['id'])->update(db_prefix() . 'postieri_api_webhook_deliveries', [
                    'response_status' => $status,
                    'response_body'   => substr((string) $response->getBody(), 0, 1000),
                    'attempt'         => $r['attempt'] + 1,
                    'delivered_at'    => $delivered ? date('Y-m-d H:i:s') : null,
                    'next_retry_at'   => $delivered ? null : $this->nextRetryTime($r['attempt'] + 1),
                ]);
            } catch (RequestException $e) {
                $this->db->where('id', $r['id'])->update(db_prefix() . 'postieri_api_webhook_deliveries', [
                    'attempt'       => $r['attempt'] + 1,
                    'next_retry_at' => $this->nextRetryTime($r['attempt'] + 1),
                    'response_body' => $e->getMessage(),
                ]);
            }
        }
    }
}
```

Commit: `feat(webhooks): add WebhookDispatcher library with retry + subscription polling`

---

### Task 8.2: Create Webhooks controller (CRUD subscribers)

**File:** `modules/postieri_api/controllers/Webhooks.php`

**Content:**
```php
<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Perfexcrm\Postieri\Api\Http\Response;

class Webhooks extends Api_v1
{
    public function index(): void
    {
        if (!$this->requireScope('webhooks:read')) return;
        $rows = $this->db
            ->order_by('id', 'DESC')
            ->get(db_prefix() . 'postieri_api_webhooks')
            ->result_array();
        Response::ok($this, $rows);
    }

    public function show($id = null): void
    {
        if (!$this->requireScope('webhooks:read')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Webhook id is required'); return; }
        $row = $this->db->where('id', (int) $id)->get(db_prefix() . 'postieri_api_webhooks')->row_array();
        if (!$row) { Response::error($this, 404, 'not_found', "Webhook {$id} not found"); return; }
        Response::ok($this, $row);
    }

    public function create(): void
    {
        if (!$this->requireScope('webhooks:write')) return;
        $input = json_decode($this->input->raw_input_stream, true) ?: [];

        $errors = [];
        if (empty($input['url']) || !filter_var($input['url'], FILTER_VALIDATE_URL)) $errors['url'] = ['valid URL required'];
        if (empty($input['events']) || !is_array($input['events'])) $errors['events'] = ['array of event names required'];
        if (!empty($errors)) {
            Response::error($this, 400, 'validation_failed', 'Validation failed', $errors);
            return;
        }

        $this->db->insert(db_prefix() . 'postieri_api_webhooks', [
            'name'       => $input['name'] ?? 'Webhook',
            'url'        => $input['url'],
            'events'     => json_encode($input['events']),
            'secret'     => $input['secret'] ?? bin2hex(random_bytes(16)),
            'is_active'  => 1,
            'created_by' => (int) $this->token['user_id'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = $this->db->insert_id();
        Response::created($this, [
            'id'     => $id,
            'secret' => $this->db->where('id', $id)->get(db_prefix() . 'postieri_api_webhooks')->row()->secret,
            'warning' => 'Save the secret now — it is used to verify webhook signatures.',
        ] + $input);
    }

    public function update($id = null): void
    {
        if (!$this->requireScope('webhooks:write')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Webhook id is required'); return; }
        $input = json_decode($this->input->raw_input_stream, true) ?: [];
        $allowed = ['name', 'url', 'events', 'is_active'];
        $data = [];
        foreach ($allowed as $k) {
            if (isset($input[$k])) {
                $data[$k] = $k === 'events' ? json_encode($input[$k]) : $input[$k];
            }
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update(db_prefix() . 'postieri_api_webhooks', $data);
        Response::ok($this, ['id' => (int) $id, 'updated' => array_keys($data)]);
    }

    public function delete($id = null): void
    {
        if (!$this->requireScope('webhooks:write')) return;
        if (!$id) { Response::error($this, 400, 'validation_failed', 'Webhook id is required'); return; }
        $this->db->where('id', (int) $id)->delete(db_prefix() . 'postieri_api_webhooks');
        Response::noContent($this);
    }
}
```

**File:** `modules/postieri_api/config/routes.php` (append)

```php
$route['api/v1/webhooks']             = 'postieri_api/webhooks/index';
$route['api/v1/webhooks/create']      = 'postieri_api/webhooks/create';
$route['api/v1/webhooks/(:num)']      = 'postieri_api/webhooks/show/$1';
$route['api/v1/webhooks/update/(:num)'] = 'postieri_api/webhooks/update/$1';
$route['api/v1/webhooks/delete/(:num)'] = 'postieri_api/webhooks/delete/$1';
```

Commit: `feat(webhooks): CRUD controller for subscribers + scope checks`

---

## Phase 9 — Python Wrapper SDK

### Task 9.1: Create perfex_client.py for Postieri pipeline

**File:** `/workspace/company/postieri-perfex-api/sdk/python/perfex_client.py`

**Content:**
```python
"""Postieri Perfex API — Python client for the Postieri XYZ pipeline.

Usage:
    client = PerfexClient(base_url="https://perfex.postieri.xyz", token="...")
    customers = client.list_customers(search="Acme")
    invoice_pdf = client.get_invoice_pdf(123)  # bytes
"""
from __future__ import annotations
import os
import time
from typing import Any
import requests


class PerfexError(Exception):
    """Raised on any non-2xx response from the API."""

    def __init__(self, status: int, code: str, message: str, details: Any = None):
        self.status = status
        self.code = code
        self.message = message
        self.details = details
        super().__init__(f"[{status}] {code}: {message}")


class PerfexClient:
    def __init__(self, base_url: str, token: str | None = None, timeout: int = 30):
        self.base_url = base_url.rstrip("/")
        self.token = token or os.environ.get("PERFEX_API_TOKEN")
        if not self.token:
            raise ValueError("token is required (pass token= or set PERFEX_API_TOKEN env var)")
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update({
            "Authorization": f"Bearer {self.token}",
            "Content-Type": "application/json",
            "User-Agent": "PostieriPerfexClient/0.1",
        })

    # --- low-level ---

    def _request(self, method: str, path: str, **kwargs) -> Any:
        url = f"{self.base_url}{path}"
        resp = self.session.request(method, url, timeout=self.timeout, **kwargs)
        if resp.status_code == 204:
            return None
        if not (200 <= resp.status_code < 300):
            try:
                err = resp.json().get("error", {})
                raise PerfexError(resp.status_code, err.get("code", "unknown"), err.get("message", resp.text), err.get("details"))
            except (ValueError, AttributeError):
                raise PerfexError(resp.status_code, "unknown", resp.text)
        return resp.json()

    def _paginate(self, path: str, **params) -> list[dict]:
        """Fetch all pages of a list endpoint."""
        params.setdefault("per_page", 100)
        params.setdefault("page", 1)
        out: list[dict] = []
        while True:
            data = self._request("GET", path, params=params)
            out.extend(data.get("data", []))
            meta = data.get("meta", {})
            if meta.get("page", 1) >= meta.get("total_pages", 1):
                break
            params["page"] = meta["page"] + 1
        return out

    # --- auth ---

    def create_token_for_user(self, user_id: int, name: str, scopes: list[str] | None = None) -> dict:
        return self._request("POST", "/api/v1/auth/token", json={
            "user_id": user_id, "name": name, "scopes": scopes or ["*"],
        })

    def list_tokens(self) -> list[dict]:
        return self._request("GET", "/api/v1/auth/tokens").get("data", [])

    def revoke_token(self, token_id: int) -> None:
        self._request("DELETE", f"/api/v1/auth/token/{token_id}")

    # --- customers ---

    def list_customers(self, search: str | None = None, page: int = 1, per_page: int = 25) -> dict:
        params: dict[str, Any] = {"page": page, "per_page": per_page}
        if search:
            params["search"] = search
        return self._request("GET", "/api/v1/customers", params=params)

    def get_customer(self, customer_id: int) -> dict:
        return self._request("GET", f"/api/v1/customers/{customer_id}").get("data", {})

    def create_customer(self, **fields) -> dict:
        return self._request("POST", "/api/v1/customers/create", json=fields)

    def update_customer(self, customer_id: int, **fields) -> dict:
        return self._request("POST", f"/api/v1/customers/update/{customer_id}", json=fields)

    def delete_customer(self, customer_id: int) -> None:
        self._request("POST", f"/api/v1/customers/delete/{customer_id}")

    # --- invoices ---

    def list_invoices(self, status: str | None = None, customer_id: int | None = None, page: int = 1) -> dict:
        params: dict[str, Any] = {"page": page, "per_page": 25}
        if status: params["status"] = status
        if customer_id: params["customer_id"] = customer_id
        return self._request("GET", "/api/v1/invoices", params=params)

    def get_invoice(self, invoice_id: int) -> dict:
        return self._request("GET", f"/api/v1/invoices/{invoice_id}").get("data", {})

    def get_invoice_pdf(self, invoice_id: int) -> bytes:
        url = f"{self.base_url}/api/v1/invoices/{invoice_id}/pdf"
        resp = self.session.get(url, timeout=self.timeout)
        resp.raise_for_status()
        return resp.content

    # --- subscriptions ---

    def list_subscriptions(self, customer_id: int | None = None, status: str | None = None) -> dict:
        params: dict[str, Any] = {}
        if customer_id: params["customer_id"] = customer_id
        if status: params["status"] = status
        return self._request("GET", "/api/v1/subscriptions", params=params)

    def get_subscription(self, sub_id: int) -> dict:
        return self._request("GET", f"/api/v1/subscriptions/{sub_id}").get("data", {})

    # --- leads ---

    def list_leads(self, status: str | None = None, page: int = 1) -> dict:
        params: dict[str, Any] = {"page": page, "per_page": 25}
        if status: params["status"] = status
        return self._request("GET", "/api/v1/leads", params=params)

    def create_lead(self, name: str, **fields) -> dict:
        return self._request("POST", "/api/v1/leads/create", json={"name": name, **fields})

    def convert_lead(self, lead_id: int) -> dict:
        return self._request("POST", f"/api/v1/leads/{lead_id}/convert").get("data", {})

    # --- webhooks ---

    def list_webhooks(self) -> list[dict]:
        return self._request("GET", "/api/v1/webhooks").get("data", [])

    def create_webhook(self, url: str, events: list[str], name: str = "Webhook") -> dict:
        return self._request("POST", "/api/v1/webhooks/create", json={
            "url": url, "events": events, "name": name,
        })

    def delete_webhook(self, webhook_id: int) -> None:
        self._request("POST", f"/api/v1/webhooks/delete/{webhook_id}")


# Convenience singleton (use when env vars are set)
_default: PerfexClient | None = None


def client() -> PerfexClient:
    global _default
    if _default is None:
        _default = PerfexClient(
            base_url=os.environ["PERFEX_BASE_URL"],
            token=os.environ["PERFEX_API_TOKEN"],
        )
    return _default
```

Commit: `feat(sdk): add Python wrapper client for the Postieri pipeline`

---

## Phase 10 — Documentation

### Task 10.1: Create OpenAPI 3.1 spec

**File:** `/workspace/company/postieri-perfex-api/docs/openapi.yaml`

(Generate via `npx @redocly/cli@latest preview-docs` later — for now, write a hand-authored minimal spec covering all 5 resource groups.)

Commit: `docs: add OpenAPI 3.1 spec (initial)`

---

### Task 10.2: Write README with install/upgrade/uninstall

**File:** `/workspace/company/postieri-perfex-api/README.md`

**Content sections:**
- Overview
- Requirements (Perfex 3.2+, PHP 8.1+, MySQL 5.7+)
- Install (copy to modules/, activate)
- Configuration (Settings → API → Postieri API)
- Authentication (Bearer token)
- API Reference (link to OpenAPI)
- Webhook events list
- Cron setup (subscription polling)
- Security notes
- License (MIT)

Commit: `docs: add README with install + auth + cron setup`

---

### Task 10.3: Add a postieri-case-studies entry

Once v1 is shipped, use the `postieri-case-studies` skill to draft a case study.

---

## Phase 11 — Production Deploy

### Task 11.1: Test suite green

Run `composer test` (or `vendor/bin/phpunit`). All unit tests pass.

### Task 11.2: Push to GitHub

```bash
cd /workspace/company/postieri-perfex-api
gh repo create postierixyz/postieri-perfex-api --public --source=. --push
```

### Task 11.3: Deploy to Contabo

1. SSH into Contabo (Plesk).
2. `rsync -av` the `modules/postieri_api/` directory to `<perfex-root>/modules/postieri_api/`.
3. Activate via `Setup → Modules`.
4. Verify `tblpostieri_api_*` tables created.
5. Issue a test token via curl.
6. Set up cron (Plesk Scheduled Tasks):
   - **Daily 06:00** → `curl -s https://perfex.postieri.xyz/api/internal/cron/subscriptions` (or call `Webhook_dispatcher::dispatch_subscription_expiring()` directly via a CLI script if Perfex exposes a CLI runner — to be confirmed in dev)

### Task 11.4: Post-deploy verification

- [ ] `GET /api/v1/auth/tokens` works with issued token
- [ ] `GET /api/v1/customers?per_page=5` returns expected data
- [ ] `GET /api/v1/invoices/{id}/pdf` streams a valid PDF
- [ ] `POST /api/v1/webhooks/create` with a test URL (e.g. webhook.site) returns 201
- [ ] Trigger a real Perfex event (e.g. mark an invoice as paid) → webhook fires

---

## Verification Checklist (before declaring v1 done)

- [ ] Token create, list, revoke all work end-to-end
- [ ] Customers CRUD: list, show, create, update, delete
- [ ] Invoices: list, show, PDF download
- [ ] Subscriptions: list, show
- [ ] Leads: list, show, create, convert
- [ ] Webhook subscribers: create, list, delete
- [ ] At least one real webhook delivery confirmed (webhook.site or local listener)
- [ ] Rate limit triggers 429 after exceeding 100 req/min
- [ ] Cron job for `subscription.expiring` is scheduled
- [ ] OpenAPI spec matches actual responses
- [ ] README has install + cron + troubleshooting
- [ ] GitHub repo is public (or whatever Luan decides)
- [ ] Python client `perfex_client.py` works against the live instance

---

## What's NOT in v1 (deferred to v1.5 / v2)

- OAuth2 server (third-party app auth)
- JWT tokens
- Contacts / Projects / Tasks / Tickets / Contracts / Estimates / Proposals endpoints
- Multi-tenant isolation
- Web UI for token management (CLI/admin only for v1)
- IP allowlisting
- Request signing (HMAC on inbound)
- Auto-generated SDK clients (TypeScript, Go)
- Hooks for `task.*`, `project.*`, `ticket.*` events
- Bulk operations (e.g. `POST /api/v1/customers/bulk`)

These can be added in subsequent phases based on real usage.
