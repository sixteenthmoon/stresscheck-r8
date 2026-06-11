<?php

class SC_Test_Execution_Service {
    private SC_DB $db;
    private SC_Token_Service $tokens;

    public function __construct(SC_DB $db, SC_Token_Service $tokens) {
        $this->db = $db;
        $this->tokens = $tokens;
    }

    public function create(string $title, ?string $delivery_date, string $answer_deadline, ?int $target_year = null): array {
        $title = trim($title);
        if ($title === '') {
            return $this->error('VALIDATION_ERROR', 'テスト実施タイトルは必須です。', 400);
        }
        if (mb_strlen($title) > 255) {
            return $this->error('VALIDATION_ERROR', 'テスト実施タイトルは255文字以内で入力してください。', 400);
        }
        $deadline_mysql = $this->to_mysql_datetime($answer_deadline);
        if ($deadline_mysql === null) {
            return $this->error('VALIDATION_ERROR', '回答締切日が不正です。', 400);
        }
        $delivery_date = $delivery_date !== null && trim($delivery_date) !== '' ? trim($delivery_date) : null;
        if ($delivery_date !== null) {
            $delivery_ts = strtotime($delivery_date);
            $deadline_ts = strtotime($deadline_mysql);
            if ($delivery_ts === false || ($deadline_ts !== false && $delivery_ts > $deadline_ts)) {
                return $this->error('VALIDATION_ERROR', '配信日は回答締切日以前にしてください。', 400);
            }
            $delivery_date = gmdate('Y-m-d', $delivery_ts);
        }
        if (!$target_year) {
            $target_year = (int) gmdate('Y', strtotime($deadline_mysql) ?: time());
        }
        $code = $this->generate_code($deadline_mysql);
        $row = [
            'execution_code_hash' => SC_Utils::hash_token($code),
            'execution_code_prefix' => substr($code, 0, 16),
            'title' => $title,
            'delivery_date' => $delivery_date,
            'answer_deadline' => $deadline_mysql,
            'status' => 'draft',
            'target_year' => $target_year,
            'issued_token_count' => 0,
            'created_at' => SC_Utils::now_mysql(),
            'updated_at' => SC_Utils::now_mysql(),
        ];
        $id = $this->db->store_test_execution($row);
        $row['id'] = $id;
        return ['ok' => true, 'data' => $this->public_row($row, $code)];
    }

    public function find_by_code(string $code): ?array {
        return $this->db->find_test_execution_by_code($code);
    }

