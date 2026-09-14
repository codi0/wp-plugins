<?php

declare(strict_types=1);

namespace CodiMcp\Packages\Database;

/**
 * Dependency-free structural validator for Codi's deliberately narrow SQL subset.
 *
 * This is not a general SQL parser. It tokenizes only enough grammar to validate the
 * read-only contract enforced by QueryPolicy: one SELECT/EXPLAIN SELECT statement,
 * derived SELECTs, explicit table sources, projection safety, and function allowlisting.
 */
final class SqlStructureValidator
{
    /** @var array<string,bool> */
    private const ALLOWED_FUNCTIONS = array(
        'ABS' => true, 'ASCII' => true, 'AVG' => true, 'BIT_AND' => true, 'BIT_OR' => true, 'BIT_XOR' => true,
        'CAST' => true, 'CEIL' => true, 'CEILING' => true, 'CHAR_LENGTH' => true, 'CHARACTER_LENGTH' => true,
        'COALESCE' => true, 'CONCAT' => true, 'CONCAT_WS' => true, 'CONVERT' => true, 'COUNT' => true,
        'CRC32' => true, 'CUME_DIST' => true, 'CURDATE' => true, 'CURRENT_DATE' => true, 'CURRENT_TIME' => true,
        'CURRENT_TIMESTAMP' => true, 'CURTIME' => true, 'DATABASE' => true, 'DATE' => true, 'DATE_ADD' => true,
        'DATE_FORMAT' => true, 'DATE_SUB' => true, 'DATEDIFF' => true, 'DAY' => true, 'DAYOFMONTH' => true,
        'DAYOFWEEK' => true, 'DAYOFYEAR' => true, 'DENSE_RANK' => true, 'ELT' => true, 'EXP' => true,
        'EXTRACT' => true, 'FIELD' => true, 'FIND_IN_SET' => true, 'FIRST_VALUE' => true, 'FLOOR' => true,
        'FROM_BASE64' => true, 'FROM_UNIXTIME' => true, 'GREATEST' => true, 'GROUP_CONCAT' => true, 'HEX' => true,
        'HOUR' => true, 'IF' => true, 'IFNULL' => true, 'INSTR' => true, 'JSON_CONTAINS' => true,
        'JSON_CONTAINS_PATH' => true, 'JSON_EXTRACT' => true, 'JSON_KEYS' => true, 'JSON_LENGTH' => true,
        'JSON_SEARCH' => true, 'JSON_TYPE' => true, 'JSON_UNQUOTE' => true, 'JSON_VALID' => true,
        'JSON_VALUE' => true, 'LAG' => true, 'LAST_VALUE' => true, 'LCASE' => true, 'LEAD' => true,
        'LEAST' => true, 'LEFT' => true, 'LENGTH' => true, 'LN' => true, 'LOCATE' => true, 'LOG' => true,
        'LOG10' => true, 'LOG2' => true, 'LOWER' => true, 'LPAD' => true, 'LTRIM' => true, 'MAKEDATE' => true,
        'MAKETIME' => true, 'MATCH' => true, 'MAX' => true, 'MD5' => true, 'MICROSECOND' => true, 'MIN' => true,
        'MINUTE' => true, 'MOD' => true, 'MONTH' => true, 'NOW' => true, 'NTH_VALUE' => true, 'NTILE' => true,
        'NULLIF' => true, 'ORD' => true, 'PERCENT_RANK' => true, 'POSITION' => true, 'POW' => true,
        'POWER' => true, 'RANK' => true, 'REGEXP_INSTR' => true, 'REGEXP_REPLACE' => true,
        'REGEXP_SUBSTR' => true, 'REPEAT' => true, 'REPLACE' => true, 'REVERSE' => true, 'RIGHT' => true,
        'ROUND' => true, 'ROW_NUMBER' => true, 'RPAD' => true, 'RTRIM' => true, 'SECOND' => true, 'SHA1' => true,
        'SHA2' => true, 'SIGN' => true, 'SPACE' => true, 'SQRT' => true, 'STD' => true, 'STDDEV' => true,
        'STDDEV_POP' => true, 'STDDEV_SAMP' => true, 'STR_TO_DATE' => true, 'SUBDATE' => true, 'SUBSTR' => true,
        'SUBSTRING' => true, 'SUM' => true, 'TIME' => true, 'TIMEDIFF' => true, 'TIMESTAMP' => true,
        'TIMESTAMPADD' => true, 'TIMESTAMPDIFF' => true, 'TO_BASE64' => true, 'TRIM' => true, 'TRUNCATE' => true,
        'UCASE' => true, 'UNHEX' => true, 'UNIX_TIMESTAMP' => true, 'UPPER' => true, 'UTC_DATE' => true,
        'UTC_TIME' => true, 'UTC_TIMESTAMP' => true, 'UUID' => true, 'VAR_POP' => true, 'VAR_SAMP' => true,
        'VARIANCE' => true, 'WEEK' => true, 'WEEKDAY' => true, 'WEEKOFYEAR' => true, 'YEAR' => true,
    );

