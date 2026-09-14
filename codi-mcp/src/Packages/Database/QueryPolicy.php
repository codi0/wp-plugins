<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Database;

final class QueryPolicy
{
    private ?SqlStructureValidator $structureValidator = null;

    public const MAX_SQL_BYTES = 12000;
    public const MAX_PARAMS = 100;
    public const MAX_ROWS = 200;
    public const MAX_RESPONSE_BYTES = 131072;
    public const MAX_CELL_BYTES = 8192;

    /**
     * @param array<int,mixed> $params
     * @return array{sql:string,placeholder_types:array<int,string>,statement:string}|\WP_Error
     */
    public function validate(string $sql, array $params)
    {
        $sql = trim($sql);
        if ($sql === '') {
            return $this->error('codi_mcp_db_query_empty', 'sql is required.');
        }
        if (strlen($sql) > self::MAX_SQL_BYTES) {
            return $this->error('codi_mcp_db_query_too_long', 'sql exceeds the maximum query length.');
        }
        if (count($params) > self::MAX_PARAMS) {
            return $this->error('codi_mcp_db_query_too_many_params', 'params exceeds the maximum parameter count.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $sql)) {
            return $this->error('codi_mcp_db_query_control_chars', 'sql contains unsupported control characters.');
        }
        if (preg_match('/(?:--[\x20\t]|#|\/\*)/', $sql)) {
            return $this->error('codi_mcp_db_query_comments', 'SQL comments are not allowed.');
        }
        if (strpos($sql, "'") !== false || strpos($sql, '"') !== false) {
            return $this->error('codi_mcp_db_query_literal', 'Quoted SQL literals are not allowed; pass values through params placeholders.');
        }
        if (strpos($sql, '@') !== false || strpos($sql, ':=') !== false) {
            return $this->error('codi_mcp_db_query_session_state', 'SQL session variables and assignments are not allowed.');
        }

        $backtickError = $this->validateBacktickIdentifiers($sql);
        if ($backtickError instanceof \WP_Error) {
            return $backtickError;
        }

        $sql = $this->stripOptionalTrailingSemicolon($sql);
        if (strpos($sql, ';') !== false) {
            return $this->error('codi_mcp_db_query_multiple_statements', 'Multiple SQL statements are not allowed.');
        }

        $placeholderTypes = $this->placeholderTypes($sql);
        if ($placeholderTypes instanceof \WP_Error) {
            return $placeholderTypes;
        }
        if (count($placeholderTypes) !== count($params)) {
            return $this->error(
                'codi_mcp_db_query_param_count',
                sprintf('sql contains %d placeholders but %d params were supplied.', count($placeholderTypes), count($params))
            );
        }

        foreach ($placeholderTypes as $index => $type) {
            $paramError = $this->validateParam($type, $params[$index] ?? null, $index);
            if ($paramError instanceof \WP_Error) {
                return $paramError;
            }
        }

        $masked = $this->maskIdentifiersAndPlaceholders($sql);
        if (preg_match('/\b(?:SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|LOAD_FILE|MASTER_POS_WAIT|WAIT_FOR_EXECUTED_GTID_SET)\s*\(/i', $masked)) {
            return $this->error('codi_mcp_db_query_dangerous_function', 'sql contains a blocked function.');
        }

        $ast = $this->structureValidator()->validate($sql, $placeholderTypes);
        if ($ast instanceof \WP_Error) {
            return $ast;
        }
        $statement = (string) $ast['statement'];

        if (preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|RENAME|GRANT|REVOKE|CALL|DO|HANDLER|LOAD|LOCK|UNLOCK|SET|USE|START|COMMIT|ROLLBACK|SAVEPOINT|RELEASE|KILL|ANALYZE|OPTIMIZE|REPAIR|INTO|PROCEDURE)\b/i', $masked)) {
            return $this->error('codi_mcp_db_query_disallowed_keyword', 'sql contains a disallowed statement or side-effecting clause.');
        }
        if (preg_match('/\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $masked)) {
            return $this->error('codi_mcp_db_query_locking', 'Locking SELECT clauses are not allowed.');
        }
        if (preg_match('/\b(?:INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|MYSQL|SYS)\s*\./i', $masked)) {
            return $this->error('codi_mcp_db_query_system_schema', 'Queries against server/system schemas are not allowed.');
        }
        if (preg_match('/\b[A-Za-z0-9_$]*(?:PASS(?:WORD)?|PASSWD|PASSWORD_HASH|SECRET|TOKEN|API_KEY|API_SECRET|PRIVATE_KEY|AUTH_KEY|ACTIVATION_KEY|SESSION|CREDENTIAL|LICENSE_KEY|CONSUMER_SECRET|WEBHOOK_SECRET|SIGNING_KEY|ENCRYPTION_KEY)[A-Za-z0-9_$]*\b/i', $masked)) {
            return $this->error('codi_mcp_db_query_sensitive_identifier', 'sql references a protected credential-like identifier.');
        }
        if (preg_match('/(?<![A-Za-z0-9_$])(?:0x[0-9A-Fa-f]+|0b[01]+|(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)(?![A-Za-z0-9_$])/i', $masked)) {
            return $this->error('codi_mcp_db_query_numeric_literal', 'Embedded numeric literals are not allowed; pass values through params placeholders.');
        }

        return array(
            'sql' => $sql,
            'placeholder_types' => $placeholderTypes,
            'statement' => $statement,
        );
    }

    /**
     * @param array<int,mixed> $params
     * @param array<int,string> $placeholderTypes
     * @param array<int,string> $tableNames
     * @param array<string,bool> $sharedTables
     */
    public function validateTableScope(string $sql, array $params, array $placeholderTypes, array $tableNames, array $sharedTables, string $prefix, string $basePrefix)
    {
        if ($this->hasImplicitCommaJoin($sql)) {
            return $this->error('codi_mcp_db_query_comma_join', 'Implicit comma joins are not supported; use explicit JOIN clauses.');
        }

        $tableParameterIndexes = $this->structureValidator()->tableParameterIndexes($sql, $placeholderTypes);
        if ($tableParameterIndexes instanceof \WP_Error) {
            return $tableParameterIndexes;
        }
        foreach ($tableParameterIndexes as $paramIndex) {
            if (($placeholderTypes[$paramIndex] ?? '') !== 'i') {
                return $this->error('codi_mcp_db_query_table_parameter', 'Table sources must use %i identifier placeholders.');
            }
            $table = (string) ($params[$paramIndex] ?? '');
            if (!in_array($table, $tableNames, true)) {
                return $this->error('codi_mcp_db_query_table_not_found', 'A table identifier parameter does not name an existing table in the current WordPress database.');
            }
            $relationship = $this->relationship($table, $sharedTables, $prefix, $basePrefix);
            if ($relationship !== 'site' && $relationship !== 'shared') {
                return $this->error('codi_mcp_db_query_table_scope', 'A table identifier parameter references a table outside the current site/shared WordPress scope.');
            }
        }

        return true;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{rows:array<int,array<string,string|null>>,redacted_fields:int,truncated_fields:int}
     */
    public function sanitizeRows(array $rows): array
    {
        $cleanRows = array();
        $redacted = 0;
        $truncated = 0;

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : array();
            $sensitiveContext = false;
            foreach ($row as $column => $value) {
                if ($this->isKeyColumn((string) $column) && is_scalar($value) && $this->isSensitiveKeyName((string) $value)) {
                    $sensitiveContext = true;
                    break;
                }
            }

            $clean = array();
            foreach ($row as $column => $value) {
                $column = (string) $column;
                if ($this->isSensitiveColumn($column) || ($sensitiveContext && $this->isPayloadColumn($column)) || $this->looksLikeSecretValue($value)) {
                    $clean[$column] = '[REDACTED]';
                    $redacted++;
                    continue;
                }

                if ($value === null) {
                    $clean[$column] = null;
                    continue;
                }

                $text = is_scalar($value) ? (string) $value : $this->json($value);
                if (strlen($text) > self::MAX_CELL_BYTES) {
                    $text = $this->truncateUtf8($text, self::MAX_CELL_BYTES) . '…';
                    $truncated++;
                }
                $clean[$column] = $text;
            }
            $cleanRows[] = $clean;
        }

        return array('rows' => $cleanRows, 'redacted_fields' => $redacted, 'truncated_fields' => $truncated);
    }

    private function validateBacktickIdentifiers(string $sql)
    {
        $offset = 0;
        $length = strlen($sql);
        while ($offset < $length) {
            $start = strpos($sql, '`', $offset);
            if ($start === false) {
                break;
            }
            $end = strpos($sql, '`', $start + 1);
            if ($end === false) {
                return $this->error('codi_mcp_db_query_identifier', 'Unterminated backtick identifier.');
            }
            $identifier = substr($sql, $start + 1, $end - $start - 1);
            if ($identifier === '' || !preg_match('/^[A-Za-z0-9_$-]+$/', $identifier)) {
                return $this->error('codi_mcp_db_query_identifier', 'Backtick identifiers may contain only letters, numbers, underscore, dollar sign, and hyphen.');
            }
            $offset = $end + 1;
        }
        return true;
    }

    private function hasImplicitCommaJoin(string $sql): bool
    {
        $length = strlen($sql);
        $depth = 0;
        $fromDepth = array();

        for ($index = 0; $index < $length;) {
            $char = $sql[$index];
            if ($char === '`') {
                $end = strpos($sql, '`', $index + 1);
                if ($end === false) {
                    return true;
                }
                $index = $end + 1;
                continue;
            }
            if ($char === '%' && $index + 1 < $length) {
                $index += 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
                $index++;
                continue;
            }
            if ($char === ')') {
                unset($fromDepth[$depth]);
                $depth = max(0, $depth - 1);
                $index++;
                continue;
            }
            if ($char === ',') {
                if (!empty($fromDepth[$depth])) {
                    return true;
                }
                $index++;
                continue;
            }
            if (preg_match('/[A-Za-z_]/', $char)) {
                $start = $index;
                while ($index < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$index])) {
                    $index++;
                }
                $word = strtoupper(substr($sql, $start, $index - $start));
                if ($word === 'FROM') {
                    $fromDepth[$depth] = true;
                } elseif (in_array($word, array('WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'UNION', 'EXCEPT', 'INTERSECT', 'FOR'), true)) {
                    $fromDepth[$depth] = false;
                }
                continue;
            }
            $index++;
        }

        return false;
    }

    /** @return array<int,string>|\WP_Error */
    private function placeholderTypes(string $sql)
    {
        $types = array();
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            if ($sql[$index] !== '%') {
                continue;
            }
            if ($index + 1 >= $length) {
                return $this->error('codi_mcp_db_query_placeholder', 'A trailing percent sign is not a valid placeholder.');
            }
            $type = $sql[$index + 1];
            if ($type === '%') {
                $index++;
                continue;
            }
            $type = strtolower($type);
            if (!in_array($type, array('s', 'd', 'f', 'i'), true)) {
                return $this->error('codi_mcp_db_query_placeholder', 'Only %s, %d, %f, %i, and %% placeholders are allowed.');
            }
            $types[] = $type;
            $index++;
        }
        return $types;
    }