    public function issue_tokens(string $code, int $count, ?string $base_url = null): array {
        $execution = $this->find_by_code($code);
        if (!$execution) {
            return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404);
        }
        if (in_array((string) ($execution['status'] ?? ''), ['closed', 'archived', 'revoked'], true)) {
            return $this->error('TEST_EXECUTION_CLOSED', 'このテスト実施にはトークンを発行できません。', 409);
        }
        $count = min(500, max(1, $count));
        $tokens = $this->tokens->issue_tokens_for_execution(
            (int) $execution['id'],
            $count,
            (int) $execution['target_year'],
            (string) $execution['answer_deadline'],
            $base_url
        );
        $issued_count = $this->db->count_tokens(['test_execution_id' => (int) $execution['id']]);
        $this->db->update_test_execution((int) $execution['id'], [
            'issued_token_count' => $issued_count,
            'status' => 'active',
            'updated_at' => SC_Utils::now_mysql(),
        ]);
        return ['ok' => true, 'data' => [
            'test_execution_code' => $code,
            'issued_count' => count($tokens),
            'tokens' => array_map(fn(array $token): array => $this->token_response_row($token), $tokens),
        ]];
    }

    public function list_tokens(string $code, array $filters = []): array {
        $execution = $this->find_by_code($code);
        if (!$execution) {
            return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404);
        }
        $filters['test_execution_id'] = (int) $execution['id'];
        $tokens = $this->db->list_tokens($filters);
        return ['ok' => true, 'data' => [
            'test_execution_code' => $code,
            'tokens' => array_map(fn(array $token): array => $this->token_admin_row($token), $tokens),
        ]];
    }

    public function progress(string $code): array {
        $execution = $this->find_by_code($code);
        if (!$execution) {
            return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404);
        }
        $execution_id = (int) $execution['id'];
        $issued = $this->db->count_tokens(['test_execution_id' => $execution_id]);
        $submitted = $this->db->count_tokens(['test_execution_id' => $execution_id, 'status' => 'submitted']);
        $started = $this->db->count_tokens(['test_execution_id' => $execution_id, 'status' => 'started']);
        $expired = $this->db->count_tokens(['test_execution_id' => $execution_id, 'status' => 'expired']);
        $not_started = $this->db->count_tokens(['test_execution_id' => $execution_id, 'status' => 'issued']);
        $tokens = $this->db->list_tokens(['test_execution_id' => $execution_id]);
        return ['ok' => true, 'data' => [
            'test_execution_code' => $code,
            'title' => (string) $execution['title'],
            'status' => (string) $execution['status'],
            'delivery_date' => $execution['delivery_date'] ?? null,
            'answer_deadline' => $this->to_iso8601((string) $execution['answer_deadline']),
            'issued_count' => $issued,
            'not_started_count' => $not_started,
            'started_count' => $started,
            'submitted_count' => $submitted,
            'expired_count' => $expired,
            'completion_rate' => $issued > 0 ? round(($submitted / $issued) * 100, 1) : 0.0,
            'tokens' => array_map(fn(array $token): array => $this->token_progress_row($token), $tokens),
        ]];
    }

    public function results(string $code, array $filters = []): array {
        $execution = $this->find_by_code($code);
        if (!$execution) {
            return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404);
        }
        $rows = [];
        foreach ($this->db->list_tokens(['test_execution_id' => (int) $execution['id']]) as $token) {
            $result = $this->db->find_result_by_token_id((int) $token['id']);
            if (!$result) {
                continue;
            }
            if (isset($filters['high_stress']) && (int) $result['high_stress_flag'] !== (int) $filters['high_stress']) {
                continue;
            }
            $rows[] = $this->result_row($token, $result);
        }
        $total_count = count($rows);
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $per_page = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : $total_count;
        if ($per_page > 0) {
            $offset = ($page - 1) * $per_page;
            $rows = array_slice($rows, $offset, $per_page);
        }
        return ['ok' => true, 'data' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_count' => $total_count,
            'results' => $rows,
        ]];
    }

    public function high_stress(string $code): array {
        $results = $this->results($code, ['high_stress' => 1]);
        if (!$results['ok']) {
            return $results;
        }
        return ['ok' => true, 'data' => [
            'test_execution_code' => $code,
            'high_stress_count' => (int) $results['data']['total_count'],
            'tokens' => array_map(static fn(array $row): array => [
                'token_issue_seq' => $row['token_issue_seq'],
                'token_prefix' => $row['token_prefix'],
                'submitted_at' => $row['submitted_at'],
                'high_stress_reason' => $row['high_stress_reason'],
                'interview_requested' => $row['interview_requested'],
            ], $results['data']['results']),
        ]];
    }

    public function summary(string $code): array {
        $execution = $this->find_by_code($code);
        if (!$execution) {
            return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404);
        }
        $execution_id = (int) $execution['id'];
        $issued = $this->db->count_tokens(['test_execution_id' => $execution_id]);
        $submitted = $this->db->count_tokens(['test_execution_id' => $execution_id, 'status' => 'submitted']);
        $high_stress = 0;
        $interview_requested = 0;
        foreach ($this->db->list_tokens(['test_execution_id' => $execution_id]) as $token) {
            $result = $this->db->find_result_by_token_id((int) $token['id']);
            if (!$result) {
                continue;
            }
            if ((int) ($result['high_stress_flag'] ?? 0) === 1) {
                $high_stress++;
            }
            if ((int) ($result['interview_requested'] ?? 0) === 1) {
                $interview_requested++;
            }
        }
        return ['ok' => true, 'data' => [
            'test_execution_code' => $code,
            'title' => (string) $execution['title'],
            'status' => (string) $execution['status'],
            'delivery_date' => $execution['delivery_date'] ?? null,
            'answer_deadline' => $this->to_iso8601((string) $execution['answer_deadline']),
            'issued_count' => $issued,
            'submitted_count' => $submitted,
            'completion_rate' => $issued > 0 ? round(($submitted / $issued) * 100, 1) : 0.0,
            'high_stress_count' => $high_stress,
            'high_stress_rate' => $submitted > 0 ? round(($high_stress / $submitted) * 100, 1) : 0.0,
            'interview_requested_count' => $interview_requested,
        ]];
    }

    public function close(string $code): array {
        $execution = $this->find_by_code($code);
        if (!$execution) {
            return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404);
        }
        $this->db->update_test_execution((int) $execution['id'], [
            'status' => 'closed',
            'updated_at' => SC_Utils::now_mysql(),
        ]);
        return ['ok' => true, 'data' => ['status' => 'closed', 'test_execution_code' => $code]];
    }

    public function public_row(array $row, ?string $plain_code = null): array {
        $data = [
            'id' => (int) ($row['id'] ?? 0),
            'execution_code_prefix' => (string) ($row['execution_code_prefix'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'delivery_date' => $row['delivery_date'] ?? null,
            'answer_deadline' => $this->to_iso8601((string) ($row['answer_deadline'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'target_year' => (int) ($row['target_year'] ?? 0),
            'issued_token_count' => (int) ($row['issued_token_count'] ?? 0),
        ];
        if ($plain_code !== null) {
            $data['test_execution_code'] = $plain_code;
        }
        return $data;
    }

    private function generate_code(string $deadline_mysql): string {
        $prefix = 'te_' . gmdate('Ym', strtotime($deadline_mysql) ?: time()) . '_';
        return $prefix . strtolower(SC_Utils::random_token(6));
    }

    private function token_response_row(array $token): array {
        return [
            'token_issue_seq' => (int) ($token['token_issue_seq'] ?? 0),
            'plain_token' => (string) ($token['token'] ?? $token['plain_token'] ?? ''),
            'token_prefix' => (string) ($token['token_prefix'] ?? ''),
            'token_url' => (string) ($token['token_url'] ?? ''),
            'status' => (string) ($token['status'] ?? 'issued'),
            'expires_at' => $this->to_iso8601((string) ($token['expires_at'] ?? '')),
        ];
    }

    private function token_admin_row(array $token): array {
        return [
            'id' => (int) ($token['id'] ?? 0),
            'token_issue_seq' => isset($token['token_issue_seq']) ? (int) $token['token_issue_seq'] : null,
            'token_prefix' => (string) ($token['token_prefix'] ?? ''),
            'status' => (string) ($token['status'] ?? ''),
            'issued_at' => (string) ($token['issued_at'] ?? ''),
            'started_at' => $token['started_at'] ?? null,
            'submitted_at' => $token['submitted_at'] ?? null,
            'expires_at' => (string) ($token['expires_at'] ?? ''),
        ];
    }

    private function token_progress_row(array $token): array {
        $result = $this->db->find_result_by_token_id((int) ($token['id'] ?? 0));
        return array_merge($this->token_admin_row($token), [
            'high_stress_flag' => $result ? (bool) ($result['high_stress_flag'] ?? false) : null,
            'interview_requested' => $result ? (bool) ($result['interview_requested'] ?? false) : null,
        ]);
    }

    private function result_row(array $token, array $result): array {
        return [
            'token_issue_seq' => isset($token['token_issue_seq']) ? (int) $token['token_issue_seq'] : null,
            'token_prefix' => (string) ($token['token_prefix'] ?? ''),
            'submitted_at' => $token['submitted_at'] ?? null,
            'score_a' => (int) ($result['score_a'] ?? 0),
            'score_b' => (int) ($result['score_b'] ?? 0),
            'score_c' => (int) ($result['score_c'] ?? 0),
            'score_d' => (int) ($result['score_d'] ?? 0),
            'score_ac' => (int) ($result['score_ac'] ?? 0),
            'high_stress_flag' => (bool) ($result['high_stress_flag'] ?? false),
            'high_stress_reason' => $result['high_stress_reason'] ?? null,
            'interview_requested' => (bool) ($result['interview_requested'] ?? false),
        ];
    }

    private function to_mysql_datetime(string $value): ?string {
        $value = trim($value);
        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value)) {
            $ts = strtotime($value);
            return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2}))?$/', $value, $m)) {
            $normalized = $m[1] . ' ' . $m[2] . ':' . ($m[3] ?? '00');
            return strtotime($normalized) === false ? null : $normalized;
        }
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private function to_iso8601(string $value): string {
        $ts = strtotime($value);
        return $ts ? gmdate(DATE_ATOM, $ts) : $value;
    }

    private function error(string $code, string $message, int $status): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'status' => $status]];
    }
}