    /** @var array<string,bool> */
    private const NON_FUNCTION_PAREN_KEYWORDS = array(
        'FROM' => true, 'JOIN' => true, 'ON' => true, 'WHERE' => true, 'HAVING' => true,
        'IN' => true, 'EXISTS' => true, 'OVER' => true, 'WHEN' => true, 'CASE' => true,
    );

    /** @var array<string,bool> */
    private const CLAUSE_BOUNDARIES = array(
        'WHERE' => true, 'GROUP' => true, 'HAVING' => true, 'ORDER' => true, 'LIMIT' => true,
        'OFFSET' => true, 'UNION' => true, 'FOR' => true, 'LOCK' => true,
    );

    /** @param array<int,string> $placeholderTypes @return array{statement:string}|\WP_Error */
    public function validate(string $sql, array $placeholderTypes): array|\WP_Error
    {
        $analysis = $this->analyze($sql, $placeholderTypes, false);
        if ($analysis instanceof \WP_Error) {
            return $analysis;
        }
        return array('statement' => $analysis['statement']);
    }

    /** @param array<int,string> $placeholderTypes @return array<int,int>|\WP_Error */
    public function tableParameterIndexes(string $sql, array $placeholderTypes): array|\WP_Error
    {
        $analysis = $this->analyze($sql, $placeholderTypes, true);
        if ($analysis instanceof \WP_Error) {
            return $analysis;
        }
        return $analysis['table_parameter_indexes'];
    }

    /** @param array<int,string> $placeholderTypes @return array{statement:string,table_parameter_indexes:array<int,int>}|\WP_Error */
    private function analyze(string $sql, array $placeholderTypes, bool $enforceTablePlaceholders): array|\WP_Error
    {
        $tokens = $this->tokenize($sql, $placeholderTypes);
        if ($tokens instanceof \WP_Error) {
            return $tokens;
        }
        if ($tokens === array()) {
            return new \WP_Error('codi_mcp_db_query_parse', 'SQL contains no tokens.');
        }

        $index = 0;
        $statement = 'select';
        if ($this->isWord($tokens[$index] ?? null, 'EXPLAIN')) {
            $statement = 'explain';
            $index++;
        }
        if (!$this->isWord($tokens[$index] ?? null, 'SELECT')) {
            return new \WP_Error('codi_mcp_db_query_statement', 'Only SELECT and EXPLAIN SELECT statements are allowed.');
        }

        $functions = $this->validateFunctions($tokens);
        if ($functions instanceof \WP_Error) {
            return $functions;
        }

        $tableIndexes = array();
        $scope = $this->analyzeSelectScope($tokens, $index, count($tokens), $tableIndexes, $enforceTablePlaceholders);
        if ($scope instanceof \WP_Error) {
            return $scope;
        }

        return array(
            'statement' => $statement,
            'table_parameter_indexes' => array_values(array_unique($tableIndexes)),
        );
    }

