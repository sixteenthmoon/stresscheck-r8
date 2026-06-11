<?php

class SC_Token_Service {
    private SC_DB $db;
    private SC_Security_Service $security;

    public function __construct(SC_DB $db, SC_Security_Service $security) {
        $this->db = $db;
        $this->security = $security;
    }

    public function token_url_base(): string {
        return $this->security->frontend_check_url();
    }

    public function generate_plain_token(int $bytes = 16): string {
        return SC_Utils::random_token($bytes);
    }

    public function hash_token(string $token): string {
        return SC_Utils::hash_token($token);
    }

    public function issue_token(int $target_year, string $expires_at, ?string $base_url = null, ?int $test_execution_id = null, ?int $token_issue_seq = null): array {
        $plain = $this->generate_plain_token();
        $row = [
            'test_execution_id' => $test_execution_id,
            'token_hash' => $this->hash_token($plain),
            'plain_token' => $plain,
            'token_prefix' => substr($plain, 0, 10),
            'token_issue_seq' => $token_issue_seq,
            'status' => 'issued',
            'target_year' => $target_year,
            'issued_at' => SC_Utils::now_mysql(),
            'started_at' => null,
            'submitted_at' => null,
            'expires_at' => $expires_at,
            'reissued_from_id' => null,
            'created_at' => SC_Utils::now_mysql(),
            'updated_at' => SC_Utils::now_mysql(),
        ];
        $id = $this->db->store_token($row);
        return [
            'id' => $id,
            'test_execution_id' => $test_execution_id,
            'token_issue_seq' => $token_issue_seq,
            'token' => $plain,
            'plain_token' => $plain,
            'token_hash' => $row['token_hash'],
            'token_prefix' => $row['token_prefix'],
            'token_url' => $this->build_token_url($plain, $base_url),
            'expires_at' => $expires_at,
            'target_year' => $target_year,
        ];
    }

    public function issue_tokens(int $count, int $target_year, string $expires_at, ?string $base_url = null): array {
        $tokens = [];
        for ($i = 0; $i < $count; $i++) {
            $tokens[] = $this->issue_token($target_year, $expires_at, $base_url);
        }
        return $tokens;
    }

    public function issue_tokens_for_execution(int $test_execution_id, int $count, int $target_year, string $expires_at, ?string $base_url = null): array {
        $tokens = [];
        $seq = $this->db->next_token_issue_seq($test_execution_id);
        for ($i = 0; $i < $count; $i++) {
            $tokens[] = $this->issue_token($target_year, $expires_at, $base_url, $test_execution_id, $seq + $i);
        }
        return $tokens;
    }

    public function build_token_url(string $plain_token, ?string $base_url = null): string {
        $base_url = $base_url ?: $this->token_url_base();
        $base_url = trim($base_url);
        if (!filter_var($base_url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($base_url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $base_url = $this->token_url_base();
        }
        $separator = str_contains($base_url, '?') ? '&' : '?';
        return $base_url . $separator . 't=' . rawurlencode($plain_token);
    }

    public function find_token(string $plain_token): ?array {
        $row = $this->db->find_token_by_hash($this->hash_token($plain_token));
        return $row ?: null;
    }

    public function assert_token_accessible(string $plain_token): array {
        $token = $this->find_token($plain_token);
        if (!$token) {
            return $this->error('INVALID_TOKEN', 'このURLは無効です。管理者にお問い合わせください。', 404);
        }
        $status = (string) ($token['status'] ?? '');
        if ($status === 'revoked' || $status === 'reissued') {
            return $this->error('SUBMITTED_TOKEN', 'このURLは無効です。管理者にお問い合わせください。', 409);
        }
        if ($this->is_expired($token)) {
            $this->expire_token((int) $token['id']);
            $token['status'] = 'expired';
            return $this->error('EXPIRED_TOKEN', 'このURLは期限切れです。管理者にお問い合わせください。', 410);
        }
        return ['ok' => true, 'data' => $token];
    }

    public function is_expired(array $token): bool {
        $expires_at = strtotime((string) ($token['expires_at'] ?? ''));
        return $expires_at !== false && $expires_at < time();
    }

    public function start_token(string $plain_token): array {
        $check = $this->assert_token_accessible($plain_token);
        if (!$check['ok']) {
            return $check;
        }
        $token = $check['data'];
        if (!in_array($token['status'], ['issued', 'started'], true)) {
            return $this->error('SUBMITTED_TOKEN', 'このURLは無効です。管理者にお問い合わせください。', 409);
        }
        if ($token['status'] === 'issued') {
            $this->db->update_token((int) $token['id'], [
                'status' => 'started',
                'started_at' => SC_Utils::now_mysql(),
                'updated_at' => SC_Utils::now_mysql(),
            ]);
            $token['status'] = 'started';
        }
        return ['ok' => true, 'data' => $token];
    }

    public function mark_submitted(int $token_id): void {
        $this->db->update_token($token_id, [
            'status' => 'submitted',
            'submitted_at' => SC_Utils::now_mysql(),
            'updated_at' => SC_Utils::now_mysql(),
        ]);
    }

    public function expire_token(int $token_id): void {
        $this->db->update_token($token_id, [
            'status' => 'expired',
            'updated_at' => SC_Utils::now_mysql(),
        ]);
    }

    public function revoke_token(int $token_id): void {
        $this->db->update_token($token_id, [
            'status' => 'revoked',
            'updated_at' => SC_Utils::now_mysql(),
        ]);
    }

    public function reissue_token(int $token_id, string $expires_at, ?string $base_url = null): array {
        $old = $this->db->find_token_by_id($token_id);
        if (!$old) {
            return $this->error('INVALID_TOKEN', 'このURLは無効です。管理者にお問い合わせください。', 404);
        }
        $this->db->update_token($token_id, [
            'status' => 'reissued',
            'updated_at' => SC_Utils::now_mysql(),
        ]);
        $test_execution_id = isset($old['test_execution_id']) ? (int) $old['test_execution_id'] : null;
        $token_issue_seq = $test_execution_id ? $this->db->next_token_issue_seq($test_execution_id) : null;
        $new = $this->issue_token((int) $old['target_year'], $expires_at, $base_url, $test_execution_id, $token_issue_seq);
        $this->db->update_token((int) $new['id'], [
            'reissued_from_id' => $token_id,
            'updated_at' => SC_Utils::now_mysql(),
        ]);
        $new['reissued_from_id'] = $token_id;
        return ['ok' => true, 'data' => $new];
    }

    public function export_rows(array $tokens): array {
        $rows = [];
        foreach ($tokens as $token) {
            $plain = $token['plain_token'] ?? null;
            $token_url = $plain ? $this->build_token_url($plain) : $this->build_token_url($token['token_prefix'] ?? '');
            $rows[] = [
                'token_url' => $token_url,
                'expires_at' => $token['expires_at'] ?? '',
                'token_prefix' => $token['token_prefix'] ?? '',
                'target_year' => $token['target_year'] ?? '',
            ];
        }
        return $rows;
    }

    public function csv(array $rows): string {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, ['token_url', 'expires_at', 'token_prefix', 'target_year']);
        foreach ($rows as $row) {
            fputcsv($fp, [$row['token_url'], $row['expires_at'], $row['token_prefix'], $row['target_year']]);
        }
        rewind($fp);
        return stream_get_contents($fp) ?: '';
    }

    private function error(string $code, string $message, int $status): array {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
            ],
        ];
    }
}
