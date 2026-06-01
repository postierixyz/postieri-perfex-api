<?php
/**
 * PHPStan stubs for CodeIgniter 3 globals and classes.
 * The real classes are auto-loaded at runtime; PHPStan can't see them
 * without a hint.
 */

if (!class_exists('CI_DB_driver', false)) {
    abstract class CI_DB_driver
    {
        public function insert($table, $data): bool { return true; }
        public function update($table, $data, $where = []): bool { return true; }
        public function delete($table, $where = []): bool { return true; }
        public function get($table, $limit = null, $offset = null): array { return []; }
        public function get_where($table, $where, $limit = null, $offset = null): array { return []; }
        public function row($type = 'object') { return null; }
        public function row_array(): array { return []; }
        public function result($type = 'object'): array { return []; }
        public function result_array(): array { return []; }
        public function num_rows(): int { return 0; }
        public function affected_rows(): int { return 0; }
        public function insert_id(): int { return 0; }
        public function count_all($table = ''): int { return 0; }
        public function count_all_results($table = ''): int { return 0; }
        public function where($key, $value = null, $escape = true) { return $this; }
        public function like($field, $match = '', $side = 'both') { return $this; }
        public function order_by($orderby, $direction = '') { return $this; }
        public function limit($value, $offset = 0) { return $this; }
        public function offset($offset) { return $this; }
        public function select($select = '*', $escape = null) { return $this; }
        public function from($from) { return $this; }
        public function join($table, $cond, $type = '') { return $this; }
        public function query($sql, $binds = false, $return_object = null) { return null; }
        public function escape($value) { return ''; }
        public function error(): array { return ['code' => 0, 'message' => '']; }
    }
}

if (!class_exists('CI_Controller', false)) {
    class CI_Controller
    {
        public $load;
        public $db;
        public $input;
        public $output;
        public $session;
        public $config;
    }
}

if (!function_exists('get_instance')) {
    function &get_instance(): CI_Controller
    {
        $c = new CI_Controller();
        return $c;
    }
}

if (!function_exists('db_prefix')) {
    function db_prefix($table = ''): string
    {
        return $table;
    }
}
