<?php

class SC_API_Service {
    private SC_DB $db;
    private SC_Token_Service $tokens;
    private SC_Test_Execution_Service $test_executions;
    private SC_Response_Service $responses;
    private SC_Security_Service $security;
    private SC_Report_Service $reports;
    private SC_Admin_Pages $admin_pages;

    public function __construct(SC_DB $db, SC_Token_Service $tokens, SC_Test_Execution_Service $test_executions, SC_Response_Service $responses, SC_Security_Service $security, SC_Report_Service $reports, SC_Admin_Pages $admin_pages) {
        $this->db = $db;
        $this->tokens = $tokens;
        $this->test_executions = $test_executions;
        $this->responses = $responses;
        $this->security = $security;
        $this->reports = $reports;
        $this->admin_pages = $admin_pages;
    }

    public function register_routes(): void {
        if (!function_exists('register_rest_route')) {
            return;
        }
        $namespace = 'sc/v1';
        register_rest_route($namespace, '/survey/(?P<token>[A-Za-z0-9\-_]+)', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->survey_get((string) $request->get_param('token'), ['origin' => $request->get_header('origin')]);
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/survey/(?P<token>[A-Za-z0-9\-_]+)/start', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->survey_start((string) $request->get_param('token'), ['origin' => $request->get_header('origin')]);
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/survey/(?P<token>[A-Za-z0-9\-_]+)/submit', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->survey_submit((string) $request->get_param('token'), (array) $request->get_json_params(), ['origin' => $request->get_header('origin')]);
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/result/(?P<token>[A-Za-z0-9\-_]+)', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->result_get((string) $request->get_param('token'), ['origin' => $request->get_header('origin')]);
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/result/(?P<token>[A-Za-z0-9\-_]+)/interview-request', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->result_interview_request((string) $request->get_param('token'), (array) $request->get_json_params(), ['origin' => $request->get_header('origin')]);
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        $admin_permission = function ($request = null) {
            return $this->authorize_admin([]);
        };
        register_rest_route($namespace, '/admin/test-executions', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_test_executions_get((array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->admin_test_executions_post((array) $request->get_json_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_test_execution_get((string) $request->get_param('code'), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)/tokens', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_test_execution_tokens_get((string) $request->get_param('code'), (array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)/tokens', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->admin_test_execution_tokens_post((string) $request->get_param('code'), (array) $request->get_json_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)/progress', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_test_execution_progress_get((string) $request->get_param('code'), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)/results', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_test_execution_results_get((string) $request->get_param('code'), (array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)/high-stress', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_test_execution_high_stress_get((string) $request->get_param('code'), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/test-executions/(?P<code>[A-Za-z0-9\-_]+)/close', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->admin_test_execution_close((string) $request->get_param('code'), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/progress', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_progress_get((array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/report/summary', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_report_summary_get((array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/report/export', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_report_export_get((array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        $external_context = function ($request): array {
            return [
                'origin' => $request->get_header('origin'),
                'api_key' => $this->api_key_from_request($request),
            ];
        };
        register_rest_route($namespace, '/external/test-executions', [
            'methods' => 'POST',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_executions_post((array) $request->get_json_params(), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/external/test-executions/(?P<code>[A-Za-z0-9\-_]+)/tokens', [
            'methods' => 'POST',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_execution_tokens_post((string) $request->get_param('code'), (array) $request->get_json_params(), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/external/test-executions/(?P<code>[A-Za-z0-9\-_]+)/progress', [
            'methods' => 'GET',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_execution_progress_get((string) $request->get_param('code'), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/external/test-executions/(?P<code>[A-Za-z0-9\-_]+)/results', [
            'methods' => 'GET',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_execution_results_get((string) $request->get_param('code'), (array) $request->get_params(), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/external/test-executions/(?P<code>[A-Za-z0-9\-_]+)/high-stress', [
            'methods' => 'GET',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_execution_high_stress_get((string) $request->get_param('code'), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/external/test-executions/(?P<code>[A-Za-z0-9\-_]+)/summary', [
            'methods' => 'GET',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_execution_summary_get((string) $request->get_param('code'), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/external/test-executions/(?P<code>[A-Za-z0-9\-_]+)/close', [
            'methods' => 'POST',
            'callback' => function ($request) use ($external_context) {
                return $this->external_test_execution_close((string) $request->get_param('code'), $external_context($request));
            },
            'permission_callback' => function ($request = null) {
                return true;
            },
        ]);
        register_rest_route($namespace, '/admin/settings', [
            'methods' => 'GET',
            'callback' => function ($request) {
                return $this->admin_settings_get((array) $request->get_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
        register_rest_route($namespace, '/admin/settings', [
            'methods' => 'POST',
            'callback' => function ($request) {
                return $this->admin_settings_post((array) $request->get_json_params(), ['is_admin' => true, 'origin' => $request->get_header('origin'), 'nonce' => $request->get_header('x-wp-nonce')]);
            },
            'permission_callback' => $admin_permission,
        ]);
    }

    public function dispatch(string $method, string $path, array $payload = [], array $context = []): array {
        $method = strtoupper($method);
        $path = preg_replace('#^/wp-json/sc/v1#', '', $path) ?? $path;
        $path = '/' . trim($path, '/');
        $is_admin_route = str_starts_with($path, '/admin/');
        $is_external_route = str_starts_with($path, '/external/');
        if ($method === 'OPTIONS') {
            $origin = isset($context['origin']) ? (string) $context['origin'] : null;
            if ($is_admin_route && $origin && !$this->security->is_allowed_origin($origin)) {
                return $this->error('FORBIDDEN_ORIGIN', 'CORS許可外', 403);
            }
            return $this->ok(['headers' => ($is_admin_route || $is_external_route) ? $this->security->cors_headers($origin) : $this->security->public_cors_headers()]);
        }

        if (preg_match('#^/survey/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->survey_get($m[1], $context);
        }
        if (preg_match('#^/survey/([^/]+)/start$#', $path, $m) && $method === 'POST') {
            return $this->survey_start($m[1], $context);
        }
        if (preg_match('#^/survey/([^/]+)/submit$#', $path, $m) && $method === 'POST') {
            return $this->survey_submit($m[1], $payload, $context);
        }
        if (preg_match('#^/result/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->result_get($m[1], $context);
        }
        if (preg_match('#^/result/([^/]+)/interview-request$#', $path, $m) && $method === 'POST') {
            return $this->result_interview_request($m[1], $payload, $context);
        }
        if ($path === '/external/test-executions' && $method === 'POST') {
            return $this->external_test_executions_post($payload, $context);
        }
        if (preg_match('#^/external/test-executions/([^/]+)/tokens$#', $path, $m) && $method === 'POST') {
            return $this->external_test_execution_tokens_post(rawurldecode($m[1]), $payload, $context);
        }
        if (preg_match('#^/external/test-executions/([^/]+)/progress$#', $path, $m) && $method === 'GET') {
            return $this->external_test_execution_progress_get(rawurldecode($m[1]), $context);
        }
        if (preg_match('#^/external/test-executions/([^/]+)/results$#', $path, $m) && $method === 'GET') {
            return $this->external_test_execution_results_get(rawurldecode($m[1]), $payload, $context);
        }
        if (preg_match('#^/external/test-executions/([^/]+)/high-stress$#', $path, $m) && $method === 'GET') {
            return $this->external_test_execution_high_stress_get(rawurldecode($m[1]), $context);
        }
        if (preg_match('#^/external/test-executions/([^/]+)/summary$#', $path, $m) && $method === 'GET') {
            return $this->external_test_execution_summary_get(rawurldecode($m[1]), $context);
        }
        if (preg_match('#^/external/test-executions/([^/]+)/close$#', $path, $m) && $method === 'POST') {
            return $this->external_test_execution_close(rawurldecode($m[1]), $context);
        }
        if ($path === '/admin/test-executions' && $method === 'POST') {
            return $this->admin_test_executions_post($payload, $context);
        }
        if ($path === '/admin/test-executions' && $method === 'GET') {
            return $this->admin_test_executions_get($payload, $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)$#', $path, $m) && $method === 'GET') {
            return $this->admin_test_execution_get(rawurldecode($m[1]), $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)/tokens$#', $path, $m) && $method === 'POST') {
            return $this->admin_test_execution_tokens_post(rawurldecode($m[1]), $payload, $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)/tokens$#', $path, $m) && $method === 'GET') {
            return $this->admin_test_execution_tokens_get(rawurldecode($m[1]), $payload, $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)/progress$#', $path, $m) && $method === 'GET') {
            return $this->admin_test_execution_progress_get(rawurldecode($m[1]), $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)/results$#', $path, $m) && $method === 'GET') {
            return $this->admin_test_execution_results_get(rawurldecode($m[1]), $payload, $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)/high-stress$#', $path, $m) && $method === 'GET') {
            return $this->admin_test_execution_high_stress_get(rawurldecode($m[1]), $context);
        }
        if (preg_match('#^/admin/test-executions/([^/]+)/close$#', $path, $m) && $method === 'POST') {
            return $this->admin_test_execution_close(rawurldecode($m[1]), $context);
        }
        if ($path === '/admin/report/export' && $method === 'GET') {
            return $this->admin_report_export_get($payload, $context);
        }
        if ($path === '/admin/progress' && $method === 'GET') {
            return $this->admin_progress_get($payload, $context);
        }
        if ($path === '/admin/report/summary' && $method === 'GET') {
            return $this->admin_report_summary_get($payload, $context);
        }
        if ($path === '/admin/report/export' && $method === 'GET') {
            return $this->admin_report_export_get($payload, $context);
        }
        if ($path === '/admin/settings' && $method === 'GET') {
            return $this->admin_settings_get($payload, $context);
        }
        if ($path === '/admin/settings' && $method === 'POST') {
            return $this->admin_settings_post($payload, $context);
        }
        return $this->error('NOT_FOUND', 'Not found', 404);
    }

    public function survey_get(string $token, array $context = []): array {
        if (!$this->rate_limit('survey', $token, $context)) {
            return $this->error('RATE_LIMITED', 'アクセスが制限されています。', 429);
        }
        $check = $this->tokens->assert_token_accessible($token);
        if (!$check['ok']) {
            return $check;
        }
        $token_row = $check['data'];
        return $this->ok([
            'status' => $token_row['status'],
            'expires_at' => $this->to_iso8601((string) $token_row['expires_at']),
            'questions' => array_values(array_map(static function (array $question): array {
                return [
                    'question_no' => (int) $question['question_no'],
                    'section_code' => (string) $question['section_code'],
                    'text' => (string) $question['question_text_ja'],
                    'options' => [1, 2, 3, 4],
                ];
            }, $this->db->get_questions(true))),
        ]);
    }

    public function survey_start(string $token, array $context = []): array {
        if (!$this->rate_limit('survey', $token, $context)) {
            return $this->error('RATE_LIMITED', 'アクセスが制限されています。', 429);
        }
        $started = $this->responses->start($token);
        if (!$started['ok']) {
            return $started;
        }
        $row = $started['data'];
        return $this->ok([
            'status' => (string) ($row['status'] ?? 'started'),
            'expires_at' => $this->to_iso8601((string) ($row['expires_at'] ?? '')),
        ]);
    }

    public function survey_submit(string $token, array $payload = [], array $context = []): array {
        if (!$this->rate_limit('submit', $token, $context)) {
            return $this->error('RATE_LIMITED', 'アクセスが制限されています。', 429);
        }
        $answers = (array) ($payload['answers'] ?? []);
        $interview_requested = SC_Utils::boolish($payload['interview_requested'] ?? false);
        return $this->responses->submit($token, $answers, $interview_requested, $context);
    }

    public function result_get(string $token, array $context = []): array {
        if (!$this->rate_limit('result', $token, $context)) {
            return $this->error('RATE_LIMITED', 'アクセスが制限されています。', 429);
        }
        return $this->responses->result($token);
    }

    public function result_interview_request(string $token, array $payload = [], array $context = []): array {
        return $this->responses->interview_request($token);
    }

    public function external_test_executions_post(array $payload = [], array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        $created = $this->test_executions->create(
            (string) ($payload['title'] ?? ''),
            isset($payload['delivery_date']) ? (string) $payload['delivery_date'] : null,
            (string) ($payload['answer_deadline'] ?? ''),
            isset($payload['target_year']) ? (int) $payload['target_year'] : null
        );
        return $this->sanitize_external_response($created);
    }

    public function external_test_execution_tokens_post(string $code, array $payload = [], array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        return $this->sanitize_external_response($this->test_executions->issue_tokens($code, (int) ($payload['count'] ?? 1), isset($payload['base_url']) && is_string($payload['base_url']) ? $payload['base_url'] : null));
    }

    public function external_test_execution_progress_get(string $code, array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        return $this->sanitize_external_response($this->test_executions->progress($code));
    }

    public function external_test_execution_results_get(string $code, array $payload = [], array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        return $this->sanitize_external_response($this->test_executions->results($code, $payload));
    }

    public function external_test_execution_high_stress_get(string $code, array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        return $this->sanitize_external_response($this->test_executions->high_stress($code));
    }

    public function external_test_execution_summary_get(string $code, array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        return $this->sanitize_external_response($this->test_executions->summary($code));
    }

    public function external_test_execution_close(string $code, array $context = []): array {
        if ($error = $this->assert_external_api_key($context)) { return $error; }
        return $this->sanitize_external_response($this->test_executions->close($code));
    }

    public function admin_test_executions_post(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->create(
            (string) ($payload['title'] ?? ''),
            isset($payload['delivery_date']) ? (string) $payload['delivery_date'] : null,
            (string) ($payload['answer_deadline'] ?? ''),
            isset($payload['target_year']) ? (int) $payload['target_year'] : null
        );
    }

    public function admin_test_executions_get(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        $filters = [];
        if (isset($payload['target_year'])) { $filters['target_year'] = (int) $payload['target_year']; }
        if (isset($payload['status'])) { $filters['status'] = (string) $payload['status']; }
        $rows = array_map(fn(array $row): array => $this->test_executions->public_row($row), $this->db->list_test_executions($filters));
        return $this->ok(['test_executions' => $rows]);
    }

    public function admin_test_execution_get(string $code, array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        $row = $this->test_executions->find_by_code($code);
        if (!$row) { return $this->error('INVALID_TEST_EXECUTION_CODE', 'テスト実施コードが無効です。', 404); }
        return $this->ok($this->test_executions->public_row($row));
    }

    public function admin_test_execution_tokens_post(string $code, array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->issue_tokens($code, (int) ($payload['count'] ?? 1), isset($payload['base_url']) && is_string($payload['base_url']) ? $payload['base_url'] : null);
    }

    public function admin_test_execution_tokens_get(string $code, array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->list_tokens($code, $payload);
    }

    public function admin_test_execution_progress_get(string $code, array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->progress($code);
    }

    public function admin_test_execution_results_get(string $code, array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->results($code, $payload);
    }

    public function admin_test_execution_high_stress_get(string $code, array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->high_stress($code);
    }

    public function admin_test_execution_close(string $code, array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) { return $error; }
        if ($error = $this->assert_admin_nonce($context)) { return $error; }
        if (!$this->authorize_admin($context)) { return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401); }
        return $this->test_executions->close($code);
    }


    public function admin_progress_get(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) {
            return $error;
        }
        if ($error = $this->assert_admin_nonce($context)) {
            return $error;
        }
        if (!$this->authorize_admin($context)) {
            return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401);
        }
        $target_year = (int) ($payload['target_year'] ?? date('Y'));
        return $this->ok($this->reports->progress_summary($target_year));
    }

    public function admin_report_summary_get(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) {
            return $error;
        }
        if ($error = $this->assert_admin_nonce($context)) {
            return $error;
        }
        if (!$this->authorize_admin($context)) {
            return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401);
        }
        $target_year = (int) ($payload['target_year'] ?? date('Y'));
        return $this->ok($this->reports->report_summary($target_year));
    }

    public function admin_report_export_get(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) {
            return $error;
        }
        if ($error = $this->assert_admin_nonce($context)) {
            return $error;
        }
        if (!$this->authorize_admin($context)) {
            return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401);
        }
        $target_year = (int) ($payload['target_year'] ?? date('Y'));
        return $this->ok([
            'target_year' => $target_year,
            'filename' => sprintf('stresscheck-report-%d.csv', $target_year),
            'csv' => $this->reports->export_report_csv($target_year),
        ]);
    }


    public function admin_settings_get(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) {
            return $error;
        }
        if ($error = $this->assert_admin_nonce($context)) {
            return $error;
        }
        if (!$this->authorize_admin($context)) {
            return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401);
        }
        return $this->ok($this->security->settings());
    }

    public function admin_settings_post(array $payload = [], array $context = []): array {
        if ($error = $this->assert_admin_origin($context)) {
            return $error;
        }
        if ($error = $this->assert_admin_nonce($context)) {
            return $error;
        }
        if (!$this->authorize_admin($context)) {
            return $this->error('ADMIN_REQUIRED', '管理者権限が必要です。', 401);
        }
        $this->security->update_settings($payload);
        return $this->ok($this->security->settings());
    }

    private function rate_limit(string $bucket, string $token, array $context): bool {
        $settings = $this->security->settings();
        $limits = $settings['rate_limits'][$bucket] ?? ['limit' => 30, 'window' => 60];
        $identifier = ($context['ip'] ?? 'cli') . '|' . $token . '|' . $bucket;
        return $this->security->check_rate_limit($bucket, $identifier, (int) $limits['limit'], (int) $limits['window']);
    }

    private function authorize_admin(array $context): bool {
        if (isset($context['is_admin'])) {
            return (bool) $context['is_admin'];
        }
        if (function_exists('current_user_can')) {
            return current_user_can($this->security->settings_capability());
        }
        return true;
    }

    private function assert_admin_origin(array $context): ?array {
        $origin = isset($context['origin']) ? trim((string) $context['origin']) : '';
        if ($origin !== '' && !$this->security->is_allowed_origin($origin)) {
            return $this->error('FORBIDDEN_ORIGIN', 'CORS許可外', 403);
        }
        return null;
    }

    private function assert_admin_nonce(array $context): ?array {
        $nonce = isset($context['nonce']) ? (string) $context['nonce'] : '';
        if (!$this->security->verify_admin_nonce($nonce)) {
            return $this->error('INVALID_NONCE', 'nonce が無効です。', 403);
        }
        return null;
    }

    private function assert_external_api_key(array $context): ?array {
        $origin = isset($context['origin']) ? trim((string) $context['origin']) : '';
        if ($origin !== '' && !$this->security->is_allowed_origin($origin)) {
            return $this->error('FORBIDDEN_ORIGIN', 'CORS許可外', 403);
        }
        $api_key = isset($context['api_key']) ? (string) $context['api_key'] : '';
        if (!$this->security->verify_external_api_key($api_key)) {
            return $this->error('EXTERNAL_AUTH_REQUIRED', '外部連携APIの認証情報が必要です。', 401);
        }
        return null;
    }

    private function api_key_from_request($request): string {
        $api_key = (string) $request->get_header('x-sc-api-key');
        if ($api_key !== '') {
            return $api_key;
        }
        return $this->api_key_from_authorization((string) $request->get_header('authorization'));
    }

    private function api_key_from_authorization(string $authorization): string {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function sanitize_external_response(array $response): array {
        if (!($response['ok'] ?? false) || !isset($response['data']) || !is_array($response['data'])) {
            return $response;
        }
        $response['data'] = $this->sanitize_external_data($response['data']);
        return $response;
    }

    private function sanitize_external_data(array $data): array {
        foreach (['id', 'test_execution_id', 'execution_code_prefix', 'token_hash', 'reissued_from_id', 'created_at', 'updated_at'] as $internal_key) {
            unset($data[$internal_key]);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitize_external_data($value);
            }
        }
        return $data;
    }

    private function to_iso8601(string $value): string {
        $ts = strtotime($value);
        return $ts ? gmdate(DATE_ATOM, $ts) : $value;
    }

    private function ok(array $data): array {
        return ['ok' => true, 'data' => $data];
    }

    private function error(string $code, string $message, int $status): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'status' => $status]];
    }
}
