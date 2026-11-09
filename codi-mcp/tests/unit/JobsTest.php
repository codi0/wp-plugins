<?php

declare(strict_types=1);

namespace {
    class ActionScheduler_Store
    {
        public const STATUS_COMPLETE = 'complete';
        public const STATUS_PENDING = 'pending';
        public const STATUS_RUNNING = 'in-progress';
        public const STATUS_FAILED = 'failed';
        public const STATUS_CANCELED = 'canceled';
    }

    class ActionScheduler_NullAction
    {
    }

    class CodiJobsFakeSchedule
    {
        public function __construct(private ?\DateTimeImmutable $date, private mixed $recurrence = null)
        {
        }

        public function get_date(): ?\DateTimeImmutable
        {
            return $this->date;
        }

        public function is_recurring(): bool
        {
            return $this->recurrence !== null;
        }

        public function get_recurrence(): mixed
        {
            return $this->recurrence;
        }
    }

    class CodiJobsFakeAction
    {
        public function __construct(
            private string $hook,
            private array $args,
            private CodiJobsFakeSchedule $schedule,
            private string $group = '',
            private int $priority = 10
        ) {
        }

        public function get_hook(): string { return $this->hook; }
        public function get_args(): array { return $this->args; }
        public function get_schedule(): CodiJobsFakeSchedule { return $this->schedule; }
        public function get_group(): string { return $this->group; }
        public function get_priority(): int { return $this->priority; }
    }

    class CodiJobsFakeLogEntry
    {
        public function __construct(private \DateTimeImmutable $date, private string $message)
        {
        }

        public function get_date(): \DateTimeImmutable { return $this->date; }
        public function get_message(): string { return $this->message; }
    }

    class CodiJobsFakeStore
    {
        public array $rows = array();

        public function query_actions(array $query = array(), string $format = ''): mixed
        {
            $ids = array();
            foreach ($this->rows as $id => $row) {
                $action = $row['action'];
                $status = (string) $row['status'];
                if (!empty($query['status']) && $status !== (string) $query['status']) {
                    continue;
                }
                if (!empty($query['hook']) && $action->get_hook() !== (string) $query['hook']) {
                    continue;
                }
                if (!empty($query['group']) && $action->get_group() !== (string) $query['group']) {
                    continue;
                }
                if (!empty($query['search']) && stripos($action->get_hook() . ' ' . $action->get_group() . ' ' . (string) $id, (string) $query['search']) === false) {
                    continue;
                }
                if (isset($query['date']) && $query['date'] instanceof \DateTimeInterface) {
                    $date = $action->get_schedule()->get_date();
                    if (!$date || $date > $query['date']) {
                        continue;
                    }
                }
                $ids[] = (int) $id;
            }
            sort($ids);
            if ($format === 'count') {
                return count($ids);
            }
            $offset = max(0, (int) ($query['offset'] ?? 0));
            $limit = max(1, (int) ($query['per_page'] ?? 50));
            return array_slice($ids, $offset, $limit);
        }

        public function fetch_action(int $id): object
        {
            return $this->rows[$id]['action'] ?? new ActionScheduler_NullAction();
        }

        public function get_status(int $id): string
        {
            return (string) ($this->rows[$id]['status'] ?? '');
        }

        public function get_claim_id(int $id): int
        {
            return (int) ($this->rows[$id]['claim_id'] ?? 0);
        }

        public function cancel_action(int $id): void
        {
            if (!isset($this->rows[$id])) {
                throw new \InvalidArgumentException('Missing action.');
            }
            $this->rows[$id]['status'] = ActionScheduler_Store::STATUS_CANCELED;
        }
    }

    class CodiJobsFakeLogger
    {
        public array $logs = array();

        public function get_logs(int $id): array
        {
            return (array) ($this->logs[$id] ?? array());
        }
    }

    class CodiJobsFakeRunner
    {
        public function __construct(private CodiJobsFakeStore $store, private CodiJobsFakeLogger $logger)
        {
        }

        public function process_action(int $id, string $context = ''): void
        {
            if (!isset($this->store->rows[$id])) {
                throw new \InvalidArgumentException('Missing action.');
            }
            $this->store->rows[$id]['status'] = ActionScheduler_Store::STATUS_COMPLETE;
            $this->logger->logs[$id][] = new CodiJobsFakeLogEntry(
                new \DateTimeImmutable('2026-11-07T12:00:00Z'),
                'action complete via ' . $context
            );
        }
    }

    class ActionScheduler
    {
        public static CodiJobsFakeStore $store;
        public static CodiJobsFakeLogger $logger;
        public static CodiJobsFakeRunner $runner;

        public static function store(): CodiJobsFakeStore { return self::$store; }
        public static function logger(): CodiJobsFakeLogger { return self::$logger; }
        public static function runner(): CodiJobsFakeRunner { return self::$runner; }
    }
}

namespace CodiMcpTest\Unit {
    use CodiMcp\Packages\Jobs\ActionSchedulerBackend;
    use CodiMcp\Packages\Jobs\Package;
    use CodiMcpTest\Framework\TestCase;

