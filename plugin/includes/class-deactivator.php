<?php

class SC_Deactivator {
    public static function deactivate(): void {
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
    }
}
