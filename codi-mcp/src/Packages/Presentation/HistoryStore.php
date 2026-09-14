<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Presentation;

use CodiMcp\Core\Storage\OptionMutex;

final class HistoryStore
{
    private const INDEX_OPTION = 'codi_mcp_presentation_history_v1';
    private const RECORD_PREFIX = 'codi_mcp_presentation_tx_';
    private const MAX_RECORDS = 50;

    public function __construct(private ?OptionMutex $mutex = null)
    {
        $this->mutex ??= new OptionMutex();
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    public function record(array $entry): array
    {
        return $this->mutex->synchronized('presentation-history', fn (): array => $this->recordUnlocked($entry));
    }

    /** @param array<string,mixed> $entry @return array<string,mixed> */
    private function recordUnlocked(array $entry): array
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            throw new \RuntimeException('WordPress option storage is unavailable for Presentation history.');
        }
        $transactionId = trim((string) ($entry['transaction_id'] ?? ''));
        if ('' === $transactionId) {
            $transactionId = 'ptx_' . bin2hex(random_bytes(12));
        }
        $entry['transaction_id'] = $transactionId;
        $entry['committed_at'] = (string) ($entry['committed_at'] ?? gmdate('c'));

        $recordKey = self::RECORD_PREFIX . $transactionId;
        $recordWritten = false;
        if (function_exists('add_option')) {
            $recordWritten = (bool) add_option($recordKey, $entry, '', false);
            if (!$recordWritten && function_exists('update_option')) {
                $recordWritten = (bool) update_option($recordKey, $entry, false);
            }
        } elseif (function_exists('update_option')) {
            $recordWritten = (bool) update_option($recordKey, $entry, false);
        }
        if (!$recordWritten) {
            throw new \RuntimeException('Could not persist the Presentation history record.');
        }

        $index = $this->index();
        $index = array_values(array_filter($index, static fn (string $id): bool => $id !== $transactionId));
        array_unshift($index, $transactionId);
        $expired = array_slice($index, self::MAX_RECORDS);
        $index = array_slice($index, 0, self::MAX_RECORDS);
        $indexWritten = (bool) update_option(self::INDEX_OPTION, $index, false);
        if (!$indexWritten && get_option(self::INDEX_OPTION, array()) !== $index) {
            if (function_exists('delete_option')) {
                delete_option($recordKey);
            }
            throw new \RuntimeException('Could not update the Presentation history index.');
        }
        if (function_exists('delete_option')) {
            foreach ($expired as $id) {
                delete_option(self::RECORD_PREFIX . $id);
            }
        }
        return $entry;
    }

    /** @return array<string,mixed> */
    public function get(string $transactionId): array
    {
        $transactionId = trim($transactionId);
        if (!preg_match('/^ptx_[a-f0-9]{24}$/', $transactionId) || !function_exists('get_option')) {
            throw new \InvalidArgumentException('Invalid Presentation transaction ID.');
        }
        $entry = get_option(self::RECORD_PREFIX . $transactionId, null);
        if (!is_array($entry)) {
            throw new \InvalidArgumentException('Presentation transaction was not found.');
        }
        return $entry;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $ref = '', int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $entries = array();
        foreach ($this->index() as $transactionId) {
            try {
                $entry = $this->get($transactionId);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ('' !== $ref && (string) ($entry['ref'] ?? '') !== $ref && (string) ($entry['result_ref'] ?? '') !== $ref) {
                continue;
            }
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }
        return $entries;
    }

    public function markRolledBack(string $transactionId, string $rollbackTransactionId): void
    {
        $this->mutex->synchronized('presentation-history', function () use ($transactionId, $rollbackTransactionId): void {
            $this->markRolledBackUnlocked($transactionId, $rollbackTransactionId);
        });
    }

    private function markRolledBackUnlocked(string $transactionId, string $rollbackTransactionId): void
    {
        $entry = $this->get($transactionId);
        $entry['rolled_back_at'] = gmdate('c');
        $entry['rolled_back_by'] = $rollbackTransactionId;
        if (!function_exists('update_option') || !function_exists('get_option')) {
            throw new \RuntimeException('WordPress option storage is unavailable for Presentation history.');
        }
        $key = self::RECORD_PREFIX . $transactionId;
        $written = (bool) update_option($key, $entry, false);
        if (!$written && get_option($key, null) !== $entry) {
            throw new \RuntimeException('Could not mark the Presentation transaction as rolled back.');
        }
    }

    public function discard(string $transactionId): void
    {
        $this->mutex->synchronized('presentation-history', function () use ($transactionId): void {
            $this->discardUnlocked($transactionId);
        });
    }

    private function discardUnlocked(string $transactionId): void
    {
        $transactionId = trim($transactionId);
        if (!preg_match('/^ptx_[a-f0-9]{24}$/', $transactionId) || !function_exists('get_option') || !function_exists('update_option')) {
            throw new \InvalidArgumentException('Invalid Presentation transaction ID.');
        }
        $index = array_values(array_filter($this->index(), static fn (string $id): bool => $id !== $transactionId));
        $indexWritten = (bool) update_option(self::INDEX_OPTION, $index, false);
        if (!$indexWritten && get_option(self::INDEX_OPTION, array()) !== $index) {
            throw new \RuntimeException('Could not remove the Presentation transaction from the history index.');
        }
        $key = self::RECORD_PREFIX . $transactionId;
        if (function_exists('delete_option')) {
            $deleted = (bool) delete_option($key);
            if (!$deleted && null !== get_option($key, null)) {
                throw new \RuntimeException('Could not remove the Presentation transaction history record.');
            }
        }
    }

    /** @return string[] */
    private function index(): array
    {
        if (!function_exists('get_option')) {
            return array();
        }
        $index = get_option(self::INDEX_OPTION, array());
        if (!is_array($index)) {
            return array();
        }
        return array_values(array_filter(array_map('strval', $index), static fn (string $id): bool => (bool) preg_match('/^ptx_[a-f0-9]{24}$/', $id)));
    }
}
