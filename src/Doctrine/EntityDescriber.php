<?php

namespace QaTracking\CaptureBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use QaTracking\CaptureBundle\Collector\ValueNormalizer;

/** Converts entities and their values into plain data: class, identifier and scalars. */
final class EntityDescriber
{
    public function __construct(private readonly ValueNormalizer $values)
    {
    }

    /**
     * @return array{class: string, id: array<string, mixed>}|null `null` if it has no identifier yet
     */
    public function reference(EntityManagerInterface $em, object $entity): ?array
    {
        try {
            // The identity map is not consulted: an entity scheduled for deletion has already
            // left it, but keeps its identifier until the delete is executed.
            $identifier = $em->getUnitOfWork()->getEntityIdentifier($entity);
        } catch (\Throwable) {
            return null;
        }
        if ([] === $identifier) {
            return null;
        }

        $id = [];
        foreach ($identifier as $field => $value) {
            $id[$field] = $this->scalar($em, $value);
        }

        // getClassMetadata resolves the real class even if the object is a proxy.
        return ['class' => $em->getClassMetadata($entity::class)->getName(), 'id' => $id];
    }

    /** A related entity is described by its identifier; everything else, as a plain value. */
    public function scalar(EntityManagerInterface $em, mixed $value): mixed
    {
        if (\is_object($value) && !$value instanceof \DateTimeInterface && !$value instanceof \UnitEnum && !$value instanceof \Stringable) {
            try {
                if (!$em->getMetadataFactory()->isTransient($value::class) && null !== $reference = $this->reference($em, $value)) {
                    return 1 === \count($reference['id']) ? reset($reference['id']) : $reference['id'];
                }
            } catch (\Throwable) {
                // Not an entity: it is described like any other object.
            }
        }

        return $this->values->normalize($value);
    }
}
