<?php

class SC_Report_Service {
    private SC_DB $db;

    public function __construct(SC_DB $db) {
        $this->db = $db;
    }

    public function progress_summary(int $target_year): array {
        $issued = $this->db->count_tokens(['target_year' => $target_year]);
        $submitted = $this->db->count_tokens(['target_year' => $target_year, 'status' => 'submitted']);
        $expired = $this->db->count_tokens(['target_year' => $target_year, 'status' => 'expired']);
        $completion_rate = $issued > 0 ? round(($submitted / $issued) * 100, 1) : 0.0;
        return [
            'issued_count' => $issued,
            'submitted_count' => $submitted,
            'expired_count' => $expired,
            'completion_rate' => $completion_rate,
        ];
    }

    public function report_summary(int $target_year): array {
        $issued = $this->db->count_tokens(['target_year' => $target_year]);
        $submitted = $this->db->count_tokens(['target_year' => $target_year, 'status' => 'submitted']);
        $high_stress = $this->db->count_results(['target_year' => $target_year, 'high_stress_flag' => 1]);
        $interview_requested = $this->db->count_results(['target_year' => $target_year, 'interview_requested' => 1]);
        $completion_rate = $issued > 0 ? round(($submitted / $issued) * 100, 1) : 0.0;
        $high_stress_rate = $submitted > 0 ? round(($high_stress / $submitted) * 100, 1) : 0.0;
        $period_start = $this->date_only($this->db->first_token_issued_at(['target_year' => $target_year]) ?? sprintf('%d-01-01', $target_year));
        $period_end = $this->date_only($this->db->last_token_issued_at(['target_year' => $target_year]) ?? sprintf('%d-12-31', $target_year));
        return [
            'target_year' => $target_year,
            'period_start' => $period_start,
            'period_end' => $period_end,
            'issued_token_count' => $issued,
            'submitted_count' => $submitted,
            'completion_rate' => $completion_rate,
            'high_stress_count' => $high_stress,
            'high_stress_rate' => $high_stress_rate,
            'interview_requested_count' => $interview_requested,
            'report_generated_at' => SC_Utils::now_mysql(),
            'notice' => '労働基準監督署への提出要否は事業場規模・最新法令を確認してください。本サービス側では企業・部署・拠点別の判定は行いません。',
        ];
    }

    public function export_report_csv(int $target_year): string {
        $report = $this->report_summary($target_year);
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, array_keys($report));
        fputcsv($fp, array_values($report));
        rewind($fp);
        return stream_get_contents($fp) ?: '';
    }

    public function export_tokens_csv(int $target_year): string {
        $tokens = $this->db->list_tokens(['target_year' => $target_year]);
        $rows = [];
        foreach ($tokens as $token) {
            $rows[] = [
                'token_url' => SC_Utils::build_frontend_url(SC_Plugin::instance()->security()->frontend_base_path(), 'check.html', ['t' => 'TOKEN_REDACTED']),
                'expires_at' => $token['expires_at'] ?? '',
                'token_prefix' => $token['token_prefix'] ?? '',
                'target_year' => $token['target_year'] ?? '',
            ];
        }
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, ['token_url', 'expires_at', 'token_prefix', 'target_year']);
        foreach ($rows as $row) {
            fputcsv($fp, [$row['token_url'], $row['expires_at'], $row['token_prefix'], $row['target_year']]);
        }
        rewind($fp);
        return stream_get_contents($fp) ?: '';
    }

    private function period_start(array $tokens): ?string {
        $dates = array_filter(array_map(static fn($row) => $row['issued_at'] ?? null, $tokens));
        if (!$dates) {
            return null;
        }
        sort($dates);
        return substr((string) reset($dates), 0, 10);
    }

    private function period_end(array $tokens): ?string {
        $dates = array_filter(array_map(static fn($row) => $row['issued_at'] ?? null, $tokens));
        if (!$dates) {
            return null;
        }
        sort($dates);
        return substr((string) end($dates), 0, 10);
    }

    private function date_only(string $value): string {
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d', $ts) : substr($value, 0, 10);
    }
}