    private function validateParam(string $type, $value, int $index)
    {
        if ($type === 'i') {
            if (!is_string($value) || $value === '' || strlen($value) > 255 || !preg_match('/^[A-Za-z0-9_$-]+$/', $value)) {
                return $this->error('codi_mcp_db_query_identifier_param', sprintf('params[%d] must be a simple identifier string for %%i.', $index));
            }
            return true;
        }
        if ($type === 'd' && !is_int($value)) {
            return $this->error('codi_mcp_db_query_integer_param', sprintf('params[%d] must be an integer for %%d.', $index));
        }
        if ($type === 'f' && !is_int($value) && !is_float($value)) {
            return $this->error('codi_mcp_db_query_float_param', sprintf('params[%d] must be numeric for %%f.', $index));
        }
        if ($type === 's' && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            return $this->error('codi_mcp_db_query_string_param', sprintf('params[%d] is not a supported scalar value for %%s.', $index));
        }
        return true;
    }

    private function stripOptionalTrailingSemicolon(string $sql): string
    {
        $sql = rtrim($sql);
        if (substr($sql, -1) === ';') {
            return rtrim(substr($sql, 0, -1));
        }
        return $sql;
    }

    private function maskIdentifiersAndPlaceholders(string $sql): string
    {
        $sql = $this->maskBackticks($sql);
        $sql = preg_replace('/%%|%[sdfi]/i', ' P ', $sql) ?? $sql;
        return $sql;
    }

