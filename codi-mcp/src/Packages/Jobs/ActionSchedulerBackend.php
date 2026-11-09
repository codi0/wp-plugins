<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Jobs;

use CodiMcp\Core\Security\SensitiveDataRedactor;
use CodiMcp\Core\SourceAttribution;

final class ActionSchedulerBackend
{
    private const MAX_PER_PAGE = 100;
    private const MAX_LOGS = 50;
    private const MAX_CALLBACKS = 50;

    private SensitiveDataRedactor $redactor;
    private SourceAttribution $attribution;

    public function __construct(?SensitiveDataRedactor $redactor = null, ?SourceAttribution $attribution = null)
    {
        $this->redactor = $redactor ?? new SensitiveDataRedactor();
        $this->attribution = $attribution ?? new SourceAttribution();
    }

    public function available(): bool
    {
        return class_exists('ActionScheduler')
            && class_exists('ActionScheduler_Store')
            && method_exists('ActionScheduler', 'store')
            && method_exists('ActionScheduler', 'logger');
    }

    public function canManage(): bool
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return false;
        }
        return !function_exists('is_multisite') || !is_multisite()
            || (function_exists('is_super_admin') && is_super_admin());
    }

    public function list(array $input = array())
    {
        $ready = $this->ready();
        if ($ready instanceof \WP_Error) {
            return $ready;
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($input['per_page'] ?? 20)));
        $status = strtolower(trim((string) ($input['status'] ?? 'all')));
        $query = array(
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'date',
            'order' => 'ASC',
        );

        $hook = trim((string) ($input['hook'] ?? ''));
        $group = trim((string) ($input['group'] ?? ''));
        $search = trim((string) ($input['search'] ?? ''));
        if ($hook !== '') {
            $query['hook'] = $hook;
        }
        if ($group !== '') {
            $query['group'] = $group;
        }
        if ($search !== '') {
            $query['search'] = $search;
        }

        if ($status === 'past-due') {
            $query['status'] = \ActionScheduler_Store::STATUS_PENDING;
            $query['date'] = function_exists('as_get_datetime_object')
                ? as_get_datetime_object()
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $query['date_compare'] = '<=';
        } elseif ($status !== '' && $status !== 'all') {
            $query['status'] = $status;
        }

        try {
            $store = \ActionScheduler::store();
            $total = (int) $store->query_actions($query, 'count');
            $ids = (array) $store->query_actions($query);
        } catch (\Throwable $throwable) {
            return new \WP_Error('codi_mcp_jobs_query_failed', $this->safeMessage($throwable->getMessage()));
        }

        $items = array();
        foreach ($ids as $id) {
            $row = $this->summary((int) $id);
            if (is_array($row)) {
                $items[] = $row;
            }
        }

        return array(
            'provider' => 'action-scheduler',
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'returned' => count($items),
        );
    }

    public function inspect(array $input = array())
    {
        $ready = $this->ready();
        if ($ready instanceof \WP_Error) {
            return $ready;
        }
        return $this->detail(max(1, (int) ($input['id'] ?? 0)));
    }

    private function ready(): bool|\WP_Error
    {
        if (!$this->available()) {
            return new \WP_Error('codi_mcp_jobs_unavailable', 'Action Scheduler is not available on this site.');
        }
        if (method_exists('ActionScheduler', 'is_initialized')) {
            try {
                if (!\ActionScheduler::is_initialized()) {
                    return new \WP_Error('codi_mcp_jobs_not_initialized', 'Action Scheduler has not finished initialization.');
                }
            } catch (\Throwable) {
                return new \WP_Error('codi_mcp_jobs_not_initialized', 'Action Scheduler initialization state could not be verified.');
            }
        } elseif (function_exists('did_action') && did_action('action_scheduler_init') < 1) {
            return new \WP_Error('codi_mcp_jobs_not_initialized', 'Action Scheduler has not finished initialization.');
        }
        return true;
    }

    /** @return array<string,mixed>|\WP_Error */
    private function state(int $id): array|\WP_Error
    {
        try {
            $store = \ActionScheduler::store();
            $status = (string) $store->get_status($id);
            $action = $store->fetch_action($id);
            if ($status === '' || (class_exists('ActionScheduler_NullAction') && $action instanceof \ActionScheduler_NullAction)) {
                return new \WP_Error('codi_mcp_job_not_found', 'The requested Action Scheduler job was not found.');
            }
            $claimId = method_exists($store, 'get_claim_id') ? (int) $store->get_claim_id($id) : 0;
            return array('store' => $store, 'action' => $action, 'status' => $status, 'claim_id' => max(0, $claimId));
        } catch (\Throwable $throwable) {
            return new \WP_Error('codi_mcp_job_read_failed', $this->safeMessage($throwable->getMessage()));
        }
    }

    /** @return array<string,mixed>|\WP_Error */
    private function detail(int $id): array|\WP_Error
    {
        $state = $this->state($id);
        if ($state instanceof \WP_Error) {
            return $state;
        }

        $summary = $this->summaryFromState($id, $state);
        $action = $state['action'];
        $args = is_object($action) && method_exists($action, 'get_args') ? (array) $action->get_args() : array();

        $logs = array();
        try {
            foreach (array_slice((array) \ActionScheduler::logger()->get_logs($id), -self::MAX_LOGS) as $entry) {
                if (!is_object($entry)) {
                    continue;
                }
                $date = method_exists($entry, 'get_date') ? $entry->get_date() : null;
                $logs[] = array(
                    'date_gmt' => $date instanceof \DateTimeInterface ? $date->setTimezone(new \DateTimeZone('UTC'))->format('c') : '',
                    'message' => method_exists($entry, 'get_message') ? $this->safeMessage((string) $entry->get_message()) : '',
                );
            }
        } catch (\Throwable) {
        }

        $summary['args_json'] = $this->argsJson($args);
        $summary['callbacks'] = $this->callbackSources((string) ($summary['hook'] ?? ''));
        $summary['logs'] = $logs;
        return $summary;
    }

    /** @return array<string,mixed>|null */
    private function summary(int $id): ?array
    {
        $state = $this->state($id);
        return is_array($state) ? $this->summaryFromState($id, $state) : null;
    }

    /** @param array<string,mixed> $state */
    private function summaryFromState(int $id, array $state): array
    {
        $action = $state['action'];
        $schedule = is_object($action) && method_exists($action, 'get_schedule') ? $action->get_schedule() : null;
        $date = is_object($schedule) && method_exists($schedule, 'get_date') ? $schedule->get_date() : null;
        $recurrence = '';
        if (is_object($schedule) && method_exists($schedule, 'is_recurring') && $schedule->is_recurring() && method_exists($schedule, 'get_recurrence')) {
            $value = $schedule->get_recurrence();
            $recurrence = is_scalar($value) ? (string) $value : get_debug_type($value);
        }

        return array(
            'id' => $id,
            'provider' => 'action-scheduler',
            'hook' => is_object($action) && method_exists($action, 'get_hook') ? substr((string) $action->get_hook(), 0, 191) : '',
            'group' => is_object($action) && method_exists($action, 'get_group') ? substr((string) $action->get_group(), 0, 191) : '',
            'status' => (string) $state['status'],
            'scheduled_at_gmt' => $date instanceof \DateTimeInterface ? $date->setTimezone(new \DateTimeZone('UTC'))->format('c') : '',
            'recurrence' => substr($recurrence, 0, 191),
            'priority' => is_object($action) && method_exists($action, 'get_priority') ? (int) $action->get_priority() : 10,
            'claim_id' => (int) $state['claim_id'],
            'claimed' => (int) $state['claim_id'] > 0,
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function callbackSources(string $hook): array
    {
        if ($hook === '') {
            return array();
        }

        global $wp_filter;
        $hookObject = is_array($wp_filter ?? null) ? ($wp_filter[$hook] ?? null) : null;
        $callbacksByPriority = is_object($hookObject) && is_array($hookObject->callbacks ?? null)
            ? $hookObject->callbacks
            : array();

        $rows = array();
        foreach ($callbacksByPriority as $priority => $registrations) {
            foreach ((array) $registrations as $registration) {
                $callback = is_array($registration) ? ($registration['function'] ?? null) : null;
                if ($callback === null) {
                    continue;
                }
                $rows[] = array(
                    'priority' => (int) $priority,
                    'accepted_args' => (int) ($registration['accepted_args'] ?? 1),
                    'source' => $this->attribution->fromCallable($callback),
                );
                if (count($rows) >= self::MAX_CALLBACKS) {
                    break 2;
                }
            }
        }
        return $rows;
    }

    private function argsJson(array $args): string
    {
        $sanitized = $this->sanitizeArgs($args);
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($sanitized, JSON_UNESCAPED_SLASHES)
            : json_encode($sanitized, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? substr($encoded, 0, 5000) : '[]';
    }

    private function sanitizeArgs(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 4) {
            return '[depth-limit]';
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return '[redacted]';
        }
        if (is_object($value)) {
            return '[object:' . get_class($value) . ']';
        }
        if (!is_array($value)) {
            return '[' . gettype($value) . ']';
        }

        $result = array();
        $count = 0;
        foreach ($value as $key => $item) {
            if (++$count > 50) {
                $result['_truncated'] = true;
                break;
            }
            $result[$key] = $this->sanitizeArgs($item, $depth + 1);
        }
        return $result;
    }

    private function safeMessage(string $message): string
    {
        return substr($this->redactor->redactLine($message), 0, 1000);
    }
}
