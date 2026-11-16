<?php

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/error-monitor.php';

function codi_debug_test_assert($condition, $message) {
	if(!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$sample = <<<'LOG'
[16-Nov-2026 10:00:00 UTC] PHP Warning: Example warning in /tmp/a.php on line 10
[16-Nov-2026 10:01:00 UTC] PHP Fatal error: Uncaught RuntimeException: Broken in /tmp/b.php:20
Stack trace:
#0 {main}
[16-Nov-2026 10:02:00 UTC] PHP Deprecated: Old API in /tmp/c.php on line 30
[16-Nov-2026 10:03:00 UTC] PHP Notice: Example notice in /tmp/d.php on line 40
LOG;

$entries = codi_debug_parse_log_text($sample);
codi_debug_test_assert(count($entries) === 4, 'Parser must preserve one entry per timestamped PHP error.');
codi_debug_test_assert($entries[0]['severity'] === 'warning', 'Warnings must classify as warning.');
codi_debug_test_assert($entries[1]['severity'] === 'critical', 'Fatal/uncaught errors must classify as critical.');
codi_debug_test_assert(strpos($entries[1]['message'], 'Stack trace:') !== false, 'Continuation lines must stay attached to their error.');
codi_debug_test_assert($entries[2]['severity'] === 'deprecated', 'Deprecations must classify separately.');
codi_debug_test_assert($entries[3]['severity'] === 'notice', 'Notices must classify separately.');
codi_debug_test_assert(codi_debug_classify_severity('WordPress database error Unknown column') === 'critical', 'WordPress database errors must be critical.');
codi_debug_test_assert(codi_debug_classify_severity('PHP ERROR: application failure') === 'critical', 'Explicit errors must be critical.');
codi_debug_test_assert(codi_debug_classify_severity('Application failure without a PHP label') === 'critical', 'Unclassified operational errors must default to critical.');
codi_debug_test_assert(codi_debug_classify_severity('PHP USER_WARNING: Example') === 'warning', 'User warnings must classify as warning.');
codi_debug_test_assert(codi_debug_classify_severity('PHP USER_NOTICE: Example') === 'notice', 'User notices must classify as notice.');
codi_debug_test_assert(codi_debug_classify_severity('PHP STRICT: Example') === 'notice', 'Legacy strict diagnostics must classify as notice.');
codi_debug_test_assert(codi_debug_classify_severity('PHP USER_DEPRECATED: Example') === 'deprecated', 'User deprecations must classify as deprecated.');

$noise = codi_debug_parse_log_text("[16-Nov-2026 10:04:00 UTC] Scraping home page...\n[16-Nov-2026 10:04:01 UTC] '###### wp_scraping_result_start:abc ######\n");
codi_debug_test_assert(count($noise) === 0, 'WordPress scraping diagnostics must not appear as operational errors.');

$urgent = codi_debug_filter_min_severity($entries, 'warning');
codi_debug_test_assert(count($urgent) === 2, 'Warning threshold must include warnings and critical errors only.');
codi_debug_test_assert(count(codi_debug_filter_min_severity($entries, 'all')) === 4, 'All-severities threshold must include every parsed error.');

list($message, $details) = codi_debug_error_message_parts("First line\nStack trace:\n#0 example\n#1 example");
codi_debug_test_assert($message === 'First line', 'Email message summary must retain the first line.');
codi_debug_test_assert(strpos($details, 'Stack trace:') === 0, 'Email details must include bounded continuation context.');

$dupe = $entries[0];
$groups = codi_debug_group_entries([ $entries[0], $dupe, $entries[1] ]);
codi_debug_test_assert(count($groups) === 2, 'Equal fingerprints must group together.');
codi_debug_test_assert($groups[0]['count'] === 2, 'Grouped errors must retain occurrence counts.');

echo "PASS\n";