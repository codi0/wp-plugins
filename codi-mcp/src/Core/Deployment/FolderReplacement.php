<?php

declare(strict_types=1);

namespace CodiMcp\Core\Deployment;

/** Shared, bounded filesystem mechanics. Packages resolve identities and permissions. */
final class FolderReplacement
{
    private string $workspace;
    private string $wordpressRoot;
    private \Closure $move;
    private \Closure $extract;
    private \Closure $extractFile;
    private bool $requiresZipArchive;
    private bool $requiresFileZipArchive;

    public function __construct(?string $workspace = null, ?callable $move = null, ?string $wordpressRoot = null, ?callable $extract = null, ?callable $extractFile = null)
    {
        $temporary = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $this->workspace = rtrim($workspace ?? $temporary . '/codi-mcp/deployments', '/\\');
        $this->wordpressRoot = rtrim($wordpressRoot ?? (defined('ABSPATH') ? ABSPATH : ''), '/\\');
        $this->move = $move === null ? static fn (string $from, string $to): bool => @rename($from, $to) : \Closure::fromCallable($move);
        $this->requiresZipArchive = $extract === null;
        $this->extract = $extract === null
            ? static fn (string $zipPath, string $wrapper, string $folder, string $destination): true|\WP_Error => (new FolderArchive())->extract($zipPath, $wrapper, $folder, $destination)
            : \Closure::fromCallable($extract);
        $this->requiresFileZipArchive = $extractFile === null;
        $this->extractFile = $extractFile === null
            ? static fn (string $zipPath, string $wrapper, string $file, string $destination): true|\WP_Error => (new FileArchive())->extract($zipPath, $wrapper, $file, $destination)
            : \Closure::fromCallable($extractFile);
    }

