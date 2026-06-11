<?php

class SC_Plugin {
    private static ?SC_Plugin $instance = null;
    private SC_DB $db;
    private SC_Security_Service $security;
    private SC_Token_Service $tokens;
    private SC_Test_Execution_Service $test_executions;
    private SC_Scoring_Service $scoring;
    private SC_Response_Service $responses;
    private SC_Report_Service $reports;
    private SC_Admin_Pages $admin_pages;
    private SC_API_Service $api;

    private function __construct() {
        $this->db = new SC_DB();
        $this->security = new SC_Security_Service($this->db);
        $this->tokens = new SC_Token_Service($this->db, $this->security);
        $this->test_executions = new SC_Test_Execution_Service($this->db, $this->tokens);
        $this->scoring = new SC_Scoring_Service();
        $this->responses = new SC_Response_Service($this->db, $this->tokens, $this->scoring, $this->security);
        $this->reports = new SC_Report_Service($this->db);
        $this->admin_pages = new SC_Admin_Pages($this->db, $this->tokens, $this->reports, $this->security);
        $this->api = new SC_API_Service($this->db, $this->tokens, $this->test_executions, $this->responses, $this->security, $this->reports, $this->admin_pages);
    }

    public static function instance(): SC_Plugin {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        if (function_exists('add_action')) {
            add_action('rest_api_init', [$this->api, 'register_routes']);
            add_action('admin_menu', [$this->admin_pages, 'register_menu']);
            add_action('init', [$this, 'maybe_expire_tokens']);
            add_action('admin_post_sc_issue_tokens', [$this->admin_pages, 'handle_issue_tokens']);
            add_action('admin_post_sc_revoke_token', [$this->admin_pages, 'handle_revoke_token']);
            add_action('admin_post_sc_reissue_token', [$this->admin_pages, 'handle_reissue_token']);
            add_action('admin_post_sc_export_report', [$this->admin_pages, 'handle_export_report']);
            add_action('admin_post_sc_save_settings', [$this->admin_pages, 'handle_save_settings']);
            add_action('admin_post_sc_save_security_settings', [$this->admin_pages, 'handle_save_security_settings']);
            add_action('admin_post_sc_sync_frontend', [$this->admin_pages, 'handle_sync_frontend']);
            add_action('admin_post_sc_toggle_frontend_publish', [$this->admin_pages, 'handle_toggle_frontend_publish']);
        }
    }

    public function maybe_expire_tokens(): void {
        foreach ($this->db->due_tokens_for_expiry() as $token) {
            $this->tokens->expire_token((int) $token['id']);
        }
    }

    public function db(): SC_DB {
        return $this->db;
    }

    public function api(): SC_API_Service {
        return $this->api;
    }

    public function tokens(): SC_Token_Service {
        return $this->tokens;
    }

    public function test_executions(): SC_Test_Execution_Service {
        return $this->test_executions;
    }

    public function responses(): SC_Response_Service {
        return $this->responses;
    }

    public function security(): SC_Security_Service {
        return $this->security;
    }

    public function reports(): SC_Report_Service {
        return $this->reports;
    }

    public function admin_pages(): SC_Admin_Pages {
        return $this->admin_pages;
    }
}
