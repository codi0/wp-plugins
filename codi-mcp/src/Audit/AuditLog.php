<?php

declare(strict_types=1);

namespace CodiMcp\Audit;

final class AuditLog
{
    private const OPTION = 'codi_mcp_audit_log_v1';
    private const MAX_RECORDS = 250;
    private const WRITE_ATTEMPTS = 100;
    private const RETRY_DELAY_MICROSECONDS = 1000;

    public function beginAbility(string $abilityName, $input, string $source): string
    {
        $eventId = 'aud_' . bin2hex(random_bytes(12));
        $inputKeys = is_array($input) ? array_map('strval', array_keys($input)) : array();
        sort($inputKeys, SORT_STRING);
        $this->append(array(
            'event_id' => $eventId,
            'event' => 'ability.execute',
            'subject' => $abilityName,
            'status' => 'started',
            'source' => $source,
            'site_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'occurred_at' => gmdate('c'),
            'completed_at' => '',
            'input_keys' => $inputKeys,
            'client_id_hash' => '',
            'reason_code' => '',
        ));
        return $eventId;
    }

    public function completeAbility(string $eventId, string $status, string $reasonCode = ''): void
    {
        if (!in_array($status, array('succeeded', 'failed'), true)) {
            throw new \InvalidArgumentException('Codi MCP audit terminal status must be succeeded or failed.');
        }
        $reasonCode = preg_replace('/[^A-Za-z0-9_.:-]/', '', trim($reasonCode)) ?? '';
        $reasonCode = substr($reasonCode, 0, 100);

        $this->mutate(function (array $records) use ($eventId, $status, $reasonCode): array {
            foreach ($records as &$record) {
                if ((string) ($record['event_id'] ?? '') !== $eventId) {
                    continue;
                }
                $record['status'] = $status;
                $record['reason_code'] = $reasonCode;
                $record['completed_at'] = gmdate('c');
                return $records;
            }
            throw new \RuntimeException('Codi MCP audit event was not found.');
        });
    }

    public function recordAbilityOutcome(string $abilityName, string $status, string $source, string $reasonCode = ''): void
    {
        if (!in_array($status, array('succeeded', 'failed'), true)) {
            throw new \InvalidArgumentException('Codi MCP audit terminal status must be succeeded or failed.');
        }
        $reasonCode = preg_replace('/[^A-Za-z0-9_.:-]/', '', trim($reasonCode)) ?? '';
        $reasonCode = substr($reasonCode, 0, 100);
        $this->append(array(
            'event_id' => 'aud_' . bin2hex(random_bytes(12)),
            'event' => 'ability.execute',
            'subject' => trim($abilityName),
            'status' => $status,
            'source' => trim($source),
            'site_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'occurred_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
            'input_keys' => array(),
            'client_id_hash' => '',
            'reason_code' => $reasonCode,
        ));
    }
    public function recordOAuth(string $event, string $clientId, string $status, int $userId = 0): void
    {
        $this->append(array(
            'event_id' => 'aud_' . bin2hex(random_bytes(12)),
            'event' => $event,
            'subject' => 'oauth',
            'status' => $status,
            'source' => 'oauth',
            'site_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1,
            'user_id' => $userId,
            'occurred_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
            'input_keys' => array(),
            'client_id_hash' => $clientId === '' ? '' : substr(hash('sha256', $clientId), 0, 16),
            'reason_code' => '',
        ));
    }

    /** @return array<int,array<string,mixed>> */
    public function list(int $limit = 50): array
    {
        return array_slice($this->records(), 0, max(1, min(self::MAX_RECORDS, $limit)));
    }

    /** @param array<string,mixed> $record */
    private function append(array $record): void
    {
        $this->mutate(function (array $records) use ($record): array {
            array_unshift($records, $record);
            return array_slice($records, 0, self::MAX_RECORDS);
        });
    }

    /**
     * Mutate the bounded audit option without a separate option-based mutex.
     *
     * Real WordPress requests use an optimistic compare-and-swap against the
     * option row so concurrent MCP abilities can append/complete audit records
     * without serializing behind a second shared lock option. Lightweight test
     * environments without wpdb fall back to the normal option API.
     *
     * @param callable(array<int,array<string,mixed>>):array<int,array<string,mixed>> $mutator
     */
    private function mutate(callable $mutator): void
    {
        global $wpdb;

        if (is_object($wpdb)
            && isset($wpdb->options)
            && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'get_var')
            && method_exists($wpdb, 'query')
            && function_exists('add_option')
            && function_exists('maybe_serialize')
            && function_exists('maybe_unserialize')) {
            $this->mutateAtomic($wpdb, $mutator);
            return;
        }

        $this->write($mutator($this->records()));
    }

    /**
     * @param object $wpdb
     * @param callable(array<int,array<string,mixed>>):array<int,array<string,mixed>> $mutator
     */
    private function mutateAtomic(object $wpdb, callable $mutator): void
    {
        for ($attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++) {
            $raw = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::OPTION
            ));

            if ($raw === null) {
                $next = $mutator(array());
                if (add_option(self::OPTION, $next, '', false)) {
                    return;
                }
                $this->clearOptionCache();
                usleep(self::RETRY_DELAY_MICROSECONDS);
                continue;
            }

            $decoded = maybe_unserialize($raw);
            $records = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : array();
            $next = $mutator($records);
            $nextRaw = maybe_serialize($next);
            if ($nextRaw === (string) $raw) {
                return;
            }

            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
                $nextRaw,
                self::OPTION,
                (string) $raw
            ));
            if ($updated === 1) {
                $this->clearOptionCache();
                return;
            }
            if ($updated === false) {
                throw new \RuntimeException('Could not persist the Codi MCP audit log.');
            }

            $this->clearOptionCache();
            usleep(self::RETRY_DELAY_MICROSECONDS);
        }

        throw new \RuntimeException('Codi MCP audit storage remained busy after repeated concurrent writes.');
    }

    private function clearOptionCache(): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION, 'options');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function records(): array
    {
        if (!function_exists('get_option')) {
            throw new \RuntimeException('WordPress option storage is unavailable for Codi MCP audit records.');
        }
        $records = get_option(self::OPTION, array());
        return is_array($records) ? array_values(array_filter($records, 'is_array')) : array();
    }

    /** @param array<int,array<string,mixed>> $records */
    private function write(array $records): void
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            throw new \RuntimeException('WordPress option storage is unavailable for Codi MCP audit records.');
        }
        $written = (bool) update_option(self::OPTION, $records, false);
        if (!$written && get_option(self::OPTION, null) !== $records) {
            throw new \RuntimeException('Could not persist the Codi MCP audit log.');
        }
    }
}
