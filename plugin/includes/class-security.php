<?php

class SC_Security_Service {
    private SC_DB $db;
    private string $option_key = 'sc_security_settings';

    public function __construct(SC_DB $db) {
        $this->db = $db;
    }

    public function defaults(): array {
        return [
            'allowed_origins' => [],
            'rate_limits' => [
                'survey' => ['limit' => 30, 'window' => 60],
                'result' => ['limit' => 20, 'window' => 60],
                'submit' => ['limit' => 10, 'window' => 60],
                'miss' => ['limit' => 20, 'window' => 600],
            ],
            'manage_capability' => 'manage_stresscheck',
            'external_api_key_hash' => '',
            'frontend_base_path' => '/st-check-r8',
            'frontend_paused' => false,
            'frontend_pause_message' => '現在受付を中止しています。',
        ];
    }

    public function settings(): array {
        return array_replace_recursive($this->defaults(), (array) SC_Utils::get_option($this->option_key, []));
    }

    public function update_settings(array $settings): bool {
        return SC_Utils::update_option($this->option_key, $this->sanitize_settings($settings));
    }

    public function sanitize_settings(array $settings): array {
        $defaults = $this->defaults();
        $allowed_origins = $settings['allowed_origins'] ?? $defaults['allowed_origins'];
        if (is_string($allowed_origins)) {
            $allowed_origins = preg_split('/[\r\n,]+/', $allowed_origins) ?: [];
        }
        $allowed_origins = array_values(array_unique(array_filter(array_map(function ($origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                return null;
            }
            if ($origin === '*') {
                return null;
            }
            if (!filter_var($origin, FILTER_VALIDATE_URL)) {
                return null;
            }
            $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
            return $origin;
        }, (array) $allowed_origins))));

        $rate_limits = [];
        $incoming_limits = (array) ($settings['rate_limits'] ?? []);
        foreach ($defaults['rate_limits'] as $bucket => $fallback) {
            $raw = (array) ($incoming_limits[$bucket] ?? []);
            $limit = (int) ($raw['limit'] ?? $fallback['limit']);
            $window = (int) ($raw['window'] ?? $fallback['window']);
            $rate_limits[$bucket] = [
                'limit' => max(1, min(1000, $limit)),
                'window' => max(1, min(86400, $window)),
            ];
        }

        $manage_capability = trim((string) ($settings['manage_capability'] ?? $defaults['manage_capability']));
        if ($manage_capability === '') {
            $manage_capability = $defaults['manage_capability'];
        }
        if (!preg_match('/^[A-Za-z0-9_:-]+$/', $manage_capability)) {
            $manage_capability = $defaults['manage_capability'];
        }

        $external_api_key_hash = trim((string) ($settings['external_api_key_hash'] ?? $defaults['external_api_key_hash']));
        if ($external_api_key_hash !== '' && !preg_match('/^[a-f0-9]{64}$/i', $external_api_key_hash)) {
            $external_api_key_hash = $defaults['external_api_key_hash'];
        }

        $frontend_base_path = (string) ($settings['frontend_base_path'] ?? $defaults['frontend_base_path']);
        $frontend_base_path = SC_Utils::frontend_base_path($frontend_base_path, (string) $defaults['frontend_base_path']);

        $frontend_paused = SC_Utils::boolish($settings['frontend_paused'] ?? $defaults['frontend_paused']);

        $frontend_pause_message = trim((string) ($settings['frontend_pause_message'] ?? $defaults['frontend_pause_message']));
        if ($frontend_pause_message === '') {
            $frontend_pause_message = (string) $defaults['frontend_pause_message'];
        }

