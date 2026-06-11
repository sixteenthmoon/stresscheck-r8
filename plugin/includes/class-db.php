<?php

class SC_DB {
    private static array $memory = [
        'questions' => [],
        'tokens' => [],
        'test_executions' => [],
        'responses' => [],
        'results' => [],
        'cache' => [],
    ];

    private static array $auto = [
        'questions' => 1,
        'tokens' => 1,
        'test_executions' => 1,
        'responses' => 1,
        'results' => 1,
    ];

    public function __construct() {
        if ($this->using_wp()) {
            return;
        }
        if (empty(self::$memory['questions'])) {
            $this->seed_questions();
        }
    }

    public function using_wp(): bool {
        return isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']);
    }

    public function table_name(string $suffix): string {
        if ($this->using_wp()) {
            global $wpdb;
            return $wpdb->prefix . $suffix;
        }
        return $suffix;
    }

    public function schema_sql(): array {
        $prefix = $this->using_wp() ? $this->table_name('sc_') : '';
        return [
            "CREATE TABLE {$prefix}questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_no TINYINT UNSIGNED NOT NULL,
  section_code CHAR(1) NOT NULL,
  scale_code VARCHAR(50) NULL,
  question_text_ja TEXT NOT NULL,
  question_text_en TEXT NULL,
  option_set_code VARCHAR(50) NOT NULL DEFAULT 'default_4',
  is_reverse_scoring TINYINT(1) NOT NULL DEFAULT 0,
  display_order TINYINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_question_no (question_no),
  KEY idx_section_code (section_code),
  KEY idx_is_active (is_active)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$prefix}test_executions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  execution_code_hash CHAR(64) NOT NULL,
  execution_code_prefix VARCHAR(16) NULL,
  title VARCHAR(255) NOT NULL,
  delivery_date DATE NULL,
  answer_deadline DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  target_year SMALLINT UNSIGNED NOT NULL,
  issued_token_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_execution_code_hash (execution_code_hash),
  KEY idx_status (status),
  KEY idx_target_year (target_year),
  KEY idx_answer_deadline (answer_deadline)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$prefix}tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_execution_id BIGINT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL,
  plain_token VARCHAR(128) NULL,
  token_prefix VARCHAR(12) NULL,
  token_issue_seq INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'issued',
  target_year SMALLINT UNSIGNED NOT NULL,
  issued_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  submitted_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  reissued_from_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_test_execution_id (test_execution_id),
  KEY idx_execution_status (test_execution_id, status),
  UNIQUE KEY uq_execution_seq (test_execution_id, token_issue_seq),
  KEY idx_status (status),
  KEY idx_target_year (target_year),
  KEY idx_expires_at (expires_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$prefix}responses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id BIGINT UNSIGNED NOT NULL,
  answers_json LONGTEXT NOT NULL,
  user_agent_hash CHAR(64) NULL,
  ip_hash CHAR(64) NULL,
  submitted_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_id (token_id),
  KEY idx_submitted_at (submitted_at)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE {$prefix}results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id BIGINT UNSIGNED NOT NULL,
  target_year SMALLINT UNSIGNED NOT NULL,
  score_a SMALLINT NOT NULL,
  score_b SMALLINT NOT NULL,
  score_c SMALLINT NOT NULL,
  score_d SMALLINT NOT NULL,
  score_ac SMALLINT NOT NULL,
  high_stress_flag TINYINT(1) NOT NULL DEFAULT 0,
  high_stress_reason VARCHAR(50) NULL,
  interview_requested TINYINT(1) NOT NULL DEFAULT 0,
  interview_requested_at DATETIME NULL,
  calculated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_result_token_id (token_id),
  KEY idx_target_year (target_year),
  KEY idx_high_stress_flag (high_stress_flag),
  KEY idx_interview_requested (interview_requested)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }

    public function install(): void {
        if ($this->using_wp()) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            if (function_exists('dbDelta')) {
                foreach ($this->schema_sql() as $sql) {
                    dbDelta($sql);
                }
            }
        }
        $this->seed_questions();
    }

    public function seed_questions(): void {
        $questions = require __DIR__ . '/data/questions.php';
        $this->clear_question_cache();
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_questions');
            $wpdb->query("DELETE FROM {$table}");
            foreach ($questions as $question) {
                $question['created_at'] = SC_Utils::now_mysql();
                $question['updated_at'] = SC_Utils::now_mysql();
                $wpdb->insert($table, $question);
            }
            return;
        }
        self::$memory['questions'] = [];
        foreach ($questions as $question) {
            $question['id'] = self::$auto['questions']++;
            $question['created_at'] = SC_Utils::now_mysql();
            $question['updated_at'] = SC_Utils::now_mysql();
            self::$memory['questions'][] = $question;
        }
    }

    public function get_questions(bool $only_active = true): array {
        $cache_key = $this->question_cache_key($only_active);
        if ($cached = $this->get_question_cache($cache_key)) {
            return $cached;
        }
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_questions');
            $sql = "SELECT * FROM {$table}";
            if ($only_active) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY display_order ASC';
            $questions = (array) $wpdb->get_results($sql, ARRAY_A);
            $this->set_question_cache($cache_key, $questions);
            return $questions;
        }
        $questions = self::$memory['questions'];
        if ($only_active) {
            $questions = array_values(array_filter($questions, static fn($row) => (int) ($row['is_active'] ?? 0) === 1));
        }
        usort($questions, static fn($a, $b) => ((int) $a['display_order']) <=> ((int) $b['display_order']));
        $this->set_question_cache($cache_key, $questions);
        return $questions;
    }

    public function clear_question_cache(): void {
        $this->delete_question_cache($this->question_cache_key(true));
        $this->delete_question_cache($this->question_cache_key(false));
    }

    private function question_cache_key(bool $only_active): string {
        return 'sc_questions_' . ($only_active ? 'active' : 'all');
    }

    private function get_question_cache(string $key): array {
        if ($this->using_wp()) {
            $cached = SC_Utils::get_transient($key);
            return is_array($cached) ? $cached : [];
        }
        return (array) (self::$memory['cache'][$key] ?? []);
    }

    private function set_question_cache(string $key, array $questions): void {
        if ($this->using_wp()) {
            SC_Utils::set_transient($key, $questions, 3600);
            return;
        }
        self::$memory['cache'][$key] = $questions;
    }

    private function delete_question_cache(string $key): void {
        if ($this->using_wp()) {
            SC_Utils::delete_transient($key);
            return;
        }
        unset(self::$memory['cache'][$key]);
    }

    public function get_question_map(bool $only_active = true): array {
        $map = [];
        foreach ($this->get_questions($only_active) as $question) {
            $map[(int) $question['question_no']] = $question;
        }
        return $map;
    }

    public function find_question_by_no(int $question_no): ?array {
        $map = $this->get_question_map(true);
        return $map[$question_no] ?? null;
    }

    public function store_test_execution(array $row): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_test_executions');
            $wpdb->insert($table, $row);
            return (int) $wpdb->insert_id;
        }
        $row['id'] = self::$auto['test_executions']++;
        self::$memory['test_executions'][] = $row;
        return (int) $row['id'];
    }

    public function update_test_execution(int $id, array $fields): bool {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_test_executions');
            return false !== $wpdb->update($table, $fields, ['id' => $id]);
        }
        foreach (self::$memory['test_executions'] as &$row) {
            if ((int) $row['id'] === $id) {
                $row = array_merge($row, $fields);
                return true;
            }
        }
        return false;
    }

    public function find_test_execution_by_code(string $plain_code): ?array {
        return $this->find_test_execution_by_hash(SC_Utils::hash_token($plain_code));
    }

    public function find_test_execution_by_hash(string $hash): ?array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_test_executions');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE execution_code_hash = %s", $hash), ARRAY_A);
            return $row ?: null;
        }
        foreach (self::$memory['test_executions'] as $row) {
            if (($row['execution_code_hash'] ?? '') === $hash) {
                return $row;
            }
        }
        return null;
    }

    public function find_test_execution_by_id(int $id): ?array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_test_executions');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
            return $row ?: null;
        }
        foreach (self::$memory['test_executions'] as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    public function list_test_executions(array $filters = []): array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_test_executions');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            if (isset($filters['status'])) {
                $where[] = 'status = %s';
                $params[] = (string) $filters['status'];
            }
            $sql = "SELECT * FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY id DESC';
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            return (array) $wpdb->get_results($sql, ARRAY_A);
        }
        $rows = self::$memory['test_executions'];
        if (isset($filters['target_year'])) {
            $rows = array_values(array_filter($rows, static fn($row) => (int) ($row['target_year'] ?? 0) === (int) $filters['target_year']));
        }
        if (isset($filters['status'])) {
            $rows = array_values(array_filter($rows, static fn($row) => ($row['status'] ?? '') === (string) $filters['status']));
        }
        usort($rows, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
        return $rows;
    }

    public function next_token_issue_seq(int $test_execution_id): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $max = $wpdb->get_var($wpdb->prepare("SELECT MAX(token_issue_seq) FROM {$table} WHERE test_execution_id = %d", $test_execution_id));
            return ((int) $max) + 1;
        }
        $max = 0;
        foreach (self::$memory['tokens'] as $row) {
            if ((int) ($row['test_execution_id'] ?? 0) === $test_execution_id) {
                $max = max($max, (int) ($row['token_issue_seq'] ?? 0));
            }
        }
        return $max + 1;
    }

    public function store_token(array $row): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $wpdb->insert($table, $row);
            return (int) $wpdb->insert_id;
        }
        $row['id'] = self::$auto['tokens']++;
        self::$memory['tokens'][] = $row;
        return (int) $row['id'];
    }

    public function update_token(int $id, array $fields): bool {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            return false !== $wpdb->update($table, $fields, ['id' => $id]);
        }
        foreach (self::$memory['tokens'] as &$row) {
            if ((int) $row['id'] === $id) {
                $row = array_merge($row, $fields);
                return true;
            }
        }
        return false;
    }

    public function find_token_by_hash(string $hash): ?array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_hash = %s", $hash), ARRAY_A);
            return $row ?: null;
        }
        foreach (self::$memory['tokens'] as $row) {
            if (($row['token_hash'] ?? '') === $hash) {
                return $row;
            }
        }
        return null;
    }

    public function find_token_by_id(int $id): ?array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
            return $row ?: null;
        }
        foreach (self::$memory['tokens'] as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    public function list_tokens(array $filters = []): array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            if (isset($filters['status'])) {
                $where[] = 'status = %s';
                $params[] = (string) $filters['status'];
            }
            if (isset($filters['expires_before'])) {
                $where[] = 'expires_at < %s';
                $params[] = (string) $filters['expires_before'];
            }
            if (isset($filters['status_not_in'])) {
                $status_not_in = array_values(array_filter((array) $filters['status_not_in'], static fn($v) => is_string($v) && $v !== ''));
                if ($status_not_in) {
                    $placeholders = implode(', ', array_fill(0, count($status_not_in), '%s'));
                    $where[] = "status NOT IN ({$placeholders})";
                    array_push($params, ...$status_not_in);
                }
            }
            $sql = "SELECT * FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= isset($filters['test_execution_id'])
                ? ' ORDER BY token_issue_seq ASC, id ASC'
                : ' ORDER BY id DESC';
            if (isset($filters['per_page'])) {
                $per_page = max(1, (int) $filters['per_page']);
                $page = max(1, (int) ($filters['page'] ?? 1));
                $offset = ($page - 1) * $per_page;
                $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $per_page, $offset);
            }
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            return (array) $wpdb->get_results($sql, ARRAY_A);
        }
        $tokens = self::$memory['tokens'];
        if (isset($filters['test_execution_id'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => (int) ($row['test_execution_id'] ?? 0) === (int) $filters['test_execution_id']));
        }
        if (isset($filters['target_year'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => (int) ($row['target_year'] ?? 0) === (int) $filters['target_year']));
        }
        if (isset($filters['status'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => ($row['status'] ?? '') === (string) $filters['status']));
        }
        if (isset($filters['expires_before'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => strtotime((string) ($row['expires_at'] ?? '')) !== false && strtotime((string) $row['expires_at']) < strtotime((string) $filters['expires_before'])));
        }
        if (isset($filters['status_not_in'])) {
            $status_not_in = array_values(array_filter((array) $filters['status_not_in'], static fn($v) => is_string($v) && $v !== ''));
            if ($status_not_in) {
                $tokens = array_values(array_filter($tokens, static fn($row) => !in_array((string) ($row['status'] ?? ''), $status_not_in, true)));
            }
        }
        if (isset($filters['test_execution_id'])) {
            usort($tokens, static fn($a, $b) => ((int) ($a['token_issue_seq'] ?? 0) <=> (int) ($b['token_issue_seq'] ?? 0)) ?: (((int) $a['id']) <=> ((int) $b['id'])));
        } else {
            usort($tokens, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
        }
        if (isset($filters['per_page'])) {
            $per_page = max(1, (int) $filters['per_page']);
            $page = max(1, (int) ($filters['page'] ?? 1));
            $offset = ($page - 1) * $per_page;
            $tokens = array_slice($tokens, $offset, $per_page);
        }
        return $tokens;
    }

    public function due_tokens_for_expiry(): array {
        return $this->list_tokens([
            'status_not_in' => ['expired', 'submitted', 'revoked', 'reissued'],
            'expires_before' => SC_Utils::now_mysql(),
        ]);
    }

    public function count_tokens(array $filters = []): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            if (isset($filters['status'])) {
                $where[] = 'status = %s';
                $params[] = (string) $filters['status'];
            }
            $sql = "SELECT COUNT(*) FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            return (int) $wpdb->get_var($sql);
        }
        $tokens = self::$memory['tokens'];
        if (isset($filters['test_execution_id'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => (int) ($row['test_execution_id'] ?? 0) === (int) $filters['test_execution_id']));
        }
        if (isset($filters['target_year'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => (int) ($row['target_year'] ?? 0) === (int) $filters['target_year']));
        }
        if (isset($filters['status'])) {
            $tokens = array_values(array_filter($tokens, static fn($row) => ($row['status'] ?? '') === (string) $filters['status']));
        }
        return count($tokens);
    }

    public function first_token_issued_at(array $filters = []): ?string {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            if (isset($filters['status'])) {
                $where[] = 'status = %s';
                $params[] = (string) $filters['status'];
            }
            $sql = "SELECT MIN(issued_at) FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            $value = $wpdb->get_var($sql);
            return $value ? (string) $value : null;
        }
        $tokens = $this->list_tokens($filters);
        $dates = array_values(array_filter(array_map(static fn($row) => $row['issued_at'] ?? null, $tokens)));
        if (!$dates) {
            return null;
        }
        sort($dates);
        return (string) $dates[0];
    }

    public function last_token_issued_at(array $filters = []): ?string {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_tokens');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            if (isset($filters['status'])) {
                $where[] = 'status = %s';
                $params[] = (string) $filters['status'];
            }
            $sql = "SELECT MAX(issued_at) FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            $value = $wpdb->get_var($sql);
            return $value ? (string) $value : null;
        }
        $tokens = $this->list_tokens($filters);
        $dates = array_values(array_filter(array_map(static fn($row) => $row['issued_at'] ?? null, $tokens)));
        if (!$dates) {
            return null;
        }
        sort($dates);
        return (string) end($dates);
    }

    public function store_response(array $row): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_responses');
            $wpdb->insert($table, $row);
            return (int) $wpdb->insert_id;
        }
        $row['id'] = self::$auto['responses']++;
        self::$memory['responses'][] = $row;
        return (int) $row['id'];
    }

    public function find_response_by_token_id(int $token_id): ?array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_responses');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_id = %d", $token_id), ARRAY_A);
            return $row ?: null;
        }
        foreach (self::$memory['responses'] as $row) {
            if ((int) $row['token_id'] === $token_id) {
                return $row;
            }
        }
        return null;
    }

    public function store_result(array $row): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_results');
            $wpdb->insert($table, $row);
            return (int) $wpdb->insert_id;
        }
        $row['id'] = self::$auto['results']++;
        self::$memory['results'][] = $row;
        return (int) $row['id'];
    }

    public function update_result(int $token_id, array $fields): bool {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_results');
            return false !== $wpdb->update($table, $fields, ['token_id' => $token_id]);
        }
        foreach (self::$memory['results'] as &$row) {
            if ((int) $row['token_id'] === $token_id) {
                $row = array_merge($row, $fields);
                return true;
            }
        }
        return false;
    }

    public function find_result_by_token_id(int $token_id): ?array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_results');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_id = %d", $token_id), ARRAY_A);
            return $row ?: null;
        }
        foreach (self::$memory['results'] as $row) {
            if ((int) $row['token_id'] === $token_id) {
                return $row;
            }
        }
        return null;
    }

    public function list_results(array $filters = []): array {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_results');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            $sql = "SELECT * FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY id DESC';
            if (isset($filters['per_page'])) {
                $per_page = max(1, (int) $filters['per_page']);
                $page = max(1, (int) ($filters['page'] ?? 1));
                $offset = ($page - 1) * $per_page;
                $sql .= $wpdb->prepare(' LIMIT %d OFFSET %d', $per_page, $offset);
            }
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            return (array) $wpdb->get_results($sql, ARRAY_A);
        }
        $results = self::$memory['results'];
        if (isset($filters['target_year'])) {
            $results = array_values(array_filter($results, static fn($row) => (int) ($row['target_year'] ?? 0) === (int) $filters['target_year']));
        }
        usort($results, static fn($a, $b) => ((int) $b['id']) <=> ((int) $a['id']));
        if (isset($filters['per_page'])) {
            $per_page = max(1, (int) $filters['per_page']);
            $page = max(1, (int) ($filters['page'] ?? 1));
            $offset = ($page - 1) * $per_page;
            $results = array_slice($results, $offset, $per_page);
        }
        return $results;
    }

    public function count_results(array $filters = []): int {
        if ($this->using_wp()) {
            global $wpdb;
            $table = $this->table_name('sc_results');
            $where = [];
            $params = [];
            if (isset($filters['test_execution_id'])) {
                $where[] = 'test_execution_id = %d';
                $params[] = (int) $filters['test_execution_id'];
            }
            if (isset($filters['target_year'])) {
                $where[] = 'target_year = %d';
                $params[] = (int) $filters['target_year'];
            }
            if (isset($filters['high_stress_flag'])) {
                $where[] = 'high_stress_flag = %d';
                $params[] = (int) $filters['high_stress_flag'];
            }
            if (isset($filters['interview_requested'])) {
                $where[] = 'interview_requested = %d';
                $params[] = (int) $filters['interview_requested'];
            }
            $sql = "SELECT COUNT(*) FROM {$table}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            if ($params) {
                $sql = $wpdb->prepare($sql, ...$params);
            }
            return (int) $wpdb->get_var($sql);
        }
        $results = self::$memory['results'];
        if (isset($filters['test_execution_id'])) {
            $results = array_values(array_filter($results, static fn($row) => (int) ($row['test_execution_id'] ?? 0) === (int) $filters['test_execution_id']));
        }
        if (isset($filters['target_year'])) {
            $results = array_values(array_filter($results, static fn($row) => (int) ($row['target_year'] ?? 0) === (int) $filters['target_year']));
        }
        if (isset($filters['high_stress_flag'])) {
            $results = array_values(array_filter($results, static fn($row) => (int) ($row['high_stress_flag'] ?? 0) === (int) $filters['high_stress_flag']));
        }
        if (isset($filters['interview_requested'])) {
            $results = array_values(array_filter($results, static fn($row) => (int) ($row['interview_requested'] ?? 0) === (int) $filters['interview_requested']));
        }
        return count($results);
    }
}
