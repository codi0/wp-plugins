<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Jobs;

use CodiMcp\Core\Abilities\AbilityMetadata;
use CodiMcp\Core\AbilityPackage;
use CodiMcp\Core\ManagedExposurePackage;

final class Package implements AbilityPackage, ManagedExposurePackage
{
    private ?ActionSchedulerBackend $backend = null;

    public function key(): string
    {
        return 'jobs';
    }

    public function label(): string
    {
        return 'Jobs';
    }

    public function abilityNames(): array
    {
        return array(
            $this->abilityName('jobs-list'),
            $this->abilityName('job-inspect'),
        );
    }

    public function managedExposureAbilityNames(): array
    {
        return $this->abilityNames();
    }

    public function registerCategories(): void
    {
        if ($this->abilityNames() === array() || !function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category($this->category(), array(
            'label' => 'Codi MCP — Jobs',
            'description' => 'Inspect existing Action Scheduler background jobs without exposing job mutation or arbitrary scheduling.',
        ));
    }

    public function registerAbilities(): void
    {
        if ($this->abilityNames() === array() || !function_exists('wp_register_ability')) {
            return;
        }

        $this->registerReadOnly(
            'jobs-list',
            'List background jobs',
            'List existing Action Scheduler jobs by status, hook, group, or search without exposing job arguments.',
            $this->listInputSchema(),
            $this->listOutputSchema(),
            array($this->backend(), 'list')
        );
        $this->registerReadOnly(
            'job-inspect',
            'Inspect background job',
            'Inspect one exact Action Scheduler job, including bounded redacted arguments, callbacks, claim state, schedule, and logs.',
            $this->idInputSchema(),
            $this->detailOutputSchema(),
            array($this->backend(), 'inspect')
        );

    }

    public function canManage(): bool
    {
        return $this->backend()->canManage();
    }

    private function registerReadOnly(string $slug, string $label, string $description, array $inputSchema, array $outputSchema, callable $callback): void
    {
        wp_register_ability($this->abilityName($slug), array(
            'label' => $label,
            'description' => $description,
            'category' => $this->category(),
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'execute_callback' => $callback,
            'permission_callback' => array($this, 'canManage'),
            'meta' => AbilityMetadata::owned($this->key(), true, false, true),
        ));
    }

    private function backend(): ActionSchedulerBackend
    {
        return $this->backend ??= new ActionSchedulerBackend();
    }

    private function abilityName(string $slug): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '/' . ltrim($slug, '/');
    }

    private function category(): string
    {
        return rtrim(CODI_MCP_ABILITY_PREFIX, '/') . '-jobs';
    }

    private function idInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array('id' => array('type' => 'integer', 'minimum' => 1)),
            'required' => array('id'),
        );
    }

    private function listInputSchema(): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'status' => array('type' => 'string', 'enum' => array('all', 'pending', 'in-progress', 'failed', 'complete', 'canceled', 'past-due')),
                'hook' => array('type' => 'string', 'maxLength' => 191),
                'group' => array('type' => 'string', 'maxLength' => 191),
                'search' => array('type' => 'string', 'maxLength' => 200),
                'page' => array('type' => 'integer', 'minimum' => 1),
                'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100),
            ),
        );
    }

    private function summarySchema(): array
    {
        return $this->strictObject(array(
            'id' => array('type' => 'integer'),
            'provider' => array('type' => 'string'),
            'hook' => array('type' => 'string'),
            'group' => array('type' => 'string'),
            'status' => array('type' => 'string'),
            'scheduled_at_gmt' => array('type' => 'string'),
            'recurrence' => array('type' => 'string'),
            'priority' => array('type' => 'integer'),
            'claim_id' => array('type' => 'integer'),
            'claimed' => array('type' => 'boolean'),
        ));
    }

    private function listOutputSchema(): array
    {
        return $this->strictObject(array(
            'provider' => array('type' => 'string'),
            'items' => array('type' => 'array', 'items' => $this->summarySchema()),
            'total' => array('type' => 'integer'),
            'page' => array('type' => 'integer'),
            'per_page' => array('type' => 'integer'),
            'returned' => array('type' => 'integer'),
        ));
    }

    private function detailOutputSchema(): array
    {
        $properties = $this->summarySchema()['properties'];
        $properties['args_json'] = array('type' => 'string');
        $properties['callbacks'] = array(
            'type' => 'array',
            'items' => $this->strictObject(array(
                'priority' => array('type' => 'integer'),
                'accepted_args' => array('type' => 'integer'),
                'source' => $this->sourceSchema(),
            )),
        );
        $properties['logs'] = array(
            'type' => 'array',
            'items' => $this->strictObject(array(
                'date_gmt' => array('type' => 'string'),
                'message' => array('type' => 'string'),
            )),
        );
        return $this->strictObject($properties);
    }

    private function sourceSchema(): array
    {
        return $this->strictObject(array(
            'component_type' => array('type' => 'string'),
            'component' => array('type' => 'string'),
            'file' => array('type' => 'string'),
            'line' => array('type' => 'integer'),
            'callable' => array('type' => 'string'),
            'hook' => array('type' => 'string'),
        ));
    }

    private function strictObject(array $properties): array
    {
        return array(
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties,
            'required' => array_keys($properties),
        );
    }
}
