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
        $reflection = new ReflectionClass($className);
        if ($reflection->isAbstract()) {
            continue;
        }
        $results[] = (new $className())->run();
    }

    echo json_encode($results, JSON_UNESCAPED_SLASHES);
    $failed = array_sum(array_map(static fn (array $result): int => (int) ($result['failed'] ?? 0), $results));
    exit($failed > 0 ? 1 : 0);
}

$full = in_array('--full', array_slice($argv, 1), true);
$files = glob(__DIR__ . '/unit/*Test.php') ?: array();
if ($full) {
    $files = array_merge($files, glob(__DIR__ . '/slow/*Test.php') ?: array());
}
sort($files);

$passed = 0;
$failed = 0;
$errors = array();
$workerSetting = (int) (getenv('CODI_MCP_TEST_WORKERS') ?: 8);
$workerCount = max(1, min(count($files), $workerSetting > 0 ? $workerSetting : 8));
$queue = $files;
$active = array();
$completed = array();
$baseEnvironment = getenv();
$baseEnvironment = is_array($baseEnvironment) ? $baseEnvironment : array();

while ($queue !== array() || $active !== array()) {
    while ($queue !== array() && count($active) < $workerCount) {
        $file = array_shift($queue);
        $pipes = array();
        $environment = $baseEnvironment;
        $environment['CODI_MCP_TEST_RUN_ID'] = 'unit-' . substr(hash('sha256', basename($file) . ':' . getmypid()), 0, 16);
        $process = proc_open(
            array(PHP_BINARY, __FILE__, '--file', $file),
            array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ),
            $pipes,
            null,
            $environment
        );
        if (!is_resource($process)) {
            $completed[$file] = array('stdout' => '', 'stderr' => '', 'exit_code' => 1, 'start_failed' => true);
            continue;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $active[$file] = array(
            'process' => $process,
            'stdout_pipe' => $pipes[1],
            'stderr_pipe' => $pipes[2],
            'stdout' => '',
            'stderr' => '',
        );
    }

    foreach (array_keys($active) as $file) {
        $entry = &$active[$file];
        $entry['stdout'] .= (string) stream_get_contents($entry['stdout_pipe']);
        $entry['stderr'] .= (string) stream_get_contents($entry['stderr_pipe']);
        $status = proc_get_status($entry['process']);
        if (!is_array($status) || !empty($status['running'])) {
            unset($entry);
            continue;
        }
        stream_set_blocking($entry['stdout_pipe'], true);
        stream_set_blocking($entry['stderr_pipe'], true);
        $entry['stdout'] .= (string) stream_get_contents($entry['stdout_pipe']);
        $entry['stderr'] .= (string) stream_get_contents($entry['stderr_pipe']);
        fclose($entry['stdout_pipe']);
        fclose($entry['stderr_pipe']);
        $exitCode = isset($status['exitcode']) ? (int) $status['exitcode'] : -1;
        proc_close($entry['process']);
        $completed[$file] = array(
            'stdout' => $entry['stdout'],
            'stderr' => $entry['stderr'],
            'exit_code' => $exitCode,
            'start_failed' => false,
        );
        unset($active[$file], $entry);
    }

    if ($active !== array()) {
        usleep(10000);
    }
}

foreach ($files as $file) {
    $execution = $completed[$file] ?? array('stdout' => '', 'stderr' => '', 'exit_code' => 1, 'start_failed' => true);
    if (!empty($execution['start_failed'])) {
        $failed++;
        $errors[] = basename($file) . ' - unable to start isolated test process.';
        continue;
    }
    $stdout = (string) ($execution['stdout'] ?? '');
    $stderr = (string) ($execution['stderr'] ?? '');
    $exitCode = (int) ($execution['exit_code'] ?? -1);
    $results = json_decode($stdout, true);
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
