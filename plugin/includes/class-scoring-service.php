<?php

class SC_Scoring_Service {
    public function score(array $answers, array $questions): array {
        $answers = SC_Utils::normalize_answers($answers);
        if (count($answers) !== 57) {
            return $this->error('VALIDATION_ERROR', '57項目すべて回答してください。', 400);
        }
        $scores = [
            'score_a' => 0,
            'score_b' => 0,
            'score_c' => 0,
            'score_d' => 0,
        ];
        foreach ($questions as $question_no => $question) {
            $question_no = (int) $question_no;
            if (!isset($answers[$question_no])) {
                return $this->error('VALIDATION_ERROR', '未回答の項目があります。', 400);
            }
            $answer = (int) $answers[$question_no];
            if ($answer < 1 || $answer > 4) {
                return $this->error('VALIDATION_ERROR', '回答値は1〜4のみ有効です。', 400);
            }
            $score = !empty($question['is_reverse_scoring']) ? 5 - $answer : $answer;
            $section = (string) ($question['section_code'] ?? '');
            $key = match ($section) {
                'A' => 'score_a',
                'B' => 'score_b',
                'C' => 'score_c',
                'D' => 'score_d',
                default => 'score_a',
            };
            $scores[$key] += $score;
        }
        $scores['score_ac'] = $scores['score_a'] + $scores['score_c'];
        $scores['high_stress_flag'] = 0;
        $scores['high_stress_reason'] = null;
        if ($scores['score_b'] >= 77) {
            $scores['high_stress_flag'] = 1;
            $scores['high_stress_reason'] = 'B77';
        } elseif ($scores['score_ac'] >= 76 && $scores['score_b'] >= 63) {
            $scores['high_stress_flag'] = 1;
            $scores['high_stress_reason'] = 'AC76_B63';
        }
        return ['ok' => true, 'data' => $scores];
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