    /** The lock is shared by site/network requests and entire/folder deployments. */
    public function locked(string $root, callable $callback): mixed
    {
        if (!$this->directory($this->workspace . '/locks', 0700)) {
            return $this->error('storage', 'Could not create the deployment lock directory.');
        }
        $key = $this->key($root);
        $handle = @fopen($this->workspace . '/locks/' . $key . '.lock', 'c+b');
        if (!is_resource($handle)) {
            return $this->error('storage', 'Could not open the deployment lock.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return $this->error('deployment_busy', 'Another Codi deployment is using this plugin or theme.');
            }
            foreach (glob($this->workspace . '/transactions/' . $key . '/*/journal.json') ?: array() as $journal) {
                $record = json_decode((string) file_get_contents($journal), true);
                if (!is_array($record) || !in_array($record['phase'] ?? '', array('committed', 'rolled_back'), true)) {
                    return $this->recovery(dirname($journal));
                }
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{root:string,folder:string,exists:bool,fingerprint:string}|\WP_Error */
    public function capture(string $root, string $folder): array|\WP_Error
    {
        return $this->locked($root, function () use ($root, $folder) {
            $direct = $this->directFilesystem();
            if ($direct instanceof \WP_Error) {
                return $direct;
            }
            if ($this->requiresZipArchive && !class_exists('ZipArchive')) {
                return $this->error('zip_required', 'Folder replacement requires PHP ZipArchive support.');
            }
            if (!$this->privateWorkspace()) {
                return $this->error('private_staging_required', 'Folder replacement requires a private temporary directory outside the WordPress and document roots.');
            }
            $target = $this->target($root, $folder);
            if ($target instanceof \WP_Error) {
                return $target;
            }
            try {
                $exists = is_dir($target);
                return array(
                    'root' => (string) realpath($root), 'folder' => $folder, 'exists' => $exists,
                    'fingerprint' => $exists ? $this->fingerprint($target) : $this->absentFingerprint(),
                );
            } catch (\Throwable $error) {
                return $this->error('folder_inspection', $error->getMessage());
            }
        });
    }

    /** @return array{root:string,file:string,fingerprint:string}|\WP_Error */
    public function captureFile(string $root, string $file): array|\WP_Error
    {
        return $this->locked($root, function () use ($root, $file) {
            $direct = $this->directFilesystem();
            if ($direct instanceof \WP_Error) {
                return $direct;
            }
            if ($this->requiresFileZipArchive && !class_exists('ZipArchive')) {
                return $this->error('zip_required', 'File replacement requires PHP ZipArchive support.');
            }
            if (!$this->privateWorkspace()) {
                return $this->error('private_staging_required', 'File replacement requires a private temporary directory outside the WordPress and document roots.');
            }
            $target = $this->fileTarget($root, $file);
            if ($target instanceof \WP_Error) {
                return $target;
            }
            try {
                return array(
                    'root' => (string) realpath($root),
                    'file' => $file,
                    'fingerprint' => $this->fileFingerprint($target),
                );
            } catch (\Throwable $error) {
                return $this->error('file_inspection', $error->getMessage());
            }
        });
    }

    /** Called under locked() by the package deployment. */
    public function replace(string $root, array $selection, string $zipPath, string $wrapper, string $uploadId, ?callable $verify = null): true|\WP_Error
    {
        $direct = $this->directFilesystem();
        if ($direct instanceof \WP_Error) {
            return $direct;
        }
        $folder = $selection['folder'] ?? null;
        $existed = $selection['exists'] ?? null;
        if (!ReplacementSelection::validFolder($folder)
            || !is_bool($existed)
            || !is_string($selection['root'] ?? null) || realpath($root) !== $selection['root']
            || !is_string($selection['fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $selection['fingerprint'])
            || (!$existed && !hash_equals($this->absentFingerprint(), $selection['fingerprint']))
            || !preg_match('/^[a-f0-9]{32}$/D', $uploadId)) {
            return $this->error('bad_replacement', 'Stored folder replacement metadata does not match the installed target.');
        }
        $target = $this->target($root, $folder);
        if ($target instanceof \WP_Error) {
            return $target;
        }
        if (!$this->privateWorkspace()) {
            return $this->error('private_staging_required', 'Folder replacement requires a private temporary directory outside the WordPress and document roots.');
        }
        $transaction = $this->workspace . '/transactions/' . $this->key($root) . '/' . $uploadId;
        if (file_exists($transaction) || !$this->directory($transaction, 0700)) {
            return $this->error('deployment_busy', 'This upload already has deployment state; create a fresh upload after checking its result.');
        }
        $backup = $transaction . '/original';
        $staged = $transaction . '/replacement';
        $retain = false;
        $maintenance = null;
        $promotionStarted = false;
        $restoreFingerprint = $selection['fingerprint'];
        $record = array('root' => $selection['root'], 'folder' => $folder, 'exists' => $existed, 'fingerprint' => $selection['fingerprint'], 'phase' => 'staging');

        try {
            $this->writeJournal($transaction, $record);
            $archive = ($this->extract)($zipPath, $wrapper, $folder, $staged);
            if ($archive instanceof \WP_Error) {
                return $archive;
            }
            $stagedStat = stat($staged);
            $targetStat = stat(dirname($target));
            if (!is_array($stagedStat) || !is_array($targetStat) || $stagedStat['dev'] !== $targetStat['dev']) {
                return $this->error('same_filesystem_required', 'Folder staging and the installed target must be on the same filesystem.');
            }
            if ($existed && is_dir($target)) {
                @chmod($staged, fileperms($target) & 0777);
            }
            $newFingerprint = $this->fingerprint($staged);
            // Do not delete recovery state while this deployment owns it.
            $record['replacement_fingerprint'] = $newFingerprint;
            $record['phase'] = 'prepared';
            $this->writeJournal($transaction, $record);

            $currentTarget = $this->target($root, $folder);
            if ($currentTarget instanceof \WP_Error || $currentTarget !== $target
                || is_dir($target) !== $existed
                || ($existed && !hash_equals($selection['fingerprint'], $this->fingerprint($target)))) {
                return $this->error('folder_conflict', 'The selected folder changed after upload creation. Create a fresh upload against its current contents.');
            }
            $maintenance = $this->beginMaintenance();
            if ($existed) {
                // Invalidate original paths, including PHP files removed by replacement.
                $this->invalidate($target);
                if (!(($this->move)($target, $backup))) {
                    throw new \RuntimeException('Could not move the original folder into recovery storage.');
                }
                $record['phase'] = 'backed_up';
                $this->writeJournal($transaction, $record);
                $restoreFingerprint = $this->fingerprint($backup);
                if (!hash_equals($selection['fingerprint'], $restoreFingerprint)) {
                    throw new \RuntimeException('The selected folder changed while replacement began.');
                }
            } elseif (file_exists($target) || is_link($target)) {
                return $this->error('folder_conflict', 'The destination appeared after upload creation. It has not been changed.');
            }
            $promotionStarted = true;
            if (!(($this->move)($staged, $target))) {
                throw new \RuntimeException('Could not move the replacement folder into place.');
            }
            $record['phase'] = 'installed';
            $this->writeJournal($transaction, $record);
            clearstatcache(true);
            if (!hash_equals($newFingerprint, $this->fingerprint($target))) {
                throw new \RuntimeException('Installed folder verification failed.');
            }
            $this->invalidate($target);
            if ($verify !== null) {
                $health = $verify();
                if ($health !== true) {
                    $message = $health instanceof \WP_Error ? $health->get_error_message() : 'WordPress runtime verification did not succeed.';
                    throw new \RuntimeException($message);
                }
            }
            $record['phase'] = 'committed';
            $this->writeJournal($transaction, $record);
            return true;
        } catch (\Throwable $error) {
            clearstatcache(true);
            $added = !$existed && $promotionStarted && !file_exists($staged) && (file_exists($target) || is_link($target));
            if (is_dir($backup) || $added) {
                try {
                    if (is_dir($target)) {
                        $this->invalidate($target);
                    }
                    if (file_exists($target) && !(($this->move)($target, $transaction . '/failed-replacement'))) {
                        throw new \RuntimeException('Could not move the failed replacement aside.');
                    }
                    if ($existed) {
                        if (!(($this->move)($backup, $target))) {
                            throw new \RuntimeException('Could not restore the original folder.');
                        }
                        clearstatcache(true);
                        // Preserve any external edit detected after the original rename.
                        if (!hash_equals($restoreFingerprint, $this->fingerprint($target))) {
                            throw new \RuntimeException('Restored folder verification failed.');
                        }
                        $this->invalidate($target);
                    } else {
                        clearstatcache(true);
                        if (file_exists($target) || is_link($target)) {
                            throw new \RuntimeException('Could not restore the original absence of the folder.');
                        }
                    }
                    $record['phase'] = 'rolled_back';
                    $this->writeJournal($transaction, $record);
                } catch (\Throwable $rollbackError) {
                    $retain = true;
                    error_log('Codi MCP folder recovery required: ' . $transaction . ' - ' . $rollbackError->getMessage());
                    return $this->recovery($transaction);
                }
            }
            return $this->error('folder_replace_failed', $error->getMessage() . ($existed ? ' The original folder has been preserved.' : ' Folder addition was not completed.'));
        } finally {
            if (!$retain) {
                $this->endMaintenance($maintenance);
                $this->removeTree($transaction);
                if (is_dir($transaction)) {
                    error_log('Codi MCP deployment cleanup is pending: ' . $transaction);
                }
            }
        }
    }

    /** Called under locked() by the package deployment. */
    public function replaceFile(string $root, array $selection, string $zipPath, string $wrapper, string $uploadId, ?callable $verify = null): true|\WP_Error
    {
        $direct = $this->directFilesystem();
        if ($direct instanceof \WP_Error) {
            return $direct;
        }
        $file = $selection['file'] ?? null;
        if (!ReplacementSelection::validFile($file)
            || !is_string($selection['root'] ?? null) || realpath($root) !== $selection['root']
            || !is_string($selection['fingerprint'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $selection['fingerprint'])
            || !preg_match('/^[a-f0-9]{32}$/D', $uploadId)) {
            return $this->error('bad_replacement', 'Stored file replacement metadata does not match the installed target.');
        }
        $target = $this->fileTarget($root, $file);
        if ($target instanceof \WP_Error) {
            return $target;
        }
        if (!$this->privateWorkspace()) {
            return $this->error('private_staging_required', 'File replacement requires a private temporary directory outside the WordPress and document roots.');
        }
        $transaction = $this->workspace . '/transactions/' . $this->key($root) . '/' . $uploadId;
        if (file_exists($transaction) || !$this->directory($transaction, 0700)) {
            return $this->error('deployment_busy', 'This upload already has deployment state; create a fresh upload after checking its result.');
        }
        $backup = $transaction . '/original';
        $staged = $transaction . '/replacement';
        $retain = false;
        $maintenance = null;
        $promotionStarted = false;
        $restoreFingerprint = $selection['fingerprint'];
        $record = array('root' => $selection['root'], 'file' => $file, 'fingerprint' => $selection['fingerprint'], 'phase' => 'staging');

        try {
            $this->writeJournal($transaction, $record);
            $archive = ($this->extractFile)($zipPath, $wrapper, $file, $staged);
            if ($archive instanceof \WP_Error) {
                return $archive;
            }
            $stagedStat = stat($staged);
            $targetStat = stat(dirname($target));
            if (!is_array($stagedStat) || !is_array($targetStat) || $stagedStat['dev'] !== $targetStat['dev']) {
                return $this->error('same_filesystem_required', 'File staging and the installed target must be on the same filesystem.');
            }
            @chmod($staged, fileperms($target) & 0777);
            $newFingerprint = $this->fileFingerprint($staged);
            $record['replacement_fingerprint'] = $newFingerprint;
            $record['phase'] = 'prepared';
            $this->writeJournal($transaction, $record);

            $currentTarget = $this->fileTarget($root, $file);
            if ($currentTarget instanceof \WP_Error || $currentTarget !== $target
                || !hash_equals($selection['fingerprint'], $this->fileFingerprint($target))) {
                return $this->error('file_conflict', 'The selected file changed after upload creation. Create a fresh upload against its current contents.');
            }

            $maintenance = $this->beginMaintenance();
            $this->invalidate($target);
            if (!(($this->move)($target, $backup))) {
                throw new \RuntimeException('Could not move the original file into recovery storage.');
            }
            $record['phase'] = 'backed_up';
            $this->writeJournal($transaction, $record);
            $restoreFingerprint = $this->fileFingerprint($backup);
            if (!hash_equals($selection['fingerprint'], $restoreFingerprint)) {
                throw new \RuntimeException('The selected file changed while replacement began.');
            }

            $promotionStarted = true;
            if (!(($this->move)($staged, $target))) {
                throw new \RuntimeException('Could not move the replacement file into place.');
            }
            $record['phase'] = 'installed';
            $this->writeJournal($transaction, $record);
            clearstatcache(true, $target);
            if (!hash_equals($newFingerprint, $this->fileFingerprint($target))) {
                throw new \RuntimeException('Installed file verification failed.');
            }
            $this->invalidate($target);
            if ($verify !== null) {
                $health = $verify();
                if ($health !== true) {
                    $message = $health instanceof \WP_Error ? $health->get_error_message() : 'WordPress runtime verification did not succeed.';
                    throw new \RuntimeException($message);
                }
            }
            $record['phase'] = 'committed';
            $this->writeJournal($transaction, $record);
            return true;
        } catch (\Throwable $error) {
            clearstatcache(true, $target);
            if (is_file($backup) || $promotionStarted) {
                try {
                    if (is_file($target)) {
                        $this->invalidate($target);
                        if (!(($this->move)($target, $transaction . '/failed-replacement'))) {
                            throw new \RuntimeException('Could not move the failed replacement aside.');
                        }
                    }
                    if (!is_file($backup) || !(($this->move)($backup, $target))) {
                        throw new \RuntimeException('Could not restore the original file.');
                    }
                    clearstatcache(true, $target);
                    if (!hash_equals($restoreFingerprint, $this->fileFingerprint($target))) {
                        throw new \RuntimeException('Restored file verification failed.');
                    }
                    $this->invalidate($target);
                    $record['phase'] = 'rolled_back';
                    $this->writeJournal($transaction, $record);
                } catch (\Throwable $rollbackError) {
                    $retain = true;
                    error_log('Codi MCP file recovery required: ' . $transaction . ' - ' . $rollbackError->getMessage());
                    return $this->recovery($transaction);
                }
            }
            return $this->error('file_replace_failed', $error->getMessage() . ' The original file has been preserved.');
        } finally {
            if (!$retain) {
                $this->endMaintenance($maintenance);
                $this->removeTree($transaction);
                if (is_dir($transaction)) {
                    error_log('Codi MCP deployment cleanup is pending: ' . $transaction);
                }
            }
        }
    }

    /** The final folder may be absent; its parent must already exist. */
    private function target(string $root, string $folder): string|\WP_Error
    {
        $root = rtrim($root, '/\\');
        if (!ReplacementSelection::validFolder($folder) || is_link($root) || !is_dir($root) || realpath($root) === false) {
            return $this->error('folder_target', 'Folder deployment requires an existing directory-backed package without symbolic links.');
        }
        $path = (string) realpath($root);
        $parts = explode('/', $folder);
        foreach ($parts as $index => $part) {
            $names = @scandir($path);
            if ($names === false) {
                return $this->error('folder_target', 'Could not inspect the selected folder parent.');
            }
            $candidate = $path . DIRECTORY_SEPARATOR . $part;
            if (!in_array($part, $names, true)) {
                foreach ($names as $name) {
                    if (strcasecmp($part, $name) === 0) {
                        return $this->error('folder_target', 'Selected folder letter case conflicts with an existing path.');
                    }
                }
                if ($index === count($parts) - 1 && !file_exists($candidate) && !is_link($candidate)) {
                    return $candidate;
                }
                return $this->error('folder_target', 'The selected folder parent must already exist with exact letter case.');
            }
            $path = $candidate;
            if (is_link($path) || !is_dir($path)) {
                return $this->error('folder_target', 'Selected paths and their parents must be directories without symbolic links.');
            }
        }
        return $path;
    }

    private function fileTarget(string $root, string $file): string|\WP_Error
    {
        $root = rtrim($root, '/\\');
        if (!ReplacementSelection::validFile($file) || is_link($root) || !is_dir($root) || realpath($root) === false) {
            return $this->error('file_target', 'File replacement requires an existing directory-backed package without symbolic links.');
        }
        $path = (string) realpath($root);
        $parts = explode('/', $file);
        foreach ($parts as $index => $part) {
            $names = @scandir($path);
            if ($names === false || !in_array($part, $names, true)) {
                return $this->error('file_target', 'The selected file must already exist with exact letter case.');
            }
            $candidate = $path . DIRECTORY_SEPARATOR . $part;
            if (is_link($candidate)) {
                return $this->error('file_target', 'Selected paths and their parents must not be symbolic links.');
            }
            if ($index === count($parts) - 1) {
                if (!is_file($candidate)) {
                    return $this->error('file_target', 'The selected file must be an existing regular file.');
                }
                return $candidate;
            }
            if (!is_dir($candidate)) {
                return $this->error('file_target', 'The selected file parent path must be a directory.');
            }
            $path = $candidate;
        }
        return $this->error('file_target', 'The selected file is unavailable.');
    }

    private function fileFingerprint(string $file): string
    {
        $hash = hash_file('sha256', $file);
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \RuntimeException('Could not fingerprint the selected file.');
        }
        return $hash;
    }

    private function absentFingerprint(): string
    {
        return PackageFingerprint::absent();
    }

    private function fingerprint(string $root): string
    {
        return (new PackageFingerprint())->directory($root);
    }

    private function directFilesystem(): true|\WP_Error
    {
        if (function_exists('get_filesystem_method') && get_filesystem_method() !== 'direct') {
            return $this->error('direct_filesystem_required', 'Folder replacement requires direct WordPress filesystem access.');
        }
        return true;
    }

    private function privateWorkspace(): bool
    {
        if (!$this->directory($this->workspace, 0700) || is_link($this->workspace)) {
            return false;
        }
        $workspace = str_replace('\\', '/', (string) realpath($this->workspace));
        foreach (array($this->wordpressRoot, $_SERVER['DOCUMENT_ROOT'] ?? '') as $public) {
            if ($public === '') {
                continue;
            }
            $public = realpath($public);
            if ($public !== false && $public !== '') {
                $public = rtrim(str_replace('\\', '/', $public), '/');
                if (strcasecmp($workspace, $public) === 0 || str_starts_with(strtolower($workspace), strtolower($public) . '/')) {
                    return false;
                }
            }
        }
        return true;
    }

    private function key(string $root): string
    {
        $canonical = realpath($root) ?: ((realpath(dirname($root)) ?: dirname($root)) . '/' . basename($root));
        $canonical = str_replace('\\', '/', $canonical);
        return hash('sha256', PHP_OS_FAMILY === 'Windows' ? strtolower($canonical) : $canonical);
    }

    private function directory(string $path, int $mode): bool
    {
        return !is_link($path) && (is_dir($path) || @mkdir($path, $mode, true));
    }

    private function writeJournal(string $transaction, array $record): void
    {
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($transaction . '/journal.next', $json, LOCK_EX) !== strlen($json)
            || !@rename($transaction . '/journal.next', $transaction . '/journal.json')) {
            throw new \RuntimeException('Could not write deployment recovery metadata.');
        }
    }

    private function beginMaintenance(): array
    {
        if ($this->wordpressRoot === '' || !is_dir($this->wordpressRoot)) {
            throw new \RuntimeException('WordPress root is unavailable for maintenance protection.');
        }
        $path = $this->wordpressRoot . '/.maintenance';
        $content = '<?php $upgrading = ' . time() . '; /* codi ' . bin2hex(random_bytes(16)) . ' */';
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('WordPress is already in maintenance mode, or maintenance protection could not be created.');
        }
        try {
            if (fwrite($handle, $content) !== strlen($content)) {
                @unlink($path);
                throw new \RuntimeException('Could not write maintenance protection.');
            }
        } finally {
            fclose($handle);
        }
        return array('path' => $path, 'content' => $content);
    }

    private function endMaintenance(?array $maintenance): void
    {
        if ($maintenance !== null && is_file($maintenance['path'])
            && file_get_contents($maintenance['path']) === $maintenance['content']) {
            @unlink($maintenance['path']);
        }
    }

    private function invalidate(string $root): void
    {
        if (!function_exists('wp_opcache_invalidate')) {
            return;
        }
        if (is_file($root) && !is_link($root)) {
            wp_opcache_invalidate($root, true);
            return;
        }
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                wp_opcache_invalidate($file->getPathname(), true);
            }
        }
    }

    private function removeTree(string $root): void
    {
        if (is_dir($root) && !is_link($root)) {
            foreach (scandir($root) ?: array() as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->removeTree($root . '/' . $name);
                }
            }
            @rmdir($root);
        } elseif (file_exists($root) || is_link($root)) {
            @unlink($root);
        }
    }

    private function recovery(string $transaction): \WP_Error
    {
        return new \WP_Error(
            'codi_mcp_recovery_required',
            'An interrupted deployment requires operator recovery before further Codi deployments of this target.',
            array('recovery_directory' => $transaction)
        );
    }

    private function error(string $code, string $message): \WP_Error
    {
        return new \WP_Error('codi_mcp_' . $code, $message);
    }
}
