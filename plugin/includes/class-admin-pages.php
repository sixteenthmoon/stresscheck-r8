<?php

class SC_Admin_Pages {
    private SC_DB $db;
    private SC_Token_Service $tokens;
    private SC_Report_Service $reports;
    private SC_Security_Service $security;

    public function __construct(SC_DB $db, SC_Token_Service $tokens, SC_Report_Service $reports, SC_Security_Service $security) {
        $this->db = $db;
        $this->tokens = $tokens;
        $this->reports = $reports;
        $this->security = $security;
    }

    public function register_menu(): void {
        if (!function_exists('add_menu_page')) {
            return;
        }
        add_menu_page('Stress Check', 'Stress Check', $this->security->settings_capability(), 'stresscheck-dashboard', [$this, 'render_dashboard_page']);
        add_submenu_page('stresscheck-dashboard', 'Tokens', 'Tokens', $this->security->settings_capability(), 'stresscheck-tokens', [$this, 'render_tokens_page']);
        add_submenu_page('stresscheck-dashboard', 'Progress', 'Progress', $this->security->settings_capability(), 'stresscheck-progress', [$this, 'render_progress_page']);
        add_submenu_page('stresscheck-dashboard', 'Report', 'Report', $this->security->settings_capability(), 'stresscheck-report', [$this, 'render_report_page']);
        add_submenu_page('stresscheck-dashboard', 'Settings', 'Settings', $this->security->settings_capability(), 'stresscheck-settings', [$this, 'render_settings_page']);
        add_submenu_page('stresscheck-dashboard', 'Security', 'Security', $this->security->settings_capability(), 'stresscheck-security', [$this, 'render_security_page']);
    }

    public function dashboard_data(int $target_year): array {
        $progress = $this->reports->progress_summary($target_year);
        $report = $this->reports->report_summary($target_year);
        return [
            'target_year' => $target_year,
            'progress' => $progress,
            'report' => $report,
        ];
    }

    public function render_dashboard_page(): void {
        $target_year = (int) date('Y');
        $data = $this->dashboard_data($target_year);
        echo '<div class="wrap"><h1>ストレスチェック 管理ダッシュボード</h1>';
        echo '<p>対象年: ' . esc_html((string) $data['target_year']) . '</p>';
        echo '<ul>';
        echo '<li>発行トークン数: ' . esc_html((string) $data['progress']['issued_count']) . '</li>';
        echo '<li>回答済み: ' . esc_html((string) $data['progress']['submitted_count']) . '</li>';
        echo '<li>回答率: ' . esc_html((string) $data['progress']['completion_rate']) . '%</li>';
        echo '<li>高ストレス者数: ' . esc_html((string) $data['report']['high_stress_count']) . '</li>';
        echo '<li>面接指導希望者数: ' . esc_html((string) $data['report']['interview_requested_count']) . '</li>';
        echo '</ul></div>';
    }

