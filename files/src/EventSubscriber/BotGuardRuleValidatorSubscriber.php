<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\BotGuardRulePatternValidator;
use App\Entity\BotGuard\BotGuardRule;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;

final class BotGuardRuleValidatorSubscriber implements EventSubscriber
{
    public function getSubscribedEvents(): array
    {
        return [
            Events::prePersist,
            Events::preUpdate,
        ];
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->validate($args);
    }

    public function preUpdate(LifecycleEventArgs $args): void
    {
        $this->validate($args);
    }

    private function validate(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        if (!$entity instanceof BotGuardRule) {
            return;
        }

        BotGuardRulePatternValidator::assertValid($entity->getType(), $entity->getPattern());
    }
}
