<?php

declare(strict_types=1);

namespace CodiMcp\Audit;

use CodiMcp\Core\Storage\OptionMutex;

final class AuditLog
{
    private const OPTION = 'codi_mcp_audit_log_v1';
    private const MAX_RECORDS = 250;

    public function __construct(private ?OptionMutex $mutex = null)
    {
        $this->mutex ??= new OptionMutex();
    }

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

        $this->mutex->synchronized('audit-log', function () use ($eventId, $status, $reasonCode): void {
            $records = $this->records();
            foreach ($records as &$record) {
                if ((string) ($record['event_id'] ?? '') !== $eventId) {
                    continue;
                }
                $record['status'] = $status;
                $record['reason_code'] = $reasonCode;
                $record['completed_at'] = gmdate('c');
                $this->write($records);
                return;
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
        $this->mutex->synchronized('audit-log', function () use ($record): void {
            $records = $this->records();
            array_unshift($records, $record);
            $this->write(array_slice($records, 0, self::MAX_RECORDS));
        });
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