    public function render_tokens_page(): void {
        $target_year = (int) date('Y');
        $per_page = 20;
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $total_tokens = $this->db->count_tokens(['target_year' => $target_year]);
        $total_pages = max(1, (int) ceil($total_tokens / $per_page));
        $page = min($page, $total_pages);
        $tokens = $this->db->list_tokens(['target_year' => $target_year, 'page' => $page, 'per_page' => $per_page]);
        $settings = $this->security->settings();
        echo '<div class="wrap"><h1>トークン管理</h1>';
        echo '<h2>新規発行</h2>';
        echo '<form method="post" action="' . esc_attr($this->admin_post_url('sc_issue_tokens')) . '">';
        $this->nonce_field('sc_issue_tokens');
        echo '<input type="hidden" name="target_year" value="' . esc_attr((string) $target_year) . '" />';
        echo '<p><label>発行数 <input type="number" name="count" min="1" max="500" value="1" /></label></p>';
        echo '<p><label>有効期限 <input type="text" name="expires_at" value="' . esc_attr(date('Y-m-d 23:59:59')) . '" /></label></p>';
        echo '<p><label>配布URLベース <input type="text" name="base_url" value="' . esc_attr($this->tokens->token_url_base()) . '" size="60" /></label></p>';
        echo '<p><button class="button button-primary" type="submit">発行</button></p>';
        echo '</form>';

        echo '<h2>発行済み一覧 <span class="count">' . esc_html((string) $total_tokens) . '件</span></h2>';
        $this->render_token_pagination($page, $total_pages, $total_tokens);
        echo '<table class="widefat striped"><thead><tr><th>prefix</th><th>status</th><th>expires_at</th><th>受検URL</th><th>結果URL</th><th>actions</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            $plain_token = (string) ($token['plain_token'] ?? $token['token'] ?? '');
            echo '<tr data-token-id="' . esc_attr((string) ($token['id'] ?? '')) . '">';
            echo '<td>' . esc_html((string) ($token['token_prefix'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($token['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($token['expires_at'] ?? '')) . '</td>';
            echo '<td>' . $this->token_url_icon($plain_token, 'check', '受検URL') . '</td>';
            echo '<td>' . $this->token_url_icon($plain_token, 'result', '結果URL') . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_attr($this->admin_post_url('sc_revoke_token')) . '" style="display:inline-block;margin-right:8px;">';
            $this->nonce_field('sc_revoke_token');
            echo '<input type="hidden" name="token_id" value="' . esc_attr((string) ($token['id'] ?? '')) . '" />';
            echo '<button class="button" type="submit">失効</button></form>';
            echo '<form method="post" action="' . esc_attr($this->admin_post_url('sc_reissue_token')) . '" style="display:inline-block;">';
            $this->nonce_field('sc_reissue_token');
            echo '<input type="hidden" name="token_id" value="' . esc_attr((string) ($token['id'] ?? '')) . '" />';
            echo '<input type="text" name="expires_at" value="' . esc_attr((string) ($token['expires_at'] ?? '')) . '" size="19" /> ';
            echo '<button class="button" type="submit">再発行</button></form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        $this->render_token_pagination($page, $total_pages, $total_tokens);
        if (empty($tokens)) {
            echo '<p>まだトークンがありません。</p>';
        }
        echo '</div>';
    }

    public function render_progress_page(): void {
        $progress = $this->reports->progress_summary((int) date('Y'));
        echo '<div class="wrap"><h1>回答進捗</h1><p>回答率: ' . esc_html((string) $progress['completion_rate']) . '%</p></div>';
    }

    public function render_report_page(): void {
        $target_year = (int) date('Y');
        $report = $this->reports->report_summary($target_year);
        echo '<div class="wrap"><h1>行政報告サマリ</h1>';
        echo '<p>' . esc_html($report['notice']) . '</p>';
        echo '<form method="post" action="' . esc_attr($this->admin_post_url('sc_export_report')) . '">';
        $this->nonce_field('sc_export_report');
        echo '<input type="hidden" name="target_year" value="' . esc_attr((string) $target_year) . '" />';
        echo '<p><button class="button button-primary" type="submit">CSVを出力</button></p>';
        echo '</form></div>';
    }

    public function render_settings_page(): void {
        $settings = $this->security->settings();
        $frontend_base_path = (string) ($settings['frontend_base_path'] ?? '/st-check-r8');
        $frontend_paused = !empty($settings['frontend_paused']);
        $frontend_pause_message = (string) ($settings['frontend_pause_message'] ?? '現在受付を中止しています。');
        $has_collision = $this->frontend_target_has_name_collision($frontend_base_path);
        echo '<div class="wrap"><h1>設定</h1>';
        echo '<p>公開先ベースパス: <code>' . esc_html($frontend_base_path) . '</code></p>';
        echo '<p>公開状態: <strong>' . esc_html($frontend_paused ? '公開停止' : '公開中') . '</strong></p>';
        echo '<form method="post" action="' . esc_attr($this->admin_post_url('sc_save_settings')) . '" onsubmit="if ((this.dataset.frontendCollision === \'1\' || this.frontend_base_path.value !== this.frontend_base_path.defaultValue) && this.sc_confirm_frontend_overwrite.value !== \'1\') { if (!confirm(\'公開フロントの配布先に既存ファイルがある場合、保存すると frontend を保存時に自動同期し、配布先を上書きします。よろしいですか？\')) { return false; } this.sc_confirm_frontend_overwrite.value = \'1\'; } return true;" data-frontend-collision="' . esc_attr($has_collision ? '1' : '0') . '">';
        $this->nonce_field('sc_save_settings');
        echo '<h2>公開フロント設定</h2>';
        echo '<p class="description">保存すると frontend を保存時に自動同期します。配布先に既存ファイルがある場合は、保存時に上書き確認を求めます。</p>';
        echo '<p><label>配布先ベースパス <input type="text" name="frontend_base_path" value="' . esc_attr($frontend_base_path) . '" size="40" /></label></p>';
        echo '<p><label><input type="checkbox" name="frontend_paused" value="1"' . checked($frontend_paused, true, false) . ' /> 公開停止中にする</label></p>';
        echo '<p><label>停止メッセージ<textarea name="frontend_pause_message" rows="3" cols="60">' . esc_textarea($frontend_pause_message) . '</textarea></label></p>';
        echo '<input type="hidden" name="sc_confirm_frontend_overwrite" value="0" />';
        echo '<p><button class="button button-primary" type="submit">保存</button></p>';
        echo '</form>';
        echo '</div>';
    }

    public function render_security_page(): void {
        $settings = $this->security->settings();
        $origins = implode("\n", (array) ($settings['allowed_origins'] ?? []));
        echo '<div class="wrap"><h1>セキュリティ設定</h1>';
        echo '<form method="post" action="' . esc_attr($this->admin_post_url('sc_save_security_settings')) . '">';
        $this->nonce_field('sc_save_security_settings');
        echo '<h2>外部連携と管理権限</h2>';
        echo '<p><label>外部連携元として許可する Origin<br /><textarea name="allowed_origins" rows="4" cols="60">' . esc_textarea($origins) . '</textarea></label></p>';
        echo '<p class="description">外部システムの管理API呼び出し元を行単位で指定します。例: https://partner.example.com。受検者画面の公開CORSとは別の制御です。</p>';
        echo '<p><label>管理画面を操作できる WordPress 権限 <input type="text" name="manage_capability" value="' . esc_attr((string) ($settings['manage_capability'] ?? 'manage_stresscheck')) . '" /></label></p>';
        echo '<p class="description">この権限を持つユーザーだけが Stress Check の管理画面を操作できます。通常は manage_stresscheck のまま使います。</p>';
        echo '<h2>API別アクセス制限</h2>';
        echo '<p class="description">短時間の連続アクセスを抑えるため、API種別ごとに許可回数と計測秒数を設定します。</p>';
        foreach (($settings['rate_limits'] ?? []) as $bucket => $limit) {
            echo '<fieldset><legend>' . esc_html($this->rate_limit_label((string) $bucket)) . '</legend>';
            echo '<label>計測秒数あたりの許可回数 <input type="number" name="rate_limits[' . esc_attr((string) $bucket) . '][limit]" value="' . esc_attr((string) ($limit['limit'] ?? 0)) . '" min="1" max="1000" /></label> ';
            echo '<label>計測秒数 <input type="number" name="rate_limits[' . esc_attr((string) $bucket) . '][window]" value="' . esc_attr((string) ($limit['window'] ?? 0)) . '" min="1" max="86400" /></label>';
            echo '</fieldset>';
        }
        echo '<p><button class="button button-primary" type="submit">保存</button></p>';
        echo '</form></div>';
    }

    public function handle_issue_tokens(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_issue_tokens');
        $count = min(500, max(1, (int) ($_POST['count'] ?? 1)));
        $target_year = (int) ($_POST['target_year'] ?? date('Y'));
        $expires_at = (string) ($_POST['expires_at'] ?? gmdate('Y-m-d H:i:s'));
        $base_url = isset($_POST['base_url']) ? trim((string) $_POST['base_url']) : null;
        $this->tokens->issue_tokens($count, $target_year, $expires_at, $base_url ?: null);
        $this->redirect_back('stresscheck-tokens', ['sc_notice' => 'issued']);
    }

    public function handle_revoke_token(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_revoke_token');
        $token_id = (int) ($_POST['token_id'] ?? 0);
        if ($token_id > 0) {
            $this->tokens->revoke_token($token_id);
        }
        $this->redirect_back('stresscheck-tokens', ['sc_notice' => 'revoked']);
    }

    public function handle_reissue_token(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_reissue_token');
        $token_id = (int) ($_POST['token_id'] ?? 0);
        $expires_at = (string) ($_POST['expires_at'] ?? gmdate('Y-m-d H:i:s'));
        if ($token_id > 0) {
            $this->tokens->reissue_token($token_id, $expires_at, $this->tokens->token_url_base());
        }
        $this->redirect_back('stresscheck-tokens', ['sc_notice' => 'reissued']);
    }

    public function handle_export_report(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_export_report');
        $target_year = (int) ($_POST['target_year'] ?? date('Y'));
        $csv = $this->reports->export_report_csv($target_year);
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (function_exists('header')) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="stresscheck-report-' . $target_year . '.csv"');
        }
        echo $csv;
        exit;
    }

    public function handle_save_settings(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_save_settings');
        $payload = [
            'frontend_base_path' => isset($_POST['frontend_base_path']) ? (string) $_POST['frontend_base_path'] : '',
            'frontend_paused' => isset($_POST['frontend_paused']) ? true : false,
            'frontend_pause_message' => isset($_POST['frontend_pause_message']) ? (string) $_POST['frontend_pause_message'] : '',
        ];
        $next_settings = $this->security->sanitize_settings(array_merge($this->security->settings(), $payload));
        if ($this->frontend_target_has_name_collision((string) $next_settings['frontend_base_path']) && empty($_POST['sc_confirm_frontend_overwrite'])) {
            $this->redirect_back('stresscheck-settings', ['sc_notice' => 'frontend_overwrite_required']);
        }
        $this->security->update_settings($next_settings);
        try {
            $this->sync_frontend_to_site();
        } catch (RuntimeException $e) {
            $this->redirect_back('stresscheck-settings', ['sc_notice' => 'frontend_sync_failed']);
        }
        $this->redirect_back('stresscheck-settings', ['sc_notice' => 'saved']);
    }

    public function handle_save_security_settings(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_save_security_settings');
        $payload = [
            'allowed_origins' => isset($_POST['allowed_origins']) ? (string) $_POST['allowed_origins'] : '',
            'manage_capability' => isset($_POST['manage_capability']) ? (string) $_POST['manage_capability'] : '',
            'rate_limits' => isset($_POST['rate_limits']) ? (array) $_POST['rate_limits'] : [],
        ];
        $this->security->update_settings(array_merge($this->security->settings(), $payload));
        $this->redirect_back('stresscheck-security', ['sc_notice' => 'saved']);
    }

    public function handle_sync_frontend(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_sync_frontend');
        $this->sync_frontend_to_site();
        $this->redirect_back('stresscheck-settings', ['sc_notice' => 'synced']);
    }

    public function handle_toggle_frontend_publish(): void {
        $this->require_manage_capability();
        $this->require_nonce('sc_toggle_frontend_publish');
        $settings = $this->security->settings();
        $settings['frontend_paused'] = empty($settings['frontend_paused']);
        $this->security->update_settings($settings);
        $this->sync_frontend_to_site();
        $this->redirect_back('stresscheck-settings', ['sc_notice' => $settings['frontend_paused'] ? 'paused' : 'resumed']);
    }

    private function sync_frontend_to_site(): void {
        $source_dir = $this->frontend_source_dir();
        $target_dir = SC_Utils::filesystem_root() . ltrim($this->security->frontend_base_path(), '/');
        if ($source_dir === null || !SC_Utils::copy_directory($source_dir, $target_dir)) {
            throw new RuntimeException('公開フロントの同期元を読み込めません。frontend ディレクトリの配置と権限を確認してください。');
        }
        if (!SC_Utils::write_json_file($target_dir . '/config.json', [
            'frontend_base_path' => $this->security->frontend_base_path(),
            'paused' => $this->security->frontend_paused(),
            'pause_message' => $this->security->frontend_pause_message(),
        ])) {
            throw new RuntimeException('公開フロント設定を書き込めません。配布先ディレクトリの権限を確認してください。');
        }
    }

    private function frontend_source_dir(): ?string {
        $candidates = [
            dirname(__DIR__) . '/frontend',
            dirname(__DIR__, 2) . '/frontend',
            dirname(__DIR__, 2) . '/plugins/frontend',
        ];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public function frontend_target_has_name_collision(string $base_path): bool {
        $target_dir = SC_Utils::filesystem_root() . ltrim(SC_Utils::frontend_base_path($base_path), '/');
        if (!file_exists($target_dir)) {
            return false;
        }
        $config_path = rtrim($target_dir, '/\\') . DIRECTORY_SEPARATOR . 'config.json';
        if (!is_file($config_path)) {
            return true;
        }
        $config = json_decode((string) @file_get_contents($config_path), true);
        return !is_array($config) || (($config['frontend_base_path'] ?? null) !== SC_Utils::frontend_base_path($base_path));
    }

    private function rate_limit_label(string $bucket): string {
        return [
            'survey' => '受検画面の取得API',
            'result' => '結果画面の取得API',
            'submit' => '回答送信API',
            'miss' => '無効URL・未存在トークン',
        ][$bucket] ?? $bucket;
    }

    private function token_url_icon(string $plain_token, string $type, string $label): string {
        $icon = $type === 'result' ? '📊' : '🔗';
        if ($plain_token === '') {
            $message = $label . 'はこの一覧から確認できません。再発行すると新しいURLを確認・交付できます';
            return '<span class="button button-small disabled" aria-label="' . esc_attr($message) . '" title="' . esc_attr($message) . '">' . esc_html($icon) . '</span>';
        }
        $url = $type === 'result'
            ? $this->security->frontend_result_url($plain_token)
            : $this->security->frontend_check_url($plain_token);
        return '<a class="button button-small" href="' . esc_attr($url) . '" target="_blank" rel="noopener" aria-label="' . esc_attr($label . 'を開く') . '" title="' . esc_attr($label . 'を開く') . '">' . esc_html($icon) . '</a>';
    }

    private function render_token_pagination(int $page, int $total_pages, int $total_tokens): void {
        if ($total_pages <= 1) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html((string) $total_tokens) . '件</span> ';
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i === $page) {
                echo '<span class="button button-small current" aria-current="page">' . esc_html((string) $i) . '</span> ';
                continue;
            }
            $url = $this->admin_page_url('stresscheck-tokens', ['paged' => $i]);
            echo '<a class="button button-small" href="' . esc_attr($url) . '" aria-label="' . esc_attr((string) $i . 'ページ目') . '">' . esc_html((string) $i) . '</a> ';
        }
        echo '</div></div>';
    }

    private function nonce_field(string $action): void {
        if (!function_exists('wp_nonce_field')) {
            return;
        }
        wp_nonce_field($action, '_wpnonce');
    }

    private function require_nonce(string $action): void {
        if (!function_exists('check_admin_referer')) {
            return;
        }
        check_admin_referer($action);
    }

    private function require_manage_capability(): void {
        if (function_exists('current_user_can') && !current_user_can($this->security->settings_capability())) {
            wp_die('Permission denied');
        }
    }

    private function admin_post_url(string $action): string {
        $base = function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php';
        return $base . '?action=' . rawurlencode($action);
    }

    private function admin_page_url(string $page, array $args = []): string {
        $base = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        $query = array_merge(['page' => $page], $args);
        return $base . '?' . http_build_query($query, '', '&');
    }

    private function redirect_back(string $page, array $args = []): void {
        $url = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        $query = array_merge(['page' => $page], $args);
        $url .= '?' . http_build_query($query, '', '&');
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }
        header('Location: ' . $url);
        exit;
    }
}
