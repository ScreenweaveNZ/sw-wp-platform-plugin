<?php
/**
 * Plugin Name: ScreenWeave Platform
 * Description: ScreenWeave WordPress platform defaults, health checks, and security hardening.
 * Version: 1.0.0
 * Author: ScreenWeave
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SCREENWEAVE_PLATFORM_VERSION = '1.0.0';
const SCREENWEAVE_PLATFORM_NAME = 'screenweave-wordpress';

/**
 * Convert environment-like values to booleans.
 */
function screenweave_env_bool(string $key, bool $default = false): bool
{
    $value = getenv($key);

    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Disable the built-in code editors. This is safe for all environments.
 */
add_action('init', static function (): void {
    if (!defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
});

/**
 * Optionally disable XML-RPC. Enabled by default for the ScreenWeave platform.
 */
if (screenweave_env_bool('WP_DISABLE_XML_RPC', true)) {
    add_filter('xmlrpc_enabled', '__return_false');
    add_filter('wp_headers', static function (array $headers): array {
        unset($headers['X-Pingback']);
        return $headers;
    });
    add_action('template_redirect', static function (): void {
        if (isset($_SERVER['SCRIPT_NAME']) && basename((string) $_SERVER['SCRIPT_NAME']) === 'xmlrpc.php') {
            status_header(403);
            exit;
        }
    });
}

/**
 * Add conservative security headers. Avoid headers that commonly break wp-admin or embedded content.
 */
add_action('send_headers', static function (): void {
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
});

/**
 * Register platform health endpoint.
 */
add_action('rest_api_init', static function (): void {
    register_rest_route('screenweave/v1', '/health', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'screenweave_health_check',
    ]);
});

/**
 * Health check used by Docker, GitHub Actions, and Coolify smoke tests.
 */
function screenweave_health_check(): WP_REST_Response
{
    global $wpdb;

    $statusCode = 200;
    $dbStatus = 'reachable';
    $cacheStatus = 'not-configured';
    $errors = [];

    try {
        $result = $wpdb->get_var('SELECT 1');
        if ((string) $result !== '1') {
            throw new RuntimeException('Unexpected database response.');
        }
    } catch (Throwable $throwable) {
        $statusCode = 503;
        $dbStatus = 'unreachable';
        $errors['db'] = $throwable->getMessage();
    }

    if (defined('WP_REDIS_HOST') && (string) WP_REDIS_HOST !== '') {
        $cacheStatus = wp_using_ext_object_cache() ? 'enabled' : 'configured-but-not-enabled';
    }

    $payload = [
        'status' => $statusCode === 200 ? 'ok' : 'degraded',
        'platform' => SCREENWEAVE_PLATFORM_NAME,
        'platformVersion' => SCREENWEAVE_PLATFORM_VERSION,
        'wordpressVersion' => get_bloginfo('version'),
        'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
        'siteUrl' => home_url(),
        'holdingUrl' => getenv('WORDPRESS_HOLDING_URL') ?: null,
        'isHoldingUrl' => screenweave_env_bool('WORDPRESS_IS_HOLDING_URL', false),
        'db' => $dbStatus,
        'objectCache' => $cacheStatus,
        'timestamp' => gmdate('c'),
    ];

    if ($errors !== []) {
        $payload['errors'] = $errors;
    }

    return new WP_REST_Response($payload, $statusCode);
}

/**
 * Show platform/environment context to administrators.
 */
add_action('admin_notices', static function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';

    if ($environment === 'production') {
        return;
    }

    $message = sprintf(
        '<strong>ScreenWeave Platform:</strong> %s environment. Platform version %s.',
        esc_html($environment),
        esc_html(SCREENWEAVE_PLATFORM_VERSION)
    );

    if (screenweave_env_bool('WORDPRESS_IS_HOLDING_URL', false)) {
        $message .= sprintf(
            ' Holding URL active: <code>%s</code>.',
            esc_html(getenv('WORDPRESS_HOLDING_URL') ?: home_url())
        );
    }

    printf('<div class="notice notice-info"><p>%s</p></div>', wp_kses_post($message));
});
