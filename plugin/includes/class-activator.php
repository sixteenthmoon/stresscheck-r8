<?php

class SC_Activator {
    public static function activate(): void {
        $plugin = SC_Plugin::instance();
        $plugin->db()->install();
        if (function_exists('get_role')) {
            $role = get_role('administrator');
            if ($role && method_exists($role, 'add_cap')) {
                $role->add_cap('manage_stresscheck');
            }
        }
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
    }
}