        return [
            'allowed_origins' => $allowed_origins,
            'rate_limits' => $rate_limits,
            'manage_capability' => $manage_capability,
            'external_api_key_hash' => strtolower($external_api_key_hash),
            'frontend_base_path' => $frontend_base_path,
            'frontend_paused' => $frontend_paused,
            'frontend_pause_message' => $frontend_pause_message,
        ];
    }

    public function verify_admin_nonce(?string $nonce): bool {
        if (!function_exists('wp_verify_nonce')) {
            return true;
        }
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }
        return (bool) wp_verify_nonce($nonce, $this->admin_nonce_action());
    }

    public function admin_nonce_action(): string {
        return 'wp_rest';
    }

    public function allowed_origins(): array {
        $settings = $this->settings();
        $origins = $settings['allowed_origins'] ?? [];
        if (is_string($origins)) {
            $origins = preg_split('/[\r\n,]+/', $origins) ?: [];
        }
        $origins = array_values(array_filter(array_map('trim', $origins)));
        return $origins;
    }

    public function is_allowed_origin(?string $origin): bool {
        if (!$origin) {
            return true;
        }
        $origin = trim($origin);
        foreach ($this->allowed_origins() as $allowed) {
            if ($allowed === '*') {
                return false;
            }
            if (strcasecmp($allowed, $origin) === 0) {
                return true;
            }
        }
        return false;
    }

    public function public_cors_headers(): array {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-WP-Nonce, X-SC-API-Key',
        ];
    }

    public function cors_headers(?string $origin): array {
        if (!$this->is_allowed_origin($origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => $origin,
            'Vary' => 'Origin',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-WP-Nonce, X-SC-API-Key',
        ];
    }

    public function hash_identifier(string $value): string {
        $salt = defined('AUTH_SALT') && AUTH_SALT ? AUTH_SALT : (defined('SECURE_AUTH_SALT') && SECURE_AUTH_SALT ? SECURE_AUTH_SALT : 'stresscheck-salt');
        return hash_hmac('sha256', $value, $salt);
    }

    public function mask_token(string $text): string {
        return preg_replace('/([?&](?:t|token)=)([A-Za-z0-9_-]+)/', '$1***', $text) ?? $text;
    }

    public function mask_log_payload(array $data): array {
        foreach ($data as $key => $value) {
            if (is_string($value) && str_contains($key, 'token')) {
                $data[$key] = '***';
            }
        }
        return $data;
    }

    public function check_rate_limit(string $bucket, string $identifier, int $limit, int $window): bool {
        $key = 'sc_rl_' . $bucket . '_' . $this->hash_identifier($identifier);
        $history = (array) SC_Utils::get_transient($key);
        $now = time();
        $history = array_values(array_filter($history, static fn($stamp) => is_int($stamp) && $stamp > ($now - $window)));
        if (count($history) >= $limit) {
            return false;
        }
        $history[] = $now;
        SC_Utils::set_transient($key, $history, $window);
        return true;
    }

    public function settings_capability(): string {
        $settings = $this->settings();
        return (string) ($settings['manage_capability'] ?? 'manage_stresscheck');
    }

    public function frontend_base_path(): string {
        $settings = $this->settings();
        return (string) ($settings['frontend_base_path'] ?? '/st-check-r8');
    }

    public function frontend_paused(): bool {
        $settings = $this->settings();
        return SC_Utils::boolish($settings['frontend_paused'] ?? false);
    }

    public function frontend_pause_message(): string {
        $settings = $this->settings();
        $message = trim((string) ($settings['frontend_pause_message'] ?? '現在受付を中止しています。'));
        return $message !== '' ? $message : '現在受付を中止しています。';
    }

    public function frontend_check_url(?string $token = null): string {
        return SC_Utils::build_frontend_url($this->frontend_base_path(), 'check.html', $token !== null ? ['t' => $token] : []);
    }

    public function frontend_result_url(?string $token = null): string {
        return SC_Utils::build_frontend_url($this->frontend_base_path(), 'result.html', $token !== null ? ['t' => $token] : []);
    }

    public function verify_external_api_key(?string $api_key): bool {
        if (!is_string($api_key) || trim($api_key) === '') {
            return false;
        }
        $settings = $this->settings();
        $hash = (string) ($settings['external_api_key_hash'] ?? '');
        if ($hash === '') {
            return false;
        }
        return hash_equals(strtolower($hash), SC_Utils::hash_token(trim($api_key)));
    }
}