    final class JobsTest extends TestCase
    {
        protected function setUp(): void
        {
                $store = new \CodiJobsFakeStore();
            $logger = new \CodiJobsFakeLogger();
            \ActionScheduler::$store = $store;
            \ActionScheduler::$logger = $logger;
            \ActionScheduler::$runner = new \CodiJobsFakeRunner($store, $logger);

            $store->rows[1] = array(
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'claim_id' => 0,
                'action' => new \CodiJobsFakeAction(
                    'codi_test_job',
                    array('user_id' => 123, 'email' => 'private@example.test', 'token' => 'secret-token'),
                    new \CodiJobsFakeSchedule(new \DateTimeImmutable('2026-11-07T12:00:00Z')),
                    'codi-test',
                    5
                ),
            );
            $store->rows[2] = array(
                'status' => \ActionScheduler_Store::STATUS_FAILED,
                'claim_id' => 0,
                'action' => new \CodiJobsFakeAction(
                    'codi_failed_job',
                    array('id' => 456),
                    new \CodiJobsFakeSchedule(new \DateTimeImmutable('2026-11-06T12:00:00Z'), 3600),
                    'codi-test'
                ),
            );
            $store->rows[3] = array(
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'claim_id' => 0,
                'action' => new \CodiJobsFakeAction(
                    'codi_cancel_job',
                    array(),
                    new \CodiJobsFakeSchedule(new \DateTimeImmutable('2026-11-08T12:00:00Z')),
                    'codi-test'
                ),
            );
            $store->rows[4] = array(
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'claim_id' => 99,
                'action' => new \CodiJobsFakeAction(
                    'codi_claimed_job',
                    array(),
                    new \CodiJobsFakeSchedule(new \DateTimeImmutable('2026-11-08T12:00:00Z')),
                    'codi-test'
                ),
            );
            $logger->logs[1] = array(
                new \CodiJobsFakeLogEntry(new \DateTimeImmutable('2026-11-07T11:00:00Z'), 'created token=log-secret'),
            );
            add_action('codi_test_job', static function (): void {
            });
            $callback = static function (): void {
            };
            $GLOBALS['wp_filter']['codi_test_job'] = (object) array(
                'callbacks' => array(
                    10 => array(
                        'fixture' => array('function' => $callback, 'accepted_args' => 0),
                    ),
                ),
            );
            do_action('action_scheduler_init');
        }

        public function test_package_always_registers_only_readonly_jobs(): void
        {
            $package = new Package();
            $expected = array('codi/jobs-list', 'codi/job-inspect');
            $this->assertSame($expected, $package->abilityNames());
            $this->assertSame($expected, $package->managedExposureAbilityNames());

            $package->registerCategories();
            $package->registerAbilities();
            $this->assertSame($expected, array_keys((array) $GLOBALS['codi_mcp_test_registered_abilities']));

            foreach ($expected as $name) {
                $descriptor = (array) $GLOBALS['codi_mcp_test_registered_abilities'][$name];
                $this->assertTrue((bool) ($descriptor['meta']['annotations']['readonly'] ?? false));
                $this->assertFalse((bool) ($descriptor['meta']['annotations']['destructive'] ?? true));
                $this->assertTrue((bool) ($descriptor['meta']['annotations']['idempotent'] ?? false));
            }
            $this->assertFalse(isset($GLOBALS['codi_mcp_test_registered_abilities']['codi/job-run']));
            $this->assertFalse(isset($GLOBALS['codi_mcp_test_registered_abilities']['codi/job-cancel']));
            $backend = new ActionSchedulerBackend();
            $this->assertFalse(method_exists($backend, 'run'));
            $this->assertFalse(method_exists($backend, 'cancel'));
        }

        public function test_jobs_require_super_admin_on_multisite(): void
        {
            $GLOBALS['codi_mcp_test_wp_current_user_id'] = 8;
            $GLOBALS['codi_mcp_test_wp_users'][8] = array(
                'sites' => array(1 => array('manage_options' => true)),
            );
            $GLOBALS['codi_mcp_test_wp_super_admins'] = array(7);

            $backend = new ActionSchedulerBackend();
            $this->assertFalse($backend->canManage());

            $GLOBALS['codi_mcp_test_wp_current_user_id'] = 7;
            $this->assertTrue($backend->canManage());
        }

        public function test_list_uses_store_without_exposing_arguments_and_inspect_redacts_strings(): void
        {
            $backend = new ActionSchedulerBackend();
            $list = $backend->list(array('status' => 'pending', 'group' => 'codi-test', 'per_page' => 20));

            $this->assertFalse($list instanceof \WP_Error);
            $this->assertSame(3, (int) $list['total']);
            $this->assertFalse(array_key_exists('args_json', (array) $list['items'][0]));

            $detail = $backend->inspect(array('id' => 1));
            $this->assertFalse($detail instanceof \WP_Error);
            $this->assertTrue(str_contains((string) $detail['args_json'], '123'));
            $this->assertFalse(str_contains((string) $detail['args_json'], 'private@example.test'));
            $this->assertFalse(str_contains((string) $detail['args_json'], 'secret-token'));
            $this->assertTrue(count((array) $detail['callbacks']) >= 1);
            $this->assertFalse(str_contains((string) $detail['logs'][0]['message'], 'log-secret'));
        }
    }
}
