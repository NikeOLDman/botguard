<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\BotGuard\BotGuardLogCleaner;
use App\Entity\BotGuard\BotGuardSettings;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class BotGuardAdminController extends AbstractController
{
    /**
     * @Route("/admin/bot-guard/cleanup", name="app_admin_bot_guard_cleanup")
     * @IsGranted("ROLE_ADMIN")
     */
    public function cleanup(Request $request, BotGuardLogCleaner $cleaner, EntityManagerInterface $em): RedirectResponse
    {
        $daysFromQuery = (int) $request->query->get('days', 0);
        $days = $daysFromQuery > 0 ? $daysFromQuery : $this->resolveRetentionDays($em);
        $deleted = $cleaner->cleanupOlderThanDays($days);

        $this->addFlash('success', sprintf('Очистка Bot Guard выполнена. Удалено записей: %d. Период хранения: %d дней.', $deleted, $days));

        $referer = (string) $request->headers->get('referer', '');

        if ('' !== $referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('darvin_admin_homepage');
    }

    private function resolveRetentionDays(EntityManagerInterface $em): int
    {
        /** @var BotGuardSettings|null $settings */
        $settings = $em->getRepository(BotGuardSettings::class)->findOneBy([], ['id' => 'ASC']);

        if (!$settings instanceof BotGuardSettings) {
            return 60;
        }

        return max(1, $settings->getRetentionDays());
    }
}

