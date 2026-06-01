#!/bin/sh
set -e

cd /var/www/html

# 1) Write database config if missing
if [ ! -f application/config/database.php ]; then
  cat > application/config/database.php <<PHP
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
\$active_group = 'default';
\$query_builder = TRUE;
\$db['default'] = array(
    'dsn'      => '',
    'hostname' => getenv('DB_HOSTNAME') ?: 'db',
    'username' => getenv('DB_USERNAME') ?: 'perfex',
    'password' => getenv('DB_PASSWORD') ?: 'perfexpass',
    'database' => getenv('DB_NAME')     ?: 'perfex',
    'dbdriver' => 'mysqli',
    'dbprefix' => 'tbl',
    'pconnect' => FALSE,
    'db_debug' => (ENVIRONMENT !== 'production'),
    'cache_on' => FALSE,
    'cachedir' => '',
    'char_set' => 'utf8mb4',
    'dbcollat' => 'utf8mb4_unicode_ci',
    'swap_pre' => '',
    'encrypt'  => array('cipher' => 'AES-128-ECB', 'key' => 'CHANGE_ME_RANDOMLY'),
    'compress' => FALSE,
    'stricton' => FALSE,
    'failover' => array(),
    'save_queries' => TRUE,
);
PHP
  echo "Wrote database.php"
fi

# 2) Base URL
cat > application/config/config.php <<'PHP'
<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$config['base_url'] = getenv('APP_URL') ?: 'http://localhost:8080';
$config['index_page'] = '';
$config['uri_protocol'] = 'REQUEST_URI';
$config['url_suffix'] = '';
$config['language'] = 'english';
$config['charset'] = 'UTF-8';
$config['enable_hooks'] = TRUE;
$config['subclass_prefix'] = 'MY_';
$config['composer_autoload'] = FALSE;
$config['permitted_uri_chars'] = 'a-z 0-9~%.:_\-';
$config['allow_get_array'] = TRUE;
$config['enable_query_strings'] = FALSE;
$config['controller_trigger'] = 'c';
$config['function_trigger'] = 'm';
$config['directory_trigger'] = 'd';
$config['log_threshold'] = 1;
$config['log_path'] = '';
$config['log_file_extension'] = '';
$config['log_file_permissions'] = 0644;
$config['log_date_format'] = 'Y-m-d H:i:s';
$config['error_views_path'] = '';
$config['cache_path'] = '';
$config['cache_query_string'] = FALSE;
$config['encryption_key'] = bin2hex(random_bytes(16));
$config['sess_driver'] = 'files';
$config['sess_cookie_name'] = 'perfex_session';
$config['sess_expiration'] = 7200;
$config['sess_save_path'] = NULL;
$config['sess_match_ip'] = FALSE;
$config['sess_time_to_update'] = 300;
$config['sess_regenerate_destroy'] = FALSE;
$config['cookie_prefix']   = '';
$config['cookie_domain']   = '';
$config['cookie_path']     = '/';
$config['cookie_secure']   = FALSE;
$config['cookie_httponly'] = FALSE;
$config['global_xss_filtering'] = FALSE;
$config['csrf_protection'] = FALSE;
$config['csrf_token_name'] = 'csrf_test_name';
$config['csrf_cookie_name'] = 'csrf_cookie_name';
$config['csrf_expire'] = 7200;
$config['csrf_regenerate'] = TRUE;
$config['csrf_exclude_uris'] = array();
$config['standardize_newlines'] = FALSE;
$config['rewrite_short_tags'] = FALSE;
$config['proxy_ips'] = '';
$config['csrf_ignores'] = [];
$config['app_version'] = '3.4.1';
PHP

# 3) Ensure module directory is present
mkdir -p modules/postieri_api
if [ ! -f modules/postieri_api/postieri_api.php ]; then
  echo "WARNING: postieri_api module is not mounted at modules/postieri_api/"
  echo "         Mount it in docker-compose.yml: - ./modules/postieri_api:/var/www/html/modules/postieri_api"
fi

# 4) Permissions
chown -R www-data:www-data /var/www/html

# 5) Wait for DB (already done by compose healthcheck, but be safe)
echo "Waiting for database..."
for i in $(seq 1 30); do
  if mysql -h "${DB_HOSTNAME:-db}" -u"${DB_USERNAME:-perfex}" -p"${DB_PASSWORD:-perfexpass}" -e "SELECT 1" "${DB_NAME:-perfex}" >/dev/null 2>&1; then
    echo "Database ready"
    break
  fi
  sleep 2
done

# 6) Run Perfex installer if no staff table yet
if ! mysql -h "${DB_HOSTNAME:-db}" -u"${DB_USERNAME:-perfex}" -p"${DB_PASSWORD:-perfexpass}" "${DB_NAME:-perfex}" -e "SHOW TABLES LIKE 'tblstaff'" 2>/dev/null | grep -q tblstaff; then
  echo "============================================================"
  echo "  Perfex is not installed yet."
  echo "  Open ${APP_URL:-http://localhost:8080} and complete the installer."
  echo "  Database connection is pre-configured (see database.php)."
  echo "============================================================"
fi

# 7) Start cron
if [ "${CRON_ENABLED:-true}" = "true" ]; then
  service cron start || true
fi

exec "$@"