    /**
     * @param array<int,array{type:string,value:string,index?:int}> $tokens
     * @param array<int,int> $tableIndexes
     */
    private function analyzeSelectScope(array $tokens, int $selectIndex, int $end, array &$tableIndexes, bool $enforceTablePlaceholders): bool|\WP_Error
    {
        if (!$this->isWord($tokens[$selectIndex] ?? null, 'SELECT')) {
            return new \WP_Error('codi_mcp_db_query_parse', 'Derived query must begin with SELECT.');
        }

        $fromIndex = $this->findTopLevelWord($tokens, $selectIndex + 1, $end, 'FROM');
        $projectionEnd = $fromIndex ?? $end;
        $projection = $this->validateProjection($tokens, $selectIndex + 1, $projectionEnd);
        if ($projection instanceof \WP_Error) {
            return $projection;
        }

        if ($fromIndex === null) {
            return true;
        }

        $sourceEnd = $this->findClauseBoundary($tokens, $fromIndex + 1, $end);
        $sourceCount = 0;
        $expectSource = true;
        $depth = 0;
        for ($i = $fromIndex + 1; $i < $sourceEnd; $i++) {
            $token = $tokens[$i];
            if ($token['value'] === '(') {
                if ($expectSource) {
                    $close = $this->matchingParen($tokens, $i, $sourceEnd);
                    if ($close === null) {
                        return new \WP_Error('codi_mcp_db_query_parse', 'Unbalanced derived-table parentheses.');
                    }
                    $nestedSelect = $i + 1;
                    if (!$this->isWord($tokens[$nestedSelect] ?? null, 'SELECT')) {
                        return new \WP_Error('codi_mcp_db_query_table_source', 'Only derived SELECT subqueries may appear as parenthesized table sources.');
                    }
                    $nested = $this->analyzeSelectScope($tokens, $nestedSelect, $close, $tableIndexes, $enforceTablePlaceholders);
                    if ($nested instanceof \WP_Error) {
                        return $nested;
                    }
                    $sourceCount++;
                    $expectSource = false;
                    $i = $close;
                    continue;
                }
                $depth++;
                continue;
            }
            if ($token['value'] === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth > 0) {
                continue;
            }

            if ($this->isJoinStart($tokens, $i, $sourceEnd)) {
                $joinIndex = $this->advanceToJoin($tokens, $i, $sourceEnd);
                if ($joinIndex !== null) {
                    $expectSource = true;
                    $i = $joinIndex;
                    continue;
                }
            }
            if ($token['value'] === ',') {
                $expectSource = true;
                continue;
            }
            if (!$expectSource) {
                continue;
            }

            if ($token['type'] === 'placeholder' && ($token['placeholder_type'] ?? '') === 'i') {
                $tableIndexes[] = (int) ($token['index'] ?? -1);
                $sourceCount++;
                $expectSource = false;
                continue;
            }

            if ($enforceTablePlaceholders) {
                return new \WP_Error('codi_mcp_db_query_table_parameter', 'Direct table sources must use %i identifier placeholders.');
            }
            if (in_array($token['type'], array('word', 'identifier'), true)) {
                $sourceCount++;
                $expectSource = false;
                continue;
            }
            return new \WP_Error('codi_mcp_db_query_table_source', 'Unsupported direct table source syntax.');
        }

        if ($sourceCount > 1 && $projection['wildcard']) {
            return new \WP_Error('codi_mcp_db_query_wildcard_join', 'Wildcard projections are not allowed across multi-source queries; select explicit columns.');
        }

        return true;
    }

