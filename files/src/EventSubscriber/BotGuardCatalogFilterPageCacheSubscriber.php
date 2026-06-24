<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\BotGuardCatalogFilterPageRegistry;
use Darvin\ECommerceBundle\Entity\CatalogFilteredInterface;
use Darvin\ECommerceBundle\Entity\CatalogFilteredTranslation;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;

final class BotGuardCatalogFilterPageCacheSubscriber implements EventSubscriber
{
    /**
     * @var BotGuardCatalogFilterPageRegistry
     */
    private $registry;

    public function __construct(BotGuardCatalogFilterPageRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::postRemove,
        ];
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->invalidateIfNeeded($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->invalidateIfNeeded($args);
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->invalidateIfNeeded($args);
    }

    private function invalidateIfNeeded(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        if ($entity instanceof CatalogFilteredInterface || $entity instanceof CatalogFilteredTranslation) {
            $this->registry->invalidate();
        }
    }
}
