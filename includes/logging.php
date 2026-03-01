<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ahx_wp_github_safe_log')) {
    function ahx_wp_github_safe_log($level, $message, $source = 'ahx_wp_github') {
        $lvl = strtoupper((string)$level);
        $msg = (string)$message;
        $src = (string)$source;

        if (class_exists('AHX_Logging') && method_exists('AHX_Logging', 'get_instance')) {
            $logger = AHX_Logging::get_instance();
            $method = 'log_' . strtolower($lvl);
            if (method_exists($logger, $method)) {
                $logger->{$method}($msg, $src);
                return;
            }
            if (method_exists($logger, 'log')) {
                $logger->log($msg, $lvl, $src);
                return;
            }
        }

        error_log('[' . $src . '][' . $lvl . '] ' . $msg);
    }
}

if (!function_exists('ahx_wp_github_log_debug')) {
    function ahx_wp_github_log_debug($message, $source = 'ahx_wp_github') {
        ahx_wp_github_safe_log('DEBUG', $message, $source);
    }
}

if (!function_exists('ahx_wp_github_log_error')) {
    function ahx_wp_github_log_error($message, $source = 'ahx_wp_github') {
        ahx_wp_github_safe_log('ERROR', $message, $source);
    }
}
