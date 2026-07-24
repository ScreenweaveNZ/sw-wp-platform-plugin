<?php
/**
 * Plugin Name: ScreenWeave Platform
 * Description: ScreenWeave WordPress platform defaults, health checks, and security hardening.
 * Version: 1.3.0
 * Author: ScreenWeave
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const SCREENWEAVE_PLATFORM_VERSION = '1.3.0';
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

function screenweave_env_string(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    return trim((string) $value);
}

function screenweave_is_non_production_or_holding(): bool
{
    $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';

    return $environment !== 'production' || screenweave_env_bool('WORDPRESS_IS_HOLDING_URL', false);
}

function screenweave_media_provider(): string
{
    $provider = screenweave_env_string('ADVMO_MEDIA_PROVIDER');

    if (in_array($provider, ['cloudflare_r2', 'minio'], true)) {
        return $provider;
    }

    if (screenweave_env_string('ADVMO_CLOUDFLARE_R2_BUCKET') !== '') {
        return 'cloudflare_r2';
    }

    if (screenweave_env_string('ADVMO_MINIO_BUCKET') !== '') {
        return 'minio';
    }

    return '';
}

function screenweave_media_bucket(string $provider): string
{
    if ($provider === 'cloudflare_r2') {
        return screenweave_env_string('ADVMO_CLOUDFLARE_R2_BUCKET');
    }

    if ($provider === 'minio') {
        return screenweave_env_string('ADVMO_MINIO_BUCKET');
    }

    return '';
}

function screenweave_media_endpoint(string $provider): string
{
    if ($provider === 'cloudflare_r2') {
        return screenweave_env_string('ADVMO_CLOUDFLARE_R2_ENDPOINT');
    }

    if ($provider === 'minio') {
        return screenweave_env_string('ADVMO_MINIO_ENDPOINT');
    }

    return '';
}

function screenweave_media_domain(string $provider): string
{
    if ($provider === 'cloudflare_r2') {
        return screenweave_env_string('ADVMO_CLOUDFLARE_R2_DOMAIN');
    }

    if ($provider === 'minio') {
        return screenweave_env_string('ADVMO_MINIO_DOMAIN');
    }

    return '';
}

/**
 * Configure Advanced Media Offloader's provider from environment before normal plugins load.
 */
add_action('muplugins_loaded', static function (): void {
    $provider = screenweave_media_provider();

    if ($provider === '') {
        return;
    }

    $settings = get_option('advmo_settings', []);

    if (!is_array($settings)) {
        $settings = [];
    }

    if (($settings['cloud_provider'] ?? '') !== $provider) {
        $settings['cloud_provider'] = $provider;
        update_option('advmo_settings', $settings, false);
    }
});

/**
 * Disable the built-in code editors. This is safe for all environments.
 */
add_action('init', static function (): void {
    if (!defined('DISALLOW_FILE_EDIT')) {
        define('DISALLOW_FILE_EDIT', true);
    }
});

/**
 * Auto-activate image-managed platform plugins when present.
 */
add_action('init', static function (): void {
    if (!function_exists('activate_plugin')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugins = array_filter(array_map('trim', explode(',', screenweave_env_string(
        'SCREENWEAVE_AUTO_ACTIVATE_PLUGINS',
        'redis-cache/redis-cache.php,fluent-smtp/fluent-smtp.php'
    ))));

    foreach ($plugins as $plugin) {
        if (file_exists(WP_PLUGIN_DIR . '/' . $plugin) && !is_plugin_active($plugin)) {
            activate_plugin($plugin, '', false, true);
        }
    }
}, 20);

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

    if (screenweave_is_non_production_or_holding()) {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
});

add_filter('robots_txt', static function (string $output, bool $public): string {
    if (!screenweave_is_non_production_or_holding()) {
        return $output;
    }

    return "User-agent: *\nDisallow: /\n";
}, 10, 2);

add_filter('pre_option_blog_public', static function ($preOption) {
    return screenweave_is_non_production_or_holding() ? '0' : $preOption;
});

/**
 * Generic env-driven SMTP fallback. FluentSMTP is still installed for UI/logging,
 * but this guarantees mail can work from environment variables alone.
 */
add_action('phpmailer_init', static function ($phpmailer): void {
    $host = screenweave_env_string('WP_SMTP_HOST');

    if ($host === '') {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host = $host;
    $phpmailer->Port = (int) (screenweave_env_string('WP_SMTP_PORT', '587'));
    $phpmailer->SMTPAuth = screenweave_env_bool('WP_SMTP_AUTH', true);
    $phpmailer->Username = screenweave_env_string('WP_SMTP_USER');
    $phpmailer->Password = screenweave_env_string('WP_SMTP_PASSWORD');
    $phpmailer->SMTPSecure = screenweave_env_string('WP_SMTP_SECURE', 'tls');

    $from = screenweave_env_string('WP_SMTP_FROM');
    if ($from !== '') {
        $phpmailer->From = $from;
    }

    $fromName = screenweave_env_string('WP_SMTP_FROM_NAME');
    if ($fromName !== '') {
        $phpmailer->FromName = $fromName;
    }
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

    $mediaProvider = screenweave_media_provider();
    $mediaBucket = screenweave_media_bucket($mediaProvider);
    $mediaEndpoint = screenweave_media_endpoint($mediaProvider);
    $mediaBucketUrl = screenweave_media_domain($mediaProvider);

    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $mediaPluginActive = is_plugin_active('advanced-media-offloader/advanced-media-offloader.php');

    $payload = [
        'status' => $statusCode === 200 ? 'ok' : 'degraded',
        'platform' => SCREENWEAVE_PLATFORM_NAME,
        'platformVersion' => SCREENWEAVE_PLATFORM_VERSION,
        'environment' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown',
        'siteUrl' => home_url(),
        'holdingUrl' => getenv('WORDPRESS_HOLDING_URL') ?: null,
        'isHoldingUrl' => screenweave_env_bool('WORDPRESS_IS_HOLDING_URL', false),
        'db' => $dbStatus,
        'objectCache' => $cacheStatus,
        'objectCacheInUse' => wp_using_ext_object_cache(),
        'redisClient' => defined('WP_REDIS_CLIENT') ? (string) WP_REDIS_CLIENT : null,
        'phpRedisLoaded' => extension_loaded('redis'),
        'noindex' => screenweave_is_non_production_or_holding(),
        'smtpConfigured' => screenweave_env_string('WP_SMTP_HOST') !== '',
        'cronDisabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'fileEditDisabled' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
        'fileModsDisabled' => defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS,
        'mediaStorage' => $mediaBucket !== '' ? 's3-compatible' : 'local',
        'mediaOffloadConfigured' => $mediaBucket !== '',
        'mediaOffloadPlugin' => 'advanced-media-offloader',
        'mediaOffloadPluginActive' => $mediaPluginActive,
        'mediaProvider' => $mediaProvider !== '' ? $mediaProvider : null,
        'mediaBucket' => $mediaBucket !== '' ? $mediaBucket : null,
        'mediaEndpointHost' => $mediaEndpoint !== '' ? wp_parse_url($mediaEndpoint, PHP_URL_HOST) : null,
        'mediaBucketUrlHost' => $mediaBucketUrl !== '' ? wp_parse_url($mediaBucketUrl, PHP_URL_HOST) : null,
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
