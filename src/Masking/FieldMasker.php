<?php

namespace QaTracking\CaptureBundle\Masking;

use QaTracking\CaptureBundle\Collector\ValueNormalizer;

/**
 * Decides which data of an entity may be sent to the tracker.
 *
 * Three levels, from the broadest to the strictest:
 * - fields whose name looks like a credential are always masked;
 * - additional fields can be masked per entity;
 * - an entity can be declared "only these fields" or excluded entirely.
 *
 * A class's rules also apply to its subclasses.
 */
final class FieldMasker
{
    /**
     * @param array<string, list<string>> $maskedFields class => fields or columns to mask
     * @param array<string, list<string>> $allowedFields class => the only visible fields or columns
     * @param list<string>                $excludedEntities classes whose data is not sent
     */
    public function __construct(
        private readonly ValueNormalizer $values,
        private readonly array $maskedFields,
        private readonly array $allowedFields,
        private readonly array $excludedEntities,
    ) {
    }

    public function isExcluded(string $class): bool
    {
        foreach ($this->excludedEntities as $excluded) {
            if (is_a($class, $excluded, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param string|null $column column name, when it differs from the field */
    public function isMasked(string $class, string $field, ?string $column = null): bool
    {
        $names = array_map('strtolower', array_filter([$field, $column], static fn ($name) => null !== $name && '' !== $name));

        $allowed = $this->rulesFor($class, $this->allowedFields);
        if (null !== $allowed) {
            return [] === array_intersect($names, $allowed);
        }

        $masked = $this->rulesFor($class, $this->maskedFields);
        if (null !== $masked && [] !== array_intersect($names, $masked)) {
            return true;
        }

        foreach ($names as $name) {
            if ($this->values->isSensitiveKey($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>                $values   field or column => value
     * @param (callable(string): ?string)|null    $fieldOf  maps a column to its field
     *
     * @return array<string, mixed>
     */
    public function mask(string $class, array $values, ?callable $fieldOf = null): array
    {
        $result = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            $field = null !== $fieldOf ? $fieldOf($name) : null;
            $result[$name] = $this->isMasked($class, $field ?? $name, $name) ? ValueNormalizer::MASK : $this->values->normalize($value);
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $rules
     *
     * @return list<string>|null lowercase names, or `null` if the class has no rules
     */
    private function rulesFor(string $class, array $rules): ?array
    {
        $found = null;
        foreach ($rules as $ruleClass => $fields) {
            if (is_a($class, $ruleClass, true)) {
                $found = [...($found ?? []), ...array_map('strtolower', $fields)];
            }
        }

        return $found;
    }
}
