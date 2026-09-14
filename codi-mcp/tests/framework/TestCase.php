<?php

declare(strict_types=1);

namespace CodiMcpTest\Framework;

use RuntimeException;

abstract class TestCase
{
    /** @return array{class:string,passed:int,failed:int,errors:array<int,string>} */
    public function run(): array
    {
        $passed = 0;
        $failed = 0;
        $errors = array();

        foreach ($this->discoverTestMethodNames() as $methodName) {
            try {
                $this->setUp();
                $this->{$methodName}();
                $passed++;
            } catch (\Throwable $throwable) {
                $failed++;
                $errors[] = sprintf('%s::%s - %s', static::class, $methodName, $throwable->getMessage());
            } finally {
                $this->tearDown();
            }
        }

        return array('class' => static::class, 'passed' => $passed, 'failed' => $failed, 'errors' => $errors);
    }

    /** @return array<int,string> */
    public function discoverTestMethodNames(): array
    {
        $methods = array_filter(
            (new \ReflectionObject($this))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test_')
        );
        usort($methods, static fn (\ReflectionMethod $left, \ReflectionMethod $right): int => strcmp($left->getName(), $right->getName()));
        return array_values(array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $methods));
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
        if (function_exists('codi_mcp_test_reset_environment')) {
            \codi_mcp_test_reset_environment();
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected %s, got %s.', var_export($expected, true), var_export($actual, true)));
        }
    }

    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected condition to be false.'): void
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }

    /** @param array<mixed> $array */
    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected key %s.', (string) $key));
        }
    }

    /** @param array<mixed> $items */
    protected function assertCount(int $expectedCount, array $items, string $message = ''): void
    {
        $actual = count($items);
        if ($actual !== $expectedCount) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected count %d, got %d.', $expectedCount, $actual));
        }
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected array to contain %s.', var_export($needle, true)));
        }
    }

    protected function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (in_array($needle, $haystack, true)) {
            throw new RuntimeException($message !== '' ? $message : sprintf('Expected array not to contain %s.', var_export($needle, true)));
        }
    }

}