    /**
     * @param array<int,array{type:string,value:string,index?:int}> $tokens
     * @return array{wildcard:bool}|\WP_Error
     */
    private function validateProjection(array $tokens, int $start, int $end): array|\WP_Error
    {
        $items = $this->splitTopLevel($tokens, $start, $end, ',');
        $wildcard = false;
        $simpleColumns = array();

        foreach ($items as [$itemStart, $itemEnd]) {
            while ($itemStart < $itemEnd && $this->isWord($tokens[$itemStart] ?? null, 'DISTINCT')) {
                $itemStart++;
            }
            if ($itemStart >= $itemEnd) {
                continue;
            }
            if ($this->isWildcardProjection($tokens, $itemStart, $itemEnd)) {
                $wildcard = true;
                continue;
            }

            $simple = $this->simpleColumnReference($tokens, $itemStart, $itemEnd);
            if ($simple !== null) {
                $simpleColumns[strtolower($simple)] = true;
            }

            foreach (array('option_value' => 'option_name', 'meta_value' => 'meta_key') as $valueColumn => $keyColumn) {
                if ($this->rangeContainsColumn($tokens, $itemStart, $itemEnd, $valueColumn) && strtolower((string) $simple) !== $valueColumn) {
                    return new \WP_Error('codi_mcp_db_query_sensitive_projection', $valueColumn . ' may not be aliased or wrapped in expressions because sensitive values must remain redactable.');
                }
            }
        }

        foreach (array('option_value' => 'option_name', 'meta_value' => 'meta_key') as $valueColumn => $keyColumn) {
            if (isset($simpleColumns[$valueColumn]) && !isset($simpleColumns[$keyColumn])) {
                return new \WP_Error('codi_mcp_db_query_sensitive_projection', $valueColumn . ' must be returned directly alongside ' . $keyColumn . ' so sensitive values can be redacted.');
            }
        }

        return array('wildcard' => $wildcard);
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function validateFunctions(array $tokens): bool|\WP_Error
    {
        $count = count($tokens);
        for ($i = 0; $i + 1 < $count; $i++) {
            $token = $tokens[$i];
            if (!in_array($token['type'], array('word', 'identifier'), true) || $tokens[$i + 1]['value'] !== '(') {
                continue;
            }
            $name = strtoupper($token['value']);
            if (isset(self::NON_FUNCTION_PAREN_KEYWORDS[$name])) {
                continue;
            }
            if (!isset(self::ALLOWED_FUNCTIONS[$name])) {
                return new \WP_Error('codi_mcp_db_query_function_not_allowed', 'sql contains a function that is not on the read-only allowlist: ' . $name . '.');
            }
        }
        return true;
    }

    /**
     * @param array<int,string> $placeholderTypes
     * @return array<int,array{type:string,value:string,index?:int,placeholder_type?:string}>|\WP_Error
     */
    private function tokenize(string $sql, array $placeholderTypes): array|\WP_Error
    {
        $tokens = array();
        $length = strlen($sql);
        $placeholderIndex = 0;
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ctype_space($char)) {
                $i++;
                continue;
            }
            if ($char === '`') {
                $end = strpos($sql, '`', $i + 1);
                if ($end === false) {
                    return new \WP_Error('codi_mcp_db_query_identifier', 'Unterminated backtick identifier.');
                }
                $tokens[] = array('type' => 'identifier', 'value' => substr($sql, $i + 1, $end - $i - 1));
                $i = $end + 1;
                continue;
            }
            if ($char === '%' && $i + 1 < $length) {
                $next = strtolower($sql[$i + 1]);
                if ($next === '%') {
                    $tokens[] = array('type' => 'symbol', 'value' => '%');
                    $i += 2;
                    continue;
                }
                if (in_array($next, array('s', 'd', 'f', 'i'), true)) {
                    $type = $placeholderTypes[$placeholderIndex] ?? $next;
                    $tokens[] = array('type' => 'placeholder', 'value' => '%' . $next, 'index' => $placeholderIndex, 'placeholder_type' => $type);
                    $placeholderIndex++;
                    $i += 2;
                    continue;
                }
            }
            if (ctype_alpha($char) || $char === '_') {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_alnum($sql[$i]) || in_array($sql[$i], array('_', '$'), true))) {
                    $i++;
                }
                $tokens[] = array('type' => 'word', 'value' => substr($sql, $start, $i - $start));
                continue;
            }
            if (ctype_digit($char)) {
                $start = $i;
                $i++;
                while ($i < $length && (ctype_digit($sql[$i]) || in_array(strtolower($sql[$i]), array('.', 'e', '+', '-'), true))) {
                    $i++;
                }
                $tokens[] = array('type' => 'number', 'value' => substr($sql, $start, $i - $start));
                continue;
            }
            if (str_contains('(),.*=<>!+-/|&^', $char)) {
                $value = $char;
                if ($i + 1 < $length && in_array($char . $sql[$i + 1], array('<=', '>=', '<>', '!=', '||', '&&'), true)) {
                    $value .= $sql[++$i];
                }
                $tokens[] = array('type' => 'symbol', 'value' => $value);
                $i++;
                continue;
            }
            return new \WP_Error('codi_mcp_db_query_parse', 'SQL contains unsupported syntax near byte ' . $i . '.');
        }
        return $tokens;
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function findTopLevelWord(array $tokens, int $start, int $end, string $word): ?int
    {
        $depth = 0;
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]['value'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['value'] === ')') {
                $depth--;
                if ($depth < 0) {
                    return null;
                }
            } elseif ($depth === 0 && $this->isWord($tokens[$i], $word)) {
                return $i;
            }
        }
        return null;
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function findClauseBoundary(array $tokens, int $start, int $end): int
    {
        $depth = 0;
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]['value'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['value'] === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $tokens[$i]['type'] === 'word' && isset(self::CLAUSE_BOUNDARIES[strtoupper($tokens[$i]['value'])])) {
                return $i;
            }
        }
        return $end;
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function matchingParen(array $tokens, int $open, int $end): ?int
    {
        $depth = 0;
        for ($i = $open; $i < $end; $i++) {
            if ($tokens[$i]['value'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['value'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                if ($depth < 0) {
                    return null;
                }
            }
        }
        return null;
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function isJoinStart(array $tokens, int $index, int $end): bool
    {
        if ($index >= $end || $tokens[$index]['type'] !== 'word') {
            return false;
        }
        return in_array(strtoupper($tokens[$index]['value']), array('JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS'), true);
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function advanceToJoin(array $tokens, int $index, int $end): ?int
    {
        for ($i = $index; $i < min($end, $index + 4); $i++) {
            if ($this->isWord($tokens[$i], 'JOIN')) {
                return $i;
            }
            if ($tokens[$i]['type'] !== 'word' || !in_array(strtoupper($tokens[$i]['value']), array('INNER', 'LEFT', 'RIGHT', 'CROSS', 'OUTER'), true)) {
                break;
            }
        }
        return null;
    }

    /**
     * @param array<int,array{type:string,value:string,index?:int}> $tokens
     * @return array<int,array{0:int,1:int}>
     */
    private function splitTopLevel(array $tokens, int $start, int $end, string $separator): array
    {
        $ranges = array();
        $depth = 0;
        $itemStart = $start;
        for ($i = $start; $i < $end; $i++) {
            if ($tokens[$i]['value'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['value'] === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $tokens[$i]['value'] === $separator) {
                $ranges[] = array($itemStart, $i);
                $itemStart = $i + 1;
            }
        }
        $ranges[] = array($itemStart, $end);
        return $ranges;
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function isWildcardProjection(array $tokens, int $start, int $end): bool
    {
        if ($end - $start === 1) {
            return $tokens[$start]['value'] === '*';
        }
        return $end - $start === 3
            && in_array($tokens[$start]['type'], array('word', 'identifier'), true)
            && $tokens[$start + 1]['value'] === '.'
            && $tokens[$start + 2]['value'] === '*';
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function simpleColumnReference(array $tokens, int $start, int $end): ?string
    {
        if ($end - $start === 1 && in_array($tokens[$start]['type'], array('word', 'identifier'), true)) {
            return $tokens[$start]['value'];
        }
        if ($end - $start === 3
            && in_array($tokens[$start]['type'], array('word', 'identifier'), true)
            && $tokens[$start + 1]['value'] === '.'
            && in_array($tokens[$start + 2]['type'], array('word', 'identifier'), true)) {
            return $tokens[$start + 2]['value'];
        }
        return null;
    }

    /** @param array<int,array{type:string,value:string,index?:int}> $tokens */
    private function rangeContainsColumn(array $tokens, int $start, int $end, string $column): bool
    {
        for ($i = $start; $i < $end; $i++) {
            if (!in_array($tokens[$i]['type'], array('word', 'identifier'), true) || strcasecmp($tokens[$i]['value'], $column) !== 0) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @param array{type:string,value:string,index?:int}|null $token */
    private function isWord(?array $token, string $word): bool
    {
        return is_array($token) && in_array($token['type'], array('word', 'identifier'), true) && strcasecmp($token['value'], $word) === 0;
    }
}
