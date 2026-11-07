<?php

declare(strict_types=1);

namespace CodiMcp\Core\Abilities;

/** A model-facing copy; native ability validation schemas remain unchanged. */
final class SchemaPresentation
{
    public static function normalize(mixed $value): mixed
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            return $value;
        }
        $fields = (array) $value;
        foreach ($fields as $key => $child) {
            if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
                if (!is_array($child) && !$child instanceof \stdClass) {
                    continue;
                }
                $schemas = (array) $child;
                if ($key === 'properties' && $schemas === []) {
                    unset($fields[$key]);
                    continue;
                }
                foreach ($schemas as $name => $schema) {
                    $schemas[$name] = self::normalize($schema);
                }
                $fields[$key] = $child instanceof \stdClass ? (object) $schemas : $schemas;
            } elseif (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true) && is_array($child)) {
                $fields[$key] = array_map([self::class, 'normalize'], $child);
            } elseif (in_array($key, ['items', 'additionalItems', 'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'contains', 'not', 'if', 'then', 'else'], true)) {
                $fields[$key] = self::normalize($child);
            }
            // Defaults, enums, examples, and extension data are values, not schemas.
        }
        return $value instanceof \stdClass ? (object) $fields : $fields;
    }
}
