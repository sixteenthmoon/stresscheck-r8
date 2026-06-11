<?php

class SC_Utils {
    private static array $options = [];
    private static array $transients = [];

    public static function now_mysql(): string {
        if (function_exists('current_time')) {
            return current_time('mysql');
        }
        return gmdate('Y-m-d H:i:s');
    }

    public static function now_iso(): string {
        if (function_exists('wp_date')) {
            return wp_date(DATE_ATOM);
        }
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    public static function site_url(string $path = ''): string {
        if (function_exists('home_url')) {
            return home_url($path);
        }
        return 'https://example.com/' . ltrim($path, '/');
    }

    public static function frontend_base_path(string $path, string $fallback = '/st-check-r8'): string {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            $path = $fallback;
        }
        $path = preg_replace('#/+#', '/', $path) ?: $fallback;
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = $fallback;
        }
        if (!preg_match('#^/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$#', $path)) {
            return $fallback;
        }
        return $path;
    }

    public static function build_frontend_url(string $base_path, string $file, array $query = []): string {
        $base_path = self::frontend_base_path($base_path);
        $url = self::site_url(rtrim($base_path, '/') . '/' . ltrim($file, '/'));
        if ($query === []) {
            return $url;
        }
        $query_string = http_build_query($query, '', '&');
        return $url . ($query_string !== '' ? '?' . $query_string : '');
    }

    public static function filesystem_root(): string {
        if (defined('ABSPATH') && is_string(ABSPATH) && ABSPATH !== '') {
            return rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR;
        }
        return rtrim(getcwd() ?: '.', '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function ensure_directory(string $path): bool {
        if (is_dir($path)) {
            return is_writable($path);
        }
        return @mkdir($path, 0775, true) || is_dir($path);
    }

    public static function copy_directory(string $source, string $target): bool {
        if (!is_dir($source) || !is_readable($source)) {
            return false;
        }
        if (!self::ensure_directory($target)) {
            return false;
        }
        $entries = @scandir($source);
        if ($entries === false) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source_path = $source . DIRECTORY_SEPARATOR . $entry;
            $target_path = $target . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($source_path)) {
                if (!self::copy_directory($source_path, $target_path)) {
                    return false;
                }
                continue;
            }
            if (!is_readable($source_path) || !self::ensure_directory(dirname($target_path)) || !@copy($source_path, $target_path)) {
                return false;
            }
            @chmod($target_path, 0664);
        }
        return true;
    }

    public static function write_json_file(string $path, array $payload): bool {
        if (!self::ensure_directory(dirname($path))) {
            return false;
        }
        $written = @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
        if ($written === false) {
            return false;
        }
        @chmod($path, 0664);
        return true;
    }

    public static function rest_url(string $path = ''): string {
        if (function_exists('rest_url')) {
            return rest_url($path);
        }
        return rtrim(self::site_url(), '/') . '/wp-json/' . ltrim($path, '/');
    }

    public static function get_option(string $key, mixed $default = null): mixed {
        if (function_exists('get_option')) {
            return get_option($key, $default);
        }
        return self::$options[$key] ?? $default;
    }

    public static function update_option(string $key, mixed $value): bool {
        if (function_exists('update_option')) {
            return (bool) update_option($key, $value);
        }
        self::$options[$key] = $value;
        return true;
    }

    public static function get_transient(string $key): mixed {
        if (function_exists('get_transient')) {
            return get_transient($key);
        }
        if (!array_key_exists($key, self::$transients)) {
            return false;
        }
        $entry = self::$transients[$key];
        if ($entry['expires_at'] !== null && $entry['expires_at'] < time()) {
            unset(self::$transients[$key]);
            return false;
        }
        return $entry['value'];
    }

    public static function set_transient(string $key, mixed $value, int $expiration): bool {
        if (function_exists('set_transient')) {
            return (bool) set_transient($key, $value, $expiration);
        }
        self::$transients[$key] = [
            'value' => $value,
            'expires_at' => $expiration > 0 ? time() + $expiration : null,
        ];
        return true;
    }

    public static function delete_transient(string $key): bool {
        if (function_exists('delete_transient')) {
            return (bool) delete_transient($key);
        }
        unset(self::$transients[$key]);
        return true;
    }

    public static function random_token(int $bytes = 16): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash_token(string $token): string {
        $salt = defined('AUTH_SALT') && AUTH_SALT ? AUTH_SALT : (defined('SECURE_AUTH_SALT') && SECURE_AUTH_SALT ? SECURE_AUTH_SALT : 'stresscheck-salt');
        return hash_hmac('sha256', $token, $salt);
    }

    public static function normalize_answers(array $answers): array {
        $normalized = [];
        foreach ($answers as $question_no => $value) {
            $question_no = (int) $question_no;
            $value = (int) $value;
            $normalized[$question_no] = $value;
        }
        ksort($normalized);
        return $normalized;
    }

    public static function json_encode_safe(mixed $value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function current_user_can_manage(): bool {
        if (function_exists('current_user_can')) {
            return current_user_can('manage_options') || current_user_can('manage_stresscheck');
        }
        return true;
    }

    public static function admin_message(string $message): string {
        return esc_html($message);
    }

    public static function boolish(mixed $value): bool {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
