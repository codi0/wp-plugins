<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '--file') {
    require_once __DIR__ . '/bootstrap.php';
    require_once __DIR__ . '/framework/TestCase.php';

    $file = (string) ($argv[2] ?? '');
    $before = get_declared_classes();
    require_once $file;
    $after = array_values(array_diff(get_declared_classes(), $before));

    $results = array();
    foreach ($after as $className) {
        if (!is_subclass_of($className, \CodiMcpTest\Framework\TestCase::class)) {
            continue;
        }
        $results[] = (new $className())->run();
    }

    echo json_encode($results, JSON_UNESCAPED_SLASHES);
    $failed = array_sum(array_map(static fn (array $result): int => (int) ($result['failed'] ?? 0), $results));
    exit($failed > 0 ? 1 : 0);
}

$files = glob(__DIR__ . '/unit/*Test.php') ?: array();
sort($files);

$passed = 0;
$failed = 0;
$errors = array();

foreach ($files as $file) {
    $command = array(PHP_BINARY, __FILE__, '--file', $file);
    $pipes = array();
    $process = proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes);

    if (!is_resource($process)) {
        $failed++;
        $errors[] = basename($file) . ' - unable to start isolated test process.';
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $results = json_decode((string) $stdout, true);
    if (!is_array($results)) {
        $failed++;
        $errors[] = basename($file) . ' - isolated test process returned invalid JSON (exit ' . $exitCode . '). ' . trim((string) $stderr);
        continue;
    }

    if (trim((string) $stderr) !== '') {
        fwrite(STDERR, (string) $stderr);
        if (!str_ends_with((string) $stderr, "\n")) {
            fwrite(STDERR, "\n");
        }
    }

    foreach ($results as $result) {
        if (!is_array($result)) {
            continue;
        }
        $classPassed = (int) ($result['passed'] ?? 0);
        $classFailed = (int) ($result['failed'] ?? 0);
        $passed += $classPassed;
        $failed += $classFailed;
        $errors = array_merge($errors, (array) ($result['errors'] ?? array()));
        fwrite(STDERR, sprintf("%s: passed=%d failed=%d\n", (string) ($result['class'] ?? basename($file)), $classPassed, $classFailed));
    }
}

echo sprintf("Passed: %d\nFailed: %d\n", $passed, $failed);
if ($errors !== array()) {
    echo "Errors:\n";
    foreach ($errors as $error) {
        echo '- ' . $error . "\n";
    }
}

exit($failed > 0 ? 1 : 0);
