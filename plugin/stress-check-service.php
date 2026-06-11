<?php
/**
 * Plugin Name: Stress Check Service
 * Description: WordPress-based stress check MVP backend and admin UI.
 * Version: 0.1.0
 * Requires PHP: 8.1
 */

require_once __DIR__ . '/includes/class-utils.php';
require_once __DIR__ . '/includes/class-db.php';
require_once __DIR__ . '/includes/class-security.php';
require_once __DIR__ . '/includes/class-token-service.php';
require_once __DIR__ . '/includes/class-test-execution-service.php';
require_once __DIR__ . '/includes/class-scoring-service.php';
require_once __DIR__ . '/includes/class-response-service.php';
require_once __DIR__ . '/includes/class-report-service.php';
require_once __DIR__ . '/includes/class-admin-pages.php';
require_once __DIR__ . '/includes/class-api.php';
require_once __DIR__ . '/includes/class-activator.php';
require_once __DIR__ . '/includes/class-deactivator.php';
require_once __DIR__ . '/includes/class-plugin.php';

if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, ['SC_Activator', 'activate']);
}
if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, ['SC_Deactivator', 'deactivate']);
}

SC_Plugin::instance()->boot();
