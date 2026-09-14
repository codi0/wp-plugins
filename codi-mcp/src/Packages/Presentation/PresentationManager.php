<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

final class PresentationManager
{
    public function __construct(
        private ?PresentationRepository $repository = null,
        private ?BlockEditor $blocks = null,
        private ?OperationEngine $engine = null,
        private ?PreviewStore $previews = null,
        private ?HistoryStore $history = null
    ) {
        $this->repository ??= new PresentationRepository();
        $this->blocks ??= new BlockEditor();
        $this->engine ??= new OperationEngine($this->repository, $this->blocks);
        $this->previews ??= new PreviewStore();
        $this->history ??= new HistoryStore();
    }

    /** @return array<string,mixed> */
    public function help(): array
    {
        return array(
            'package' => 'Presentation',
            'workflow' => array('presentation-find', 'presentation-inspect', 'presentation-preview', 'presentation-verify', 'presentation-commit', 'presentation-history', 'presentation-rollback'),
            'owned_state' => array(
                'Gutenberg block composition for editor-supported posts/pages/CPTs',
                'WordPress-managed templates and template parts',
                'WordPress-managed patterns and block navigation',
                'Native wp_global_styles user-origin design state',
                'Native content template assignment',
            ),
            'external_owners' => array(
                'Content: content entity identity/lifecycle, publication, featured media, taxonomy, author and registered metadata',
                'Media: attachment lifecycle, binary data and media-library metadata',
                'Themes/Plugins: source files and package deployment',
                'Site: typed site settings',
            ),
            'classic_theme_support' => 'Classic widgets, classic menus/menu locations and generic Customizer/theme_mod mutation are intentionally not implemented in this initial Presentation package.',
            'block_safety' => array(
                'No generic Gutenberg attribute/property setter is exposed.',
                'A PHP render callback does not prove editor-safe mutation because hybrid blocks can still have a JavaScript save() contract.',
                'Static/hybrid block internals are mutable only through explicit block-specific codecs.',
                'Generic structural edits are limited to root insertion/removal and same-parent reordering unless a container codec exists.',
                'PHP serialize/reparse and server rendering are safety checks, not substitutes for Gutenberg client save() validation.',
            ),
            'operations' => OperationCatalog::publicList(),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function find(array $input): array
    {
        return $this->repository->find($input);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function inspect(array $input): array
    {
        $snapshot = $this->repository->snapshotByRef((string) ($input['ref'] ?? ''));
        if (!$this->repository->canInspectSnapshot($snapshot)) {
            throw new \RuntimeException('You do not have permission to inspect this Presentation target.');
        }
        $blocks = 'global_styles' === (string) ($snapshot['kind'] ?? '')
            ? array()
            : $this->blocks->summarize((string) ($snapshot['content'] ?? ''));
        return array(
            'surface' => $this->repository->surfaceFromSnapshot($snapshot, false),
            'content' => (string) ($snapshot['content'] ?? ''),
            'template' => (string) ($snapshot['template'] ?? ''),
            'global_styles' => is_array($snapshot['global_styles'] ?? null) ? $snapshot['global_styles'] : array(),
            'sync_status' => (string) ($snapshot['sync_status'] ?? ''),
            'blocks' => $blocks,
            'dependencies' => $this->dependenciesForBlocks($blocks),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(array $input): array
    {
        $ref = trim((string) ($input['ref'] ?? ''));
        $operations = array_values(array_filter((array) ($input['operations'] ?? array()), 'is_array'));
        $this->assertOperationSourcesReadable($operations);
        $before = '' !== $ref ? $this->repository->snapshotByRef($ref) : null;
        if (is_array($before) && !$this->repository->canEditSnapshot($before)) {
            throw new \RuntimeException('You do not have permission to change this Presentation target.');
        }
        if (is_array($before) && $this->containsAction($operations, 'object.delete') && !$this->repository->canDeleteSnapshot($before)) {
            throw new \RuntimeException('You do not have permission to delete this Presentation target.');
        }
        if (!is_array($before)) {
            $kind = strtolower(trim((string) (($operations[0]['kind'] ?? ''))));
            if (!$this->repository->canCreateKind($kind)) {
                throw new \RuntimeException('You do not have permission to create this Presentation object.');
            }
        }

        $after = $this->engine->apply($before, $operations);
        if ((!empty($after['_create']) || !empty($after['_duplicate'])) && !$this->repository->canCreateKind((string) ($after['kind'] ?? ''))) {
            throw new \RuntimeException('You do not have permission to create this Presentation object.');
        }
        $preview = $this->previews->create(array(
            'ref' => is_array($before) ? (string) ($before['ref'] ?? '') : '',
            'target_kind' => (string) ($after['kind'] ?? ''),
            'before' => $before,
            'after' => $after,
            'operations' => $operations,
            'before_checksum' => is_array($before) ? (string) ($before['checksum'] ?? '') : '',
            'after_checksum' => (string) ($after['checksum'] ?? ''),
            'changes' => $this->changes($before, $after),
        ));
        return $this->presentPreview($preview);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function verify(array $input): array
    {
        $previewId = trim((string) ($input['preview_id'] ?? ''));
        $ref = trim((string) ($input['ref'] ?? ''));
        if (('' === $previewId) === ('' === $ref)) {
            throw new \InvalidArgumentException('presentation-verify requires exactly one of preview_id or ref.');
        }
        $checks = array();
        $subject = 'current';
        $state = null;
        if ('' !== $previewId) {
            $subject = 'preview';
            $preview = $this->previews->get($previewId);
            $before = is_array($preview['before'] ?? null) ? $preview['before'] : null;
            $state = is_array($preview['after'] ?? null) ? $preview['after'] : array();
            if (is_array($before)) {
                try {
                    $current = $this->repository->snapshotByRef((string) $before['ref']);
                    $fresh = hash_equals((string) ($preview['before_checksum'] ?? ''), (string) ($current['checksum'] ?? ''));
                    $checks[] = $this->check('preview_freshness', $fresh, $fresh ? 'The target still matches the reviewed preview base state.' : 'The target changed after the preview was created.');
                } catch (\Throwable $throwable) {
                    $checks[] = $this->check('preview_freshness', false, $throwable->getMessage());
                }
            } else {
                $checks[] = $this->check('preview_freshness', true, 'This is a creation preview and has no existing target precondition.');
            }
        } else {
            $state = $this->repository->snapshotByRef($ref);
        }
        if (!is_array($state)) {
            throw new \RuntimeException('Presentation verification state is unavailable.');
        }
        $checks = array_merge($checks, $this->stateChecks($state));

        if (!empty($input['frontend']) && 'current' === $subject) {
            $checks[] = $this->frontendCheck((string) ($state['route_url'] ?? ''));
        } elseif (!empty($input['frontend']) && 'preview' === $subject) {
            $checks[] = $this->previewRenderCheck($state);
        }

        $valid = !in_array(false, array_map(static fn (array $check): bool => (bool) ($check['ok'] ?? false), $checks), true);
        return array(
            'subject' => $subject,
            'ref' => (string) ($state['ref'] ?? $ref),
            'preview_id' => $previewId,
            'valid' => $valid,
            'checksum' => (string) ($state['checksum'] ?? $this->repository->checksumForState($state)),
            'checks' => $checks,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function commit(array $input): array
    {
        $previewId = trim((string) ($input['preview_id'] ?? ''));
        $preview = $this->previews->get($previewId);
        $before = is_array($preview['before'] ?? null) ? $preview['before'] : null;
        $after = is_array($preview['after'] ?? null) ? $preview['after'] : array();
        $operations = array_values(array_filter((array) ($preview['operations'] ?? array()), 'is_array'));

        if (is_array($before)) {
            $current = $this->repository->snapshotByRef((string) $before['ref']);
            if (!hash_equals((string) ($preview['before_checksum'] ?? ''), (string) ($current['checksum'] ?? ''))) {
                throw new \RuntimeException('Presentation commit refused: the target changed after preview. Create a new preview.');
            }
            if (!$this->repository->canEditSnapshot($current)) {
                throw new \RuntimeException('You do not have permission to commit this Presentation preview.');
            }
            if (!empty($after['_delete']) && !$this->repository->canDeleteSnapshot($current)) {
                throw new \RuntimeException('You do not have permission to delete this Presentation target.');
            }
        } elseif (!$this->repository->canCreateKind((string) ($after['kind'] ?? ''))) {
            throw new \RuntimeException('You do not have permission to create this Presentation object.');
        }

        $committed = null;
        $persistAttempted = false;
        try {
            $persistAttempted = true;
            $committed = $this->repository->persist($before, $after);
            if (!$this->committedStateMatches($after, $committed)) {
                throw new \RuntimeException('WordPress did not persist the exact Presentation state that was previewed.');
            }
            $kind = $this->transactionKind($after);
            $entry = $this->history->record(array(
                'kind' => $kind,
                'ref' => is_array($before) ? (string) ($before['ref'] ?? '') : '',
                'result_ref' => is_array($committed) ? (string) ($committed['ref'] ?? '') : '',
                'preview_id' => $previewId,
                'before_checksum' => is_array($before) ? (string) ($before['checksum'] ?? '') : '',
                'after_checksum' => is_array($committed) ? (string) ($committed['checksum'] ?? '') : '',
                'before' => $before,
                'after' => $committed,
                'operations' => $operations,
                'changes' => (array) ($preview['changes'] ?? array()),
            ));
        } catch (\Throwable $throwable) {
            if ($persistAttempted && !isset($entry)) {
                $compensated = $this->compensateCommit($before, $committed, $after);
                $message = $compensated
                    ? 'Presentation commit failed after native persistence was attempted; WordPress state was compensated. '
                    : 'Presentation commit failed after native persistence was attempted and compensation could not be confirmed; re-inspect the target before retrying. ';
                throw new \RuntimeException($message . $throwable->getMessage(), 0, $throwable);
            }
            throw $throwable;
        }

        $this->previews->delete($previewId);
        return array(
            'transaction_id' => (string) ($entry['transaction_id'] ?? ''),
            'preview_id' => $previewId,
            'ref' => is_array($committed) ? (string) ($committed['ref'] ?? '') : (is_array($before) ? (string) ($before['ref'] ?? '') : ''),
            'status' => 'committed',
            'before_checksum' => is_array($before) ? (string) ($before['checksum'] ?? '') : '',
            'after_checksum' => is_array($committed) ? (string) ($committed['checksum'] ?? '') : '',
            'committed_at' => (string) ($entry['committed_at'] ?? gmdate('c')),
            'rollback_supported' => true,
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function history(array $input): array
    {
        $ref = trim((string) ($input['ref'] ?? ''));
        $limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
        $items = array();
        foreach ($this->history->list($ref, $limit) as $entry) {
            $actions = array();
            foreach ((array) ($entry['operations'] ?? array()) as $operation) {
                if (is_array($operation) && '' !== trim((string) ($operation['action'] ?? ''))) {
                    $actions[] = (string) $operation['action'];
                }
            }
            $items[] = array(
                'transaction_id' => (string) ($entry['transaction_id'] ?? ''),
                'kind' => (string) ($entry['kind'] ?? ''),
                'ref' => (string) (($entry['result_ref'] ?? '') ?: ($entry['ref'] ?? '')),
                'committed_at' => (string) ($entry['committed_at'] ?? ''),
                'actions' => array_values(array_unique($actions)),
                'changes' => array_values(array_map('strval', (array) ($entry['changes'] ?? array()))),
                'rolled_back_at' => (string) ($entry['rolled_back_at'] ?? ''),
                'rolled_back_by' => (string) ($entry['rolled_back_by'] ?? ''),
            );
        }
        return array('items' => $items, 'returned' => count($items));
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function rollback(array $input): array
    {
        $transactionId = trim((string) ($input['transaction_id'] ?? ''));
        $mode = strtolower(trim((string) ($input['mode'] ?? 'preview')));
        if (!in_array($mode, array('preview', 'apply'), true)) {
            throw new \InvalidArgumentException('Rollback mode must be preview or apply.');
        }
        $entry = $this->history->get($transactionId);
        if ('' !== trim((string) ($entry['rolled_back_at'] ?? ''))) {
            throw new \InvalidArgumentException('This Presentation transaction has already been rolled back.');
        }
        if ('rollback' === (string) ($entry['kind'] ?? '')) {
            throw new \InvalidArgumentException('Rollback transactions cannot themselves be rolled back through this compact workflow.');
        }
        $before = is_array($entry['before'] ?? null) ? $entry['before'] : null;
        $after = is_array($entry['after'] ?? null) ? $entry['after'] : null;
        $current = null;
        if (is_array($after)) {
            $current = $this->repository->snapshotByRef((string) $after['ref']);
            if (!hash_equals((string) ($entry['after_checksum'] ?? ''), (string) ($current['checksum'] ?? ''))) {
                throw new \RuntimeException('Rollback refused because the Presentation target changed after the selected transaction.');
            }
            if (!$this->repository->canEditSnapshot($current)) {
                throw new \RuntimeException('You do not have permission to roll back this Presentation target.');
            }
        } elseif (is_array($before)) {
            if (!$this->repository->canCreateKind((string) ($before['kind'] ?? ''))) {
                throw new \RuntimeException('You do not have permission to restore this deleted Presentation object.');
            }
            $this->repository->assertDeletedSnapshotIdentityAvailable($before);
        }

        $impact = $this->rollbackImpact((string) ($entry['kind'] ?? ''), $before, $after);
        if ('preview' === $mode) {
            return array('mode' => 'preview', 'transaction_id' => $transactionId, 'can_apply' => true, 'impact' => $impact, 'result_ref' => is_array($before) ? (string) ($before['ref'] ?? '') : '');
        }

        $restored = null;
        $rollbackEntry = null;
        $nativeRollbackAttempted = false;
        $kind = (string) ($entry['kind'] ?? '');
        try {
            $nativeRollbackAttempted = true;
            if (in_array($kind, array('create', 'duplicate'), true)) {
                if (!is_array($current) || !$this->repository->canDeleteSnapshot($current)) {
                    throw new \RuntimeException('You do not have permission to remove the created Presentation object.');
                }
                $this->repository->deleteSnapshot($current);
            } elseif ('delete' === $kind) {
                if (!is_array($before)) {
                    throw new \RuntimeException('The deleted Presentation snapshot is unavailable.');
                }
                $restored = $this->repository->restoreSnapshot($before, true);
            } else {
                if (!is_array($before)) {
                    throw new \RuntimeException('The Presentation rollback snapshot is unavailable.');
                }
                $restored = $this->repository->restoreSnapshot($before);
            }

            $rollbackEntry = $this->history->record(array(
                'kind' => 'rollback',
                'ref' => is_array($after) ? (string) ($after['ref'] ?? '') : '',
                'result_ref' => is_array($restored) ? (string) ($restored['ref'] ?? '') : '',
                'before_checksum' => is_array($current) ? (string) ($current['checksum'] ?? '') : '',
                'after_checksum' => is_array($restored) ? (string) ($restored['checksum'] ?? '') : '',
                'before' => $current,
                'after' => $restored,
                'operations' => array(),
                'changes' => array('rollback of ' . $transactionId),
                'reverts_transaction_id' => $transactionId,
            ));
            $this->history->markRolledBack($transactionId, (string) $rollbackEntry['transaction_id']);
        } catch (\Throwable $throwable) {
            if (is_array($rollbackEntry) && '' !== trim((string) ($rollbackEntry['transaction_id'] ?? ''))) {
                try {
                    $this->history->discard((string) $rollbackEntry['transaction_id']);
                } catch (\Throwable) {
                    // Native-state compensation remains primary; history inspection will expose an orphan if cleanup failed.
                }
            }
            if ($nativeRollbackAttempted) {
                $compensated = $this->compensateRollback($kind, $current, $restored, $before);
                $message = $compensated
                    ? 'Presentation rollback failed after native persistence was attempted; WordPress state was compensated. '
                    : 'Presentation rollback failed after native persistence was attempted and compensation could not be confirmed; re-inspect the target before retrying. ';
                throw new \RuntimeException($message . $throwable->getMessage(), 0, $throwable);
            }
            throw $throwable;
        }

        return array(
            'mode' => 'apply',
            'transaction_id' => $transactionId,
            'rollback_transaction_id' => (string) $rollbackEntry['transaction_id'],
            'status' => 'rolled_back',
            'impact' => $impact,
            'result_ref' => is_array($restored) ? (string) ($restored['ref'] ?? '') : '',
        );
    }

    public function canInspect(): bool
    {
        return $this->repository->canInspect();
    }

    /** @param array<string,mixed> $input */
    public function canPreview(array $input): bool
    {
        try {
            $ref = trim((string) ($input['ref'] ?? ''));
            $operations = array_values(array_filter((array) ($input['operations'] ?? array()), 'is_array'));
            if (!$this->operationSourcesReadable($operations)) {
                return false;
            }
            if ('' !== $ref) {
                $snapshot = $this->repository->snapshotByRef($ref);
                return $this->repository->canEditSnapshot($snapshot);
            }
            if ($operations === array() || strtolower(trim((string) ($operations[0]['action'] ?? ''))) !== 'object.create') {
                return false;
            }
            return $this->repository->canCreateKind((string) ($operations[0]['kind'] ?? ''));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $input */
    public function canCommit(array $input): bool
    {
        try {
            $preview = $this->previews->get((string) ($input['preview_id'] ?? ''));
            $before = is_array($preview['before'] ?? null) ? $preview['before'] : null;
            $after = is_array($preview['after'] ?? null) ? $preview['after'] : array();
            if (is_array($before)) {
                if (!empty($after['_delete'])) {
                    return $this->repository->canDeleteSnapshot($before);
                }
                return $this->repository->canEditSnapshot($before);
            }
            return $this->repository->canCreateKind((string) ($after['kind'] ?? ''));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $input */
    public function canVerify(array $input): bool
    {
        try {
            $previewId = trim((string) ($input['preview_id'] ?? ''));
            if ('' !== $previewId) {
                return $this->canCommit(array('preview_id' => $previewId));
            }
            $ref = trim((string) ($input['ref'] ?? ''));
            if ('' === $ref) {
                return false;
            }
            $snapshot = $this->repository->snapshotByRef($ref);
            return $this->repository->canInspectSnapshot($snapshot);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $input */
    public function canRollback(array $input): bool
    {
        try {
            $entry = $this->history->get((string) ($input['transaction_id'] ?? ''));
            $state = is_array($entry['after'] ?? null) ? $entry['after'] : (is_array($entry['before'] ?? null) ? $entry['before'] : null);
            return is_array($state) && ($this->repository->canEditSnapshot($state) || $this->repository->canCreateKind((string) ($state['kind'] ?? '')));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int,array<string,mixed>> $operations */
    private function assertOperationSourcesReadable(array $operations): void
    {
        if (!$this->operationSourcesReadable($operations)) {
            throw new \RuntimeException('You do not have permission to inspect one or more Presentation source refs used by this preview.');
        }
    }

    /** @param array<int,array<string,mixed>> $operations */
    private function operationSourcesReadable(array $operations): bool
    {
        try {
            foreach ($operations as $operation) {
                if (!is_array($operation) || !array_key_exists('source_ref', $operation)) {
                    continue;
                }
                $sourceRef = trim((string) $operation['source_ref']);
                if ('' === $sourceRef) {
                    return false;
                }
                $source = $this->repository->snapshotByRef($sourceRef);
                if (!$this->repository->canInspectSnapshot($source)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function presentPreview(array $preview): array
    {
        $after = is_array($preview['after'] ?? null) ? $preview['after'] : array();
        return array(
            'preview_id' => (string) ($preview['preview_id'] ?? ''),
            'ref' => (string) ($preview['ref'] ?? ''),
            'target_kind' => (string) ($preview['target_kind'] ?? ''),
            'operation_count' => count((array) ($preview['operations'] ?? array())),
            'before_checksum' => (string) ($preview['before_checksum'] ?? ''),
            'after_checksum' => (string) ($preview['after_checksum'] ?? ''),
            'changes' => array_values(array_map('strval', (array) ($preview['changes'] ?? array()))),
            'proposed_state' => $this->repository->surfaceFromSnapshot($after, true),
            'expires_at' => (string) ($preview['expires_at'] ?? ''),
        );
    }

    /** @param array<string,mixed> $state @return array<int,array<string,mixed>> */
    private function stateChecks(array $state): array
    {
        $checks = array();
        if (!empty($state['_delete'])) {
            return array($this->check('delete_target', true, 'The preview proposes native object deletion.'));
        }
        if ('global_styles' === (string) ($state['kind'] ?? '')) {
            $styles = $state['global_styles'] ?? null;
            $checks[] = $this->check('global_styles_json', is_array($styles), is_array($styles) ? 'Native Global Styles state is structurally valid JSON data.' : 'Native Global Styles state is invalid.');
            return $checks;
        }
        try {
            $blocks = $this->blocks->summarize((string) ($state['content'] ?? ''));
            $checks[] = $this->check('block_markup', true, sprintf('Gutenberg markup parsed successfully (%d blocks).', count($blocks)));
            $unregistered = $this->unregisteredBlockNames($blocks);
            $checks[] = $this->check('registered_blocks', $unregistered === array(), $unregistered === array() ? 'All named blocks are registered or registration could not be checked.' : 'Unregistered blocks: ' . implode(', ', $unregistered));
            $deps = $this->dependenciesForBlocks($blocks);
            $missingMedia = array_values(array_filter((array) ($deps['media_ids'] ?? array()), fn (int $id): bool => !$this->attachmentExists($id)));
            $checks[] = $this->check('media_dependencies', $missingMedia === array(), $missingMedia === array() ? 'Referenced Media attachments exist.' : 'Missing Media attachment IDs: ' . implode(', ', $missingMedia));
            $missingNavigation = array_values(array_filter((array) ($deps['navigation_ids'] ?? array()), fn (int $id): bool => !$this->postExistsAs($id, 'wp_navigation')));
            $checks[] = $this->check('navigation_dependencies', $missingNavigation === array(), $missingNavigation === array() ? 'Referenced navigation entities exist.' : 'Missing wp_navigation IDs: ' . implode(', ', $missingNavigation));
            $missingPatterns = array_values(array_filter((array) ($deps['pattern_ids'] ?? array()), fn (int $id): bool => !$this->postExistsAs($id, 'wp_block')));
            $checks[] = $this->check('pattern_dependencies', $missingPatterns === array(), $missingPatterns === array() ? 'Referenced synced patterns exist.' : 'Missing wp_block IDs: ' . implode(', ', $missingPatterns));
            $missingTemplateParts = array_values(array_filter((array) ($deps['template_part_slugs'] ?? array()), fn (string $slug): bool => !$this->templatePartExists($slug)));
            $checks[] = $this->check('template_part_dependencies', $missingTemplateParts === array(), $missingTemplateParts === array() ? 'Referenced template parts resolve through WordPress.' : 'Missing template-part slugs: ' . implode(', ', $missingTemplateParts));
            $checks[] = $this->check('gutenberg_editor_contract', true, 'Static block editor validity depends on each block JavaScript save() contract; Presentation therefore permits static mutations only through explicit conservative codecs and does not claim PHP parsing alone proves editor validity.');
            if ('content' === (string) ($state['kind'] ?? '') && '' !== trim((string) ($state['template'] ?? ''))) {
                try {
                    $this->repository->assertTemplateAssignmentAvailable($state, (string) $state['template']);
                    $checks[] = $this->check('template_assignment', true, 'The explicit content template is currently available to this post type.');
                } catch (\Throwable $throwable) {
                    $checks[] = $this->check('template_assignment', false, $throwable->getMessage());
                }
            }
        } catch (\Throwable $throwable) {
            $checks[] = $this->check('block_markup', false, $throwable->getMessage());
        }
        return $checks;
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<string,mixed> */
    private function dependenciesForBlocks(array $blocks): array
    {
        $media = array();
        $navigation = array();
        $patterns = array();
        $templateParts = array();
        $queries = array();
        foreach ($blocks as $block) {
            $name = strtolower((string) ($block['name'] ?? ''));
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
            if (in_array($name, array('core/image', 'core/cover'), true) && isset($attrs['id']) && is_numeric($attrs['id'])) {
                $media[] = (int) $attrs['id'];
            }
            if ('core/gallery' === $name && is_array($attrs['ids'] ?? null)) {
                foreach ($attrs['ids'] as $id) {
                    if (is_numeric($id)) { $media[] = (int) $id; }
                }
            }
            if ('core/navigation' === $name && isset($attrs['ref']) && is_numeric($attrs['ref'])) {
                $navigation[] = (int) $attrs['ref'];
            }
            if ('core/block' === $name && isset($attrs['ref']) && is_numeric($attrs['ref'])) {
                $patterns[] = (int) $attrs['ref'];
            }
            if ('core/template-part' === $name && '' !== trim((string) ($attrs['slug'] ?? ''))) {
                $templateParts[] = (string) $attrs['slug'];
            }
            if ('core/query' === $name) {
                $queries[] = (string) ($block['path'] ?? '');
            }
        }
        return array(
            'media_ids' => array_values(array_unique($media)),
            'navigation_ids' => array_values(array_unique($navigation)),
            'pattern_ids' => array_values(array_unique($patterns)),
            'template_part_slugs' => array_values(array_unique($templateParts)),
            'query_paths' => array_values(array_unique($queries)),
        );
    }

    /** @param array<int,array<string,mixed>> $blocks @return string[] */
    private function unregisteredBlockNames(array $blocks): array
    {
        if (!class_exists('WP_Block_Type_Registry') || !method_exists('WP_Block_Type_Registry', 'get_instance')) {
            return array();
        }
        $registry = \WP_Block_Type_Registry::get_instance();
        $missing = array();
        foreach ($blocks as $block) {
            $name = trim((string) ($block['name'] ?? ''));
            if ('' !== $name && method_exists($registry, 'is_registered') && !$registry->is_registered($name)) {
                $missing[] = $name;
            }
        }
        return array_values(array_unique($missing));
    }

    private function attachmentExists(int $id): bool
    {
        if ($id < 1 || !function_exists('get_post')) {
            return false;
        }
        $post = get_post($id);
        return is_object($post) && 'attachment' === (string) ($post->post_type ?? '');
    }

    private function postExistsAs(int $id, string $postType): bool
    {
        if ($id < 1 || !function_exists('get_post')) {
            return false;
        }
        $post = get_post($id);
        return is_object($post) && $postType === (string) ($post->post_type ?? '');
    }

    private function templatePartExists(string $slug): bool
    {
        $slug = trim($slug);
        if ('' === $slug || !function_exists('get_block_templates')) {
            return false;
        }
        foreach ((array) get_block_templates(array('slug__in' => array($slug)), 'wp_template_part') as $template) {
            if (is_object($template) && $slug === (string) ($template->slug ?? '')) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function previewRenderCheck(array $state): array
    {
        if ('global_styles' === (string) ($state['kind'] ?? '')) {
            return $this->check('preview_render', false, 'Frontend rendering was requested, but Global Styles has no standalone route-level preview in this verifier.');
        }
        if (!function_exists('do_blocks')) {
            return $this->check('preview_render', false, 'Frontend rendering was requested, but WordPress server block rendering is unavailable.');
        }
        try {
            $rendered = do_blocks((string) ($state['content'] ?? ''));
            if (!is_string($rendered)) {
                return $this->check('preview_render', false, 'WordPress did not return rendered block markup for the proposed document.');
            }
            return $this->check('preview_render', true, sprintf('WordPress server-rendered the proposed block document successfully (%d bytes). This does not replace Gutenberg client save() validation for static/hybrid blocks.', strlen($rendered)));
        } catch (\Throwable $throwable) {
            return $this->check('preview_render', false, 'WordPress failed to server-render the proposed block document: ' . $throwable->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function frontendCheck(string $url): array
    {
        if ('' === trim($url)) {
            return $this->check('frontend', false, 'Frontend verification was requested, but no canonical frontend route is available for this Presentation object.');
        }
        if (!function_exists('wp_remote_get')) {
            return $this->check('frontend', false, 'Frontend verification was requested, but WordPress HTTP verification is unavailable.');
        }
        $response = wp_remote_get($url, array('timeout' => 10, 'redirection' => 2));
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return $this->check('frontend', false, 'Frontend request failed: ' . $response->get_error_message());
        }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        return $this->check('frontend', $code >= 200 && $code < 400, sprintf('Frontend route returned HTTP %d.', $code));
    }

    /** @return array<string,mixed> */
    private function check(string $code, bool $ok, string $message): array
    {
        return array('code' => $code, 'ok' => $ok, 'message' => $message);
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed> $after @return string[] */
    private function changes(?array $before, array $after): array
    {
        if (!is_array($before)) {
            return array('create ' . (string) ($after['kind'] ?? 'presentation object'));
        }
        if (!empty($after['_delete'])) {
            if (in_array((string) ($before['kind'] ?? ''), array('template', 'template_part'), true)) {
                return array('remove WordPress ' . (string) $before['kind'] . ' customization');
            }
            return array('delete ' . (string) ($before['kind'] ?? 'presentation object'));
        }
        if (!empty($after['_duplicate'])) {
            return array('duplicate ' . (string) ($before['kind'] ?? 'presentation object'));
        }
        $changes = array();
        if ((string) ($before['content'] ?? '') !== (string) ($after['content'] ?? '')) { $changes[] = 'block composition'; }
        if ((string) ($before['template'] ?? '') !== (string) ($after['template'] ?? '')) { $changes[] = 'template assignment'; }
        if (($before['global_styles'] ?? array()) !== ($after['global_styles'] ?? array())) { $changes[] = 'Global Styles'; }
        return $changes === array() ? array('semantic no-op') : $changes;
    }

    /** @param array<string,mixed>|null $committed */
    private function committedStateMatches(array $expected, ?array $committed): bool
    {
        if (!empty($expected['_delete'])) {
            if (in_array((string) ($expected['kind'] ?? ''), array('template', 'template_part'), true)) {
                return null === $committed
                    || (is_array($committed)
                        && (string) ($expected['template_id'] ?? '') === (string) ($committed['template_id'] ?? '')
                        && 'custom' !== (string) ($committed['source'] ?? ''));
            }
            return null === $committed;
        }
        if (!is_array($committed)) {
            return false;
        }
        foreach (array('kind', 'post_type') as $field) {
            if ((string) ($expected[$field] ?? '') !== (string) ($committed[$field] ?? '')) { return false; }
        }
        if ('global_styles' === (string) ($expected['kind'] ?? '')) {
            if (!$this->valuesEquivalent($expected['global_styles'] ?? array(), $committed['global_styles'] ?? array())) { return false; }
        } elseif ((string) ($expected['content'] ?? '') !== (string) ($committed['content'] ?? '')) {
            return false;
        }
        if ('content' === (string) ($expected['kind'] ?? '') && (string) ($expected['template'] ?? '') !== (string) ($committed['template'] ?? '')) {
            return false;
        }
        if (in_array((string) ($expected['kind'] ?? ''), array('template', 'template_part'), true)) {
            if ('' !== (string) ($expected['template_id'] ?? '') && (string) ($expected['template_id'] ?? '') !== (string) ($committed['template_id'] ?? '')) {
                return false;
            }
            if ('' !== (string) ($expected['source'] ?? '') && (string) ($expected['source'] ?? '') !== (string) ($committed['source'] ?? '')) {
                return false;
            }
        }
        foreach (array('title', 'slug', 'area', 'sync_status') as $field) {
            if ('' !== trim((string) ($expected[$field] ?? '')) && (string) ($expected[$field] ?? '') !== (string) ($committed[$field] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private function valuesEquivalent(mixed $left, mixed $right): bool
    {
        return $this->normalizeComparisonValue($left) === $this->normalizeComparisonValue($right);
    }

    private function normalizeComparisonValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeComparisonValue($item);
        }
        if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return $value;
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $committed */
    private function compensateCommit(?array $before, ?array $committed, array $attemptedAfter): bool
    {
        try {
            if (is_array($before)) {
                $restored = $this->repository->restoreSnapshot($before, !empty($attemptedAfter['_delete']));
                return $this->committedStateMatches($before, $restored);
            }
            $created = is_array($committed) ? $committed : $this->repository->locateCreatedSnapshot($attemptedAfter);
            if (!is_array($created)) {
                return true;
            }
            $this->repository->deleteSnapshot($created);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed>|null $current @param array<string,mixed>|null $restored @param array<string,mixed>|null $before */
    private function compensateRollback(string $kind, ?array $current, ?array $restored, ?array $before): bool
    {
        try {
            if (is_array($current)) {
                $compensated = $this->repository->restoreSnapshot($current, in_array($kind, array('create', 'duplicate'), true));
                return $this->committedStateMatches($current, $compensated);
            }

            $created = $restored;
            if (!is_array($created) && is_array($before)) {
                $created = $this->repository->locateCreatedSnapshot($before);
            }
            if (is_array($created)) {
                $this->repository->deleteSnapshot($created);
            }
            if (!is_array($before) || '' === trim((string) ($before['ref'] ?? ''))) {
                return true;
            }
            try {
                $this->repository->snapshotByRef((string) $before['ref']);
                return false;
            } catch (\Throwable) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $after */
    private function transactionKind(array $after): string
    {
        if (!empty($after['_delete'])) { return 'delete'; }
        if (!empty($after['_duplicate'])) { return 'duplicate'; }
        if (!empty($after['_create'])) { return 'create'; }
        return 'update';
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function rollbackImpact(string $kind, ?array $before, ?array $after): string
    {
        if (in_array($kind, array('create', 'duplicate'), true) && is_array($after) && in_array((string) ($after['kind'] ?? ''), array('template', 'template_part'), true)) {
            return 'Remove the custom WordPress template record created by this transaction; an underlying theme/plugin source will remain authoritative if one exists.';
        }
        if ('delete' === $kind && is_array($before) && in_array((string) ($before['kind'] ?? ''), array('template', 'template_part'), true)) {
            return 'Restore the removed WordPress template customization over its current underlying source.';
        }
        return match ($kind) {
            'create', 'duplicate' => 'Delete the object created by this transaction.',
            'delete' => 'Restore the deleted native Presentation object from the recorded pre-commit snapshot.',
            default => 'Restore the target Presentation-owned state to its recorded pre-commit snapshot.',
        };
    }

    /** @param array<int,array<string,mixed>> $operations */
    private function containsAction(array $operations, string $action): bool
    {
        foreach ($operations as $operation) {
            if (strtolower(trim((string) ($operation['action'] ?? ''))) === $action) {
                return true;
            }
        }
        return false;
    }
}
