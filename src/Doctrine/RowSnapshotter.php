<?php

namespace QaTracking\CaptureBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use QaTracking\CaptureBundle\Masking\FieldMasker;

/**
 * Reads from the database the rows of the entities involved in a report.
 *
 * They are read at report time, not on every request: storing full rows for
 * every request would be expensive, and only seconds pass between QA seeing the
 * problem and reporting it. Whatever did change during the requests is kept in their `changes`.
 *
 * It is a `SELECT *` by primary key and not an ORM hydration: we want the row
 * exactly as it is in the table, with its column names, and without triggering
 * cascading loads.
 */
final class RowSnapshotter
{
    private const WRITE_OPS = ['insert', 'update', 'delete'];

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly FieldMasker $masker,
        private readonly int $maxRows,
        private readonly bool $includeReadRows,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $contexts
     *
     * @return array{rows: list<array<string, mixed>>, omitted: int, capturedAt: int}
     */
    public function snapshot(array $contexts): array
    {
        $references = $this->collect($contexts);

        // First the screen's entity, then what was modified, and last what was only read.
        usort($references, fn (array $a, array $b) => $this->rank($a) <=> $this->rank($b));

        $rows = [];
        $omitted = 0;
        foreach ($references as $reference) {
            if (2 === $this->rank($reference) && !$this->includeReadRows) {
                continue;
            }
            if (\count($rows) >= $this->maxRows) {
                ++$omitted;
                continue;
            }
            $rows[] = $this->row($reference);
        }

        return ['rows' => $rows, 'omitted' => $omitted, 'capturedAt' => (int) round(microtime(true) * 1000)];
    }

    /**
     * Merges the references from all requests: the same entity appears only once.
     *
     * @param list<array<string, mixed>> $contexts
     *
     * @return list<array{class: string, id: array<string, mixed>, primary: bool, ops: list<string>}>
     */
    private function collect(array $contexts): array
    {
        $merged = [];
        foreach ($contexts as $context) {
            foreach ($context['entities'] ?? [] as $entity) {
                if (!\is_string($entity['class'] ?? null) || !\is_array($entity['id'] ?? null)) {
                    continue;
                }
                $key = $entity['class'].'#'.json_encode($entity['id']);
                $merged[$key] ??= ['class' => $entity['class'], 'id' => $entity['id'], 'primary' => false, 'ops' => []];
                $merged[$key]['primary'] = $merged[$key]['primary'] || !empty($entity['primary']);
                $merged[$key]['ops'] = array_values(array_unique([...$merged[$key]['ops'], ...($entity['ops'] ?? [])]));
            }
        }

        return array_values($merged);
    }

    /** @param array{primary: bool, ops: list<string>} $reference */
    private function rank(array $reference): int
    {
        if ($reference['primary']) {
            return 0;
        }

        return [] !== array_intersect($reference['ops'], self::WRITE_OPS) ? 1 : 2;
    }

    /**
     * @param array{class: string, id: array<string, mixed>, primary: bool, ops: list<string>} $reference
     *
     * @return array<string, mixed>
     */
    private function row(array $reference): array
    {
        $row = [...$reference, 'status' => 'ok', 'tables' => []];

        if ($this->masker->isExcluded($reference['class'])) {
            $row['status'] = 'excluded';

            return $row;
        }

        try {
            $em = $this->registry->getManagerForClass($reference['class']);
            if (!$em instanceof EntityManagerInterface) {
                $row['status'] = 'error';

                return $row;
            }

            $metadata = $em->getClassMetadata($reference['class']);
            // With JOINED inheritance the entity is spread across the table of each parent class and its own.
            $classes = $metadata->isInheritanceTypeJoined() ? [...$metadata->parentClasses, $reference['class']] : [$reference['class']];

            foreach (array_unique($classes) as $class) {
                $tableMetadata = $em->getClassMetadata($class);
                $data = $this->fetch($em, $metadata, $tableMetadata, $reference['id']);
                if (null !== $data) {
                    $row['tables'][] = [
                        'table' => $tableMetadata->getTableName(),
                        // The column order is kept separately: a JSON value stored in MySQL does not preserve it.
                        'columns' => array_keys($data),
                        'data' => $this->masker->mask($reference['class'], $data, static function (string $column) use ($tableMetadata): ?string {
                            try {
                                return $tableMetadata->getFieldForColumn($column);
                            } catch (\Throwable) {
                                return null;
                            }
                        }),
                    ];
                }
            }

            if ([] === $row['tables']) {
                $row['status'] = 'not_found';
            }
        } catch (\Throwable) {
            $row['status'] = 'error';
            $row['tables'] = [];
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $id
     *
     * @return array<string, mixed>|null
     */
    private function fetch(EntityManagerInterface $em, ClassMetadata $entity, ClassMetadata $table, array $id): ?array
    {
        $connection = $em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $em->getConfiguration()->getQuoteStrategy();

        $columns = $quote->getIdentifierColumnNames($entity, $platform);
        $where = [];
        $params = [];
        $types = [];
        foreach (array_values($entity->getIdentifierFieldNames()) as $index => $field) {
            if (!\array_key_exists($field, $id) || !isset($columns[$index])) {
                return null;
            }
            $where[] = $columns[$index].' = ?';
            $params[] = $id[$field];
            // With the Doctrine type, a binary UUID or an integer is converted the same way as when saving.
            if ($entity->hasField($field) && null !== $type = $entity->getTypeOfField($field)) {
                $types[$index] = $type;
            }
        }
        if ([] === $where) {
            return null;
        }

        $sql = \sprintf('SELECT * FROM %s WHERE %s', $quote->getTableName($table, $platform), implode(' AND ', $where));
        $data = $connection->fetchAssociative($sql, $params, $types);

        return false === $data ? null : $data;
    }
}
