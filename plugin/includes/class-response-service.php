<?php

class SC_Response_Service {
    private SC_DB $db;
    private SC_Token_Service $tokens;
    private SC_Scoring_Service $scoring;
    private SC_Security_Service $security;

    public function __construct(SC_DB $db, SC_Token_Service $tokens, SC_Scoring_Service $scoring, SC_Security_Service $security) {
        $this->db = $db;
        $this->tokens = $tokens;
        $this->scoring = $scoring;
        $this->security = $security;
    }

    public function start(string $plain_token): array {
        return $this->tokens->start_token($plain_token);
    }

    public function submit(string $plain_token, array $answers, bool $interview_requested = false, array $context = []): array {
        $token_check = $this->tokens->assert_token_accessible($plain_token);
        if (!$token_check['ok']) {
            return $token_check;
        }
        $token = $token_check['data'];
        if (($token['status'] ?? '') === 'submitted') {
            return $this->error('SUBMITTED_TOKEN', 'このURLは既に回答済みです。', 409);
        }
        $answers = SC_Utils::normalize_answers($answers);
        if (count($answers) !== 57) {
            return $this->error('VALIDATION_ERROR', '57項目すべて回答してください。', 400);
        }
        foreach ($answers as $answer) {
            if ($answer < 1 || $answer > 4) {
                return $this->error('VALIDATION_ERROR', '回答値は1〜4のみ有効です。', 400);
            }
        }
        $questions = $this->db->get_question_map(true);
        $scoring = $this->scoring->score($answers, $questions);
        if (!$scoring['ok']) {
            return $scoring;
        }
        $score = $scoring['data'];
        $now = SC_Utils::now_mysql();
        $this->db->store_response([
            'token_id' => (int) $token['id'],
            'answers_json' => SC_Utils::json_encode_safe($answers),
            'user_agent_hash' => !empty($context['user_agent']) ? $this->security->hash_identifier((string) $context['user_agent']) : null,
            'ip_hash' => !empty($context['ip']) ? $this->security->hash_identifier((string) $context['ip']) : null,
            'submitted_at' => $now,
            'created_at' => $now,
        ]);
        $this->db->store_result([
            'token_id' => (int) $token['id'],
            'target_year' => (int) $token['target_year'],
            'score_a' => (int) $score['score_a'],
            'score_b' => (int) $score['score_b'],
            'score_c' => (int) $score['score_c'],
            'score_d' => (int) $score['score_d'],
            'score_ac' => (int) $score['score_ac'],
            'high_stress_flag' => (int) $score['high_stress_flag'],
            'high_stress_reason' => $score['high_stress_reason'],
            'interview_requested' => $interview_requested ? 1 : 0,
            'interview_requested_at' => $interview_requested ? $now : null,
            'calculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->tokens->mark_submitted((int) $token['id']);
        return [
            'ok' => true,
            'data' => [
                'status' => 'submitted',
                'result_url' => $this->security->frontend_result_url($plain_token),
                'high_stress_flag' => (bool) $score['high_stress_flag'],
            ],
        ];
    }

    public function result(string $plain_token): array {
        $token_check = $this->tokens->assert_token_accessible($plain_token);
        if (!$token_check['ok']) {
            return $token_check;
        }
        $token = $token_check['data'];
        $result = $this->db->find_result_by_token_id((int) $token['id']);
        if (!$result) {
            return $this->error('VALIDATION_ERROR', 'まだ結果が作成されていません。', 404);
        }
        return [
            'ok' => true,
            'data' => [
                'target_year' => (int) $result['target_year'],
                'score_a' => (int) $result['score_a'],
                'score_b' => (int) $result['score_b'],
                'score_c' => (int) $result['score_c'],
                'score_d' => (int) $result['score_d'],
                'score_ac' => (int) $result['score_ac'],
                'submitted_at' => $this->to_iso8601((string) ($token['submitted_at'] ?? $result['calculated_at'] ?? $result['created_at'] ?? '')),
                'token_prefix' => (string) ($token['token_prefix'] ?? ''),
                'high_stress_flag' => (bool) $result['high_stress_flag'],
                'high_stress_reason' => $result['high_stress_reason'],
                'interview_requested' => (bool) $result['interview_requested'],
                'messages' => [[
                    'type' => 'selfcare',
                    'title' => 'セルフケア情報',
                    'body' => '具体的なセルフケア情報や相談窓口は、勤務先にお問い合わせください。',
                ]],
            ],
        ];
    }

    public function interview_request(string $plain_token): array {
        $token_check = $this->tokens->assert_token_accessible($plain_token);
        if (!$token_check['ok']) {
            return $token_check;
        }
        $token = $token_check['data'];
        $result = $this->db->find_result_by_token_id((int) $token['id']);
        if (!$result) {
            return $this->error('VALIDATION_ERROR', 'まだ結果が作成されていません。', 404);
        }
        $now = SC_Utils::now_mysql();
        $this->db->update_result((int) $token['id'], [
            'interview_requested' => 1,
            'interview_requested_at' => $now,
            'updated_at' => $now,
        ]);
        return [
            'ok' => true,
            'data' => [
                'interview_requested' => true,
                'message' => '面接指導希望を記録しました。具体的な連絡方法は勤務先にお問い合わせください。',
            ],
        ];
    }

    private function to_iso8601(string $value): string {
        $ts = strtotime($value);
        return $ts ? gmdate(DATE_ATOM, $ts) : $value;
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
