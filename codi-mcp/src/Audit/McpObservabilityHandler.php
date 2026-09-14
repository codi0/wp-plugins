<?php

declare(strict_types=1);

namespace CodiMcp\Audit;

use CodiMcp\Core\Abilities\AbilityCatalogue;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

final class McpObservabilityHandler implements McpObservabilityHandlerInterface
{
    public function record_event(string $event, array $tags = array(), ?float $duration_ms = null): void
    {
        if ($event !== 'mcp.request') {
            return;
        }

        $abilityName = is_scalar($tags['ability_name'] ?? null) ? trim((string) $tags['ability_name']) : '';
        $status = is_scalar($tags['status'] ?? null) ? strtolower(trim((string) $tags['status'])) : '';
        if ($abilityName === '' || !in_array($status, array('success', 'error'), true)) {
            return;
        }

        $descriptor = (new AbilityCatalogue())->get($abilityName);
        if ($descriptor === null || !empty($descriptor['owned']) || empty($descriptor['adoptable'])) {
            return;
        }

        $reason = '';
        if ($status === 'error') {
            foreach (array('failure_reason', 'error_category', 'error_code') as $field) {
                if (is_scalar($tags[$field] ?? null) && trim((string) $tags[$field]) !== '') {
                    $reason = (string) $tags[$field];
                    break;
                }
            }
        }

        try {
            (new AuditLog())->recordAbilityOutcome(
                $abilityName,
                $status === 'success' ? 'succeeded' : 'failed',
                'mcp',
                $reason
            );
        } catch (\Throwable) {
            if (function_exists('error_log')) {
                error_log('Codi MCP could not persist an adopted ability audit event.');
            }
        }
    }
}