    private function maskBackticks(string $sql): string
    {
        return preg_replace('/`[^`]*`/', ' IDENT ', $sql) ?? $sql;
    }

    /** @param array<string,bool> $sharedTables */
    private function relationship(string $table, array $sharedTables, string $prefix, string $basePrefix): string
    {
        if (isset($sharedTables[$table])) {
            return 'shared';
        }
        if ($prefix === $basePrefix && $basePrefix !== '' && preg_match('/^' . preg_quote($basePrefix, '/') . '[1-9][0-9]*_/', $table)) {
            return 'wordpress-other';
        }
        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return 'site';
        }
        if ($basePrefix !== '' && strpos($table, $basePrefix) === 0) {
            return 'wordpress-other';
        }
        return 'other';
    }

    private function isSensitiveColumn(string $column): bool
    {
        return (bool) preg_match('/(?:^|_)(?:pass(?:word)?|passwd|password_hash|secret|token|api_key|api_secret|private_key|client_secret|access_token|refresh_token|auth_key|activation_key|session|credential|license_key|consumer_secret|webhook_secret|signing_key|encryption_key)(?:$|_)/i', $column);
    }

    private function isKeyColumn(string $column): bool
    {
        return (bool) preg_match('/(?:^|_)(?:name|key|setting|option_name|meta_key)$/i', $column);
    }

    private function isPayloadColumn(string $column): bool
    {
        return (bool) preg_match('/(?:^|_)(?:value|data|content|payload|option_value|meta_value)$/i', $column);
    }

    private function isSensitiveKeyName(string $value): bool
    {
        $value = strtolower(trim($value));
        return (bool) preg_match('/(?:^|[_\-.])(?:password|passwd|pass|secret|token|api[_-]?key|api[_-]?secret|client[_-]?secret|access[_-]?token|refresh[_-]?token|private[_-]?key|auth[_-]?key|session)(?:$|[_\-.])/i', $value);
    }

    private function looksLikeSecretValue($value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        if (preg_match('/^Bearer\s+\S+/i', $value)) {
            return true;
        }
        if (preg_match('/^-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $value)) {
            return true;
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,}$/', $value)) {
            return true;
        }
        return (bool) preg_match('/^\$(?:P\$|H\$|2[aby]\$|wp\$)/', $value);
    }

    private function truncateUtf8(string $value, int $bytes): string
    {
        if (function_exists('mb_strcut')) {
            return (string) mb_strcut($value, 0, $bytes, 'UTF-8');
        }

        $cut = substr($value, 0, $bytes);
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }
        return $cut;
    }

    private function json($value): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        return is_string($json) ? $json : '';
    }

    private function structureValidator(): SqlStructureValidator
    {
        return $this->structureValidator ??= new SqlStructureValidator();
    }

    private function error(string $code, string $message): \WP_Error
    {
        return new \WP_Error($code, $message);
    }
}
