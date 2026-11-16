<?php

namespace QaTracking\CaptureBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use QaTracking\CaptureBundle\Collector\RequestContextCollector;
use QaTracking\CaptureBundle\Masking\FieldMasker;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records which Doctrine entities are involved in each request.
 *
 * - On read (`postLoad`) it stores only the reference: class and identifier. It is
 *   cheap, so it does not matter if a list loads hundreds of entities.
 * - On write (`onFlush`) it also stores what changed, before and after, and the
 *   data of what is deleted, because that row will no longer exist at report time.
 * - The entities the controller receives as arguments are marked as
 *   primary: they are "invoice 23" on the screen.
 *
 * The event parameters are untyped so they accept the classes of
 * Doctrine ORM 2 and 3.
 */
final class EntityTracker implements ResetInterface
{
    /** @var list<array{EntityManagerInterface, object}> inserts whose identifier is known only after the flush */
    private array $pendingInserts = [];

    public function __construct(
        private readonly RequestContextCollector $collector,
        private readonly EntityDescriber $describer,
        private readonly FieldMasker $masker,
        private readonly bool $enabled,
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    /** @param \Doctrine\ORM\Event\PostLoadEventArgs $args */
    public function postLoad($args): void
    {
        if (!$this->enabled || !$this->collector->isActive()) {
            return;
        }

        try {
            $reference = $this->describer->reference($args->getObjectManager(), $args->getObject());
            if (null !== $reference) {
                $this->collector->addEntity($reference['class'], $reference['id'], 'read');
            }
        } catch (\Throwable) {
        }
    }

    /** @param \Doctrine\ORM\Event\OnFlushEventArgs $args */
    public function onFlush($args): void
    {
        if (!$this->enabled || !$this->collector->isActive()) {
            return;
        }

        try {
            $em = $args->getObjectManager();
            $unitOfWork = $em->getUnitOfWork();

            foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
                $this->pendingInserts[] = [$em, $entity];
            }

            foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
                if (null === $reference = $this->describer->reference($em, $entity)) {
                    continue;
                }

                $fields = [];
                foreach ($unitOfWork->getEntityChangeSet($entity) as $field => $change) {
                    $fields[$field] = $this->masker->isMasked($reference['class'], $field)
                        ? ['old' => '***', 'new' => '***']
                        : ['old' => $this->describer->scalar($em, $change[0] ?? null), 'new' => $this->describer->scalar($em, $change[1] ?? null)];
                }

                $this->collector->addEntity($reference['class'], $reference['id'], 'update');
                $this->collector->addChange(['op' => 'update', 'class' => $reference['class'], 'id' => $reference['id'], 'fields' => $fields]);
            }

            foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
                if (null === $reference = $this->describer->reference($em, $entity)) {
                    continue;
                }

                $data = [];
                if (!$this->masker->isExcluded($reference['class'])) {
                    foreach ($unitOfWork->getOriginalEntityData($entity) as $field => $value) {
                        $data[$field] = $this->describer->scalar($em, $value);
                    }
                    $data = $this->masker->mask($reference['class'], $data);
                }

                $this->collector->addEntity($reference['class'], $reference['id'], 'delete');
                $this->collector->addChange(['op' => 'delete', 'class' => $reference['class'], 'id' => $reference['id'], 'data' => $data]);
            }
        } catch (\Throwable) {
        }
    }

    /** @param \Doctrine\ORM\Event\PostFlushEventArgs $args */
    public function postFlush($args): void
    {
        $pending = $this->pendingInserts;
        $this->pendingInserts = [];

        if (!$this->enabled || !$this->collector->isActive()) {
            return;
        }

        foreach ($pending as [$em, $entity]) {
            try {
                if (null !== $reference = $this->describer->reference($em, $entity)) {
                    $this->collector->addEntity($reference['class'], $reference['id'], 'insert');
                    $this->collector->addChange(['op' => 'insert', 'class' => $reference['class'], 'id' => $reference['id']]);
                }
            } catch (\Throwable) {
            }
        }
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$this->enabled || null === $this->registry || !$this->collector->isActive()) {
            return;
        }

        try {
            // `data` is where API Platform puts the resource it resolved for the operation.
            $candidates = [...$event->getArguments(), $event->getRequest()->attributes->get('data')];
            foreach ($candidates as $candidate) {
                if (!\is_object($candidate)) {
                    continue;
                }
                $em = $this->registry->getManagerForClass($candidate::class);
                if ($em instanceof EntityManagerInterface && null !== $reference = $this->describer->reference($em, $candidate)) {
                    $this->collector->addEntity($reference['class'], $reference['id'], 'read', true);
                }
            }
        } catch (\Throwable) {
        }
    }

    public function reset(): void
    {
        $this->pendingInserts = [];
    }
}
