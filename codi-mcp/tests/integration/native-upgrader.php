<?php
/**
 * Invariants against real Core classes without a database or a live site.
 * Run only with a disposable extracted WordPress tree:
 * php -d extension=zip tests/integration/native-upgrader.php <tree> --disposable
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[2] ?? '') !== '--disposable') {
    throw new RuntimeException('This test requires an explicitly disposable WordPress tree.');
}
define('ABSPATH', rtrim(str_replace('\\', '/', $argv[1]), '/') . '/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WP_LANG_DIR', WP_CONTENT_DIR . '/languages');
define('WPINC', 'wp-includes');
define('FS_CHMOD_DIR', 0755);
define('FS_CHMOD_FILE', 0644);
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', false);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

require ABSPATH . 'wp-includes/class-wp-error.php';
require dirname(__DIR__) . '/bootstrap.php';
ini_set('display_errors', '1');
ini_set('log_errors', '0');
$_COOKIE = [];

function wp_normalize_path($path) { return str_replace('\\', '/', $path); }
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }
function untrailingslashit($path) { return rtrim($path, '/\\'); }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, is_object($args) ? get_object_vars($args) : (array) $args); }
function wp_doing_cron() { return false; }
function wp_installing($installing = null) { return false; }
function wp_generate_password($length = 12, $special = true, $extra = false) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function wp_is_stream($path) { return str_contains($path, '://'); }
function mbstring_binary_safe_encoding($reset = false) {}
function reset_mbstring_encoding() {}
function wp_raise_memory_limit($context = 'admin') { return ini_get('memory_limit'); }
function wp_is_windows() { return PHP_OS_FAMILY === 'Windows'; }
function wp_basename($path, $suffix = '') { return basename($path, $suffix); }
function validate_file($file, $allowed = []) { return 0; }

function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_cache_get($key, $group = '') { return false; }
function wp_cache_set($key, $data, $group = '', $expire = 0) { return true; }
function wp_clean_plugins_cache($clear = true) {}
function wp_clean_themes_cache($clear = true) {}
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0755, true); }
function wp_is_maintenance_mode() { return is_file(ABSPATH . '.maintenance'); }
function esc_html($text) { return $text; }
function esc_attr($text) { return $text; }
function esc_url($text) { return $text; }
function _x($text, $context, $domain = '') { return $text; }
function _n($single, $plural, $number, $domain = '') { return $number === 1 ? $single : $plural; }
function wp_get_theme($name = '') { return null; }
function get_theme_root($stylesheet = '') { return WP_CONTENT_DIR . '/themes'; }

require ABSPATH . 'wp-admin/includes/file.php';
require ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
class CoreInstallerTestFilesystem extends WP_Filesystem_Direct
{
    // The managed test runtime maps scratch paths; its native readability check sees virtual drive paths.
    public function is_readable($path) { return $this->exists($path); }
}
$GLOBALS['wp_filesystem'] = new CoreInstallerTestFilesystem(null);
$GLOBALS['wp_theme_directories'] = [get_theme_root()];

class CoreInstallerTestSkin extends Automatic_Upgrader_Skin
{
    public array $nativeErrors = [];
    public function error($errors) { $this->nativeErrors[] = $errors; parent::error($errors); }
    public function feedback($feedback, ...$args) {}
    public function header() {}
    public function footer() {}
}
class CoreInstallerTestUpgrader extends Plugin_Upgrader
{
    // ZIP/header validation is independently covered; exercise the actual Core file lifecycle here.
    public function check_package($source) { return $source; }
    public function fs_connect($directories = [], $allow_relaxed_file_ownership = false) { return true; }
}
class CoreInstallerTestThemeUpgrader extends Theme_Upgrader
{
    public function check_package($source) { return $source; }
    public function check_parent_theme_filter($response, $extra, $result) { return $response; }
    public function fs_connect($directories = [], $allow_relaxed_file_ownership = false) { return true; }
}
function invariant(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function package_zip(string $slug): string
{
    $path = ABSPATH . $slug . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($slug . '/new.php', '<?php // updated');
    $zip->close();
    return $path;
}

$slug = 'codi-native-installer-fixture';
$target = WP_PLUGIN_DIR . '/' . $slug;
wp_mkdir_p($target);
file_put_contents($target . '/old.php', 'original');
$zip = package_zip($slug);
$backup = WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $slug;

$installer = new CodiMcp\Core\Deployment\CorePackageInstaller(static function () use ($backup) {
    invariant(file_get_contents($backup . '/old.php') === 'original', 'Core must retain the original during the probe.');
    return new WP_Error('fixture_fatal', 'Simulated fresh-request fatal');
});
$upgrader = new CoreInstallerTestUpgrader(new CoreInstallerTestSkin());
$result = $installer->install($upgrader, $zip, 'plugin', $slug, WP_PLUGIN_DIR, true);
if (is_wp_error($result) && $result->get_error_code() !== 'codi_mcp_update_rolled_back') { echo json_encode($upgrader->skin->nativeErrors) . PHP_EOL; }
invariant(is_wp_error($result) && $result->get_error_code() === 'codi_mcp_update_rolled_back', 'Failed verification must report a rollback: ' . (is_wp_error($result) ? $result->get_error_message() : var_export($result, true)));
invariant(file_get_contents($target . '/old.php') === 'original' && !file_exists($target . '/new.php'), 'Core must restore the exact previous files.');
invariant(!file_exists(ABSPATH . '.maintenance') && !is_dir($backup), 'Completed rollback must release maintenance and consume its backup.');

$installer = new CodiMcp\Core\Deployment\CorePackageInstaller(static fn () => true);
$result = $installer->install(new CoreInstallerTestUpgrader(new CoreInstallerTestSkin()), $zip, 'plugin', $slug, WP_PLUGIN_DIR, true);
invariant($result === true, 'Verified replacement must succeed.');
invariant(file_exists($target . '/new.php') && !file_exists($target . '/old.php'), 'Core must remove obsolete files on successful replacement.');
invariant(!file_exists(ABSPATH . '.maintenance') && !is_dir($backup), 'Successful commit must release Core recovery state.');

$themeSlug = 'codi-native-installer-theme-fixture';
$themeRoot = get_theme_root();
$themeTarget = $themeRoot . '/' . $themeSlug;
wp_mkdir_p($themeTarget);
file_put_contents($themeTarget . '/style.css', 'original theme');
$themeZip = package_zip($themeSlug);
$themeInstaller = new CodiMcp\Core\Deployment\CorePackageInstaller(static fn () => new WP_Error('fixture_fatal', 'Theme probe failed'));
$result = $themeInstaller->install(new CoreInstallerTestThemeUpgrader(new CoreInstallerTestSkin()), $themeZip, 'theme', $themeSlug, $themeRoot, true);
invariant(is_wp_error($result) && $result->get_error_code() === 'codi_mcp_update_rolled_back', 'Core must roll back a failed theme verification.');
invariant(file_get_contents($themeTarget . '/style.css') === 'original theme', 'Core must restore the previous theme.');
$themeInstaller = new CodiMcp\Core\Deployment\CorePackageInstaller(static fn () => true);
$result = $themeInstaller->install(new CoreInstallerTestThemeUpgrader(new CoreInstallerTestSkin()), $themeZip, 'theme', $themeSlug, $themeRoot, true);
invariant($result === true && file_exists($themeTarget . '/new.php'), 'Core must install the verified theme.');
invariant(!is_dir(WP_CONTENT_DIR . '/upgrade-temp-backup/themes/' . $themeSlug), 'Core must clean the theme backup.');

// Run the real protected Core probe with controlled HTTP transport responses.
$probe = new CodiMcp\Core\Deployment\CoreFatalErrorCheck();
file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = 12345; ?>');
$key = md5('12345');
$url = add_query_arg(['wp_scrape_key' => $key, 'wp_scrape_nonce' => '12345'], home_url('/'));
foreach ([true, ['type' => E_PARSE, 'message' => 'parse error'], 'missing'] as $payload) {
    $GLOBALS['codi_mcp_test_wp_remote_get_responses'][$url] = static function ($url, $args) use ($payload, $key) {
        $body = $payload === 'missing' ? '<html>blocked</html>' : "###### wp_scraping_result_start:$key ######" . json_encode($payload) . "###### wp_scraping_result_end:$key ######";
        return apply_filters('http_response', ['response' => ['code' => 200], 'body' => $body], $args, $url);
    };
    $result = $probe->verify();
    invariant(($result === true) === ($payload === true), 'Real Core probe must distinguish success, fatal errors, and missing proof.');
}
unlink(ABSPATH . '.maintenance');
require ABSPATH . 'wp-includes/version.php';
echo json_encode(['wordpress' => $wp_version, 'native_file_installation' => true, 'native_backup_restore' => true, 'native_theme_installation' => true, 'native_probe' => true]) . PHP_EOL;
