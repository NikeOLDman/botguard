<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\BotGuard\BotGuardLogCleaner;
use App\BotGuard\BotGuardSettingsCache;
use App\Entity\BotGuard\BotGuardRule;
use App\Entity\BotGuard\BotGuardSettings;
use App\Form\Admin\BotGuardSettingsType;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BotGuardAdminController extends AbstractController
{
    private const IMPORT_FILE_MAX_SIZE_BYTES = 1048576;
    private const IMPORT_MAX_RULES = 2000;

    /**
     * @Route("/admin/bot-guard-settings/panel", name="app_admin_bot_guard_settings_panel", methods={"GET"})
     * @IsGranted("ROLE_ADMIN")
     */
    public function settingsPanel(Request $request, EntityManagerInterface $em): Response
    {
        $settings = $this->getOrCreateSettings($em);
        $form = $this->createForm(BotGuardSettingsType::class, $settings, [
            'action' => $this->generateUrl('app_admin_bot_guard_settings_save'),
            'method' => 'POST',
        ]);

        return $this->render('admin/bot_guard/settings/page.html.twig', [
            'settings' => $settings,
            'form' => $form->createView(),
            'project_dir' => $this->getParameter('kernel.project_dir'),
            'php_binary' => PHP_BINARY,
        ]);
    }

    /**
     * @Route("/admin/bot-guard-settings/save", name="app_admin_bot_guard_settings_save", methods={"POST"})
     * @IsGranted("ROLE_ADMIN")
     */
    public function saveSettings(
        Request $request,
        EntityManagerInterface $em,
        BotGuardSettingsCache $settingsCache
    ): RedirectResponse {
        $settings = $this->getOrCreateSettings($em);

        $form = $this->createForm(BotGuardSettingsType::class, $settings);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Не удалось сохранить настройки защиты сайта. Проверьте заполнение полей.');

            return $this->redirectToSettingsIndex($request);
        }

        $em->flush();
        $settingsCache->invalidate();

        $this->addFlash('success', 'Настройки «Твердыня» сохранены. Изменения применятся в течение ~15 секунд.');

        return $this->redirectToSettingsIndex($request);
    }

    /**
     * @Route("/admin/bot-guard/cleanup", name="app_admin_bot_guard_cleanup")
     * @IsGranted("ROLE_ADMIN")
     */
    public function cleanup(Request $request, BotGuardLogCleaner $cleaner, EntityManagerInterface $em): RedirectResponse
    {
        $daysFromQuery = (int) $request->query->get('days', 0);
        $days = $daysFromQuery > 0 ? $daysFromQuery : $this->resolveRetentionDays($em);
        $deleted = $cleaner->cleanupAllOlderThanDays($days);

        $this->addFlash('success', sprintf(
            'Очистка Bot Guard выполнена. Удалено записей: всего=%d, блокировок=%d, подозрительных=%d, метрик=%d. Период хранения: %d дней.',
            $deleted['total'],
            $deleted['bot_guard_log'],
            $deleted['bot_guard_suspicious_event'],
            $deleted['bot_guard_system_metric'],
            $days
        ));

        $referer = (string) $request->headers->get('referer', '');

        if ('' !== $referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('darvin_admin_homepage');
    }

    /**
     * @Route("/admin/bot-guard/rules/export", name="app_admin_bot_guard_rule_export", methods={"GET"})
     * @IsGranted("ROLE_ADMIN")
     */
    public function exportRules(EntityManagerInterface $em): Response
    {
        /** @var BotGuardRule[] $rules */
        $rules = $em->getRepository(BotGuardRule::class)->findBy([], ['priority' => 'ASC', 'id' => 'ASC']);
        $payload = [
            'format' => 'bot_guard_rules_v1',
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'rules' => [],
        ];

        foreach ($rules as $rule) {
            $payload['rules'][] = [
                'name' => $rule->getName(),
                'type' => $rule->getType(),
                'pattern' => $rule->getPattern(),
                'uriPattern' => $rule->getUriPattern(),
                'active' => $rule->isActive(),
                'priority' => $rule->getPriority(),
            ];
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (false === $json) {
            throw new \RuntimeException('Не удалось сформировать JSON для экспорта правил.');
        }

        $filename = sprintf('bot-guard-rules-%s.json', (new \DateTimeImmutable())->format('Ymd-His'));
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    /**
     * @Route("/admin/bot-guard/rules/import", name="app_admin_bot_guard_rule_import", methods={"POST"})
     * @IsGranted("ROLE_ADMIN")
     */
    public function importRules(Request $request, EntityManagerInterface $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('bot_guard_rule_import', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Импорт правил BotGuard отклонен: недействительный CSRF-токен.');

            return $this->redirectToRulesPage($request);
        }

        $file = $request->files->get('rules_file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('danger', 'Импорт правил BotGuard не выполнен: не удалось прочитать файл.');

            return $this->redirectToRulesPage($request);
        }

        if ((int) $file->getSize() > self::IMPORT_FILE_MAX_SIZE_BYTES) {
            $this->addFlash('danger', 'Импорт правил BotGuard не выполнен: файл слишком большой (максимум 1 МБ).');

            return $this->redirectToRulesPage($request);
        }

        $raw = (string) file_get_contents($file->getPathname());
        if ('' === trim($raw)) {
            $this->addFlash('danger', 'Импорт правил BotGuard не выполнен: файл пустой.');

            return $this->redirectToRulesPage($request);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $importRules = $this->normalizeImportedRules($decoded);
            $connection = $em->getConnection();
            $connection->beginTransaction();
            try {
                $em->createQuery(sprintf('DELETE FROM %s rule', BotGuardRule::class))->execute();

                foreach ($importRules as $item) {
                    $rule = (new BotGuardRule())
                        ->setName($item['name'])
                        ->setType($item['type'])
                        ->setPattern($item['pattern'])
                        ->setUriPattern($item['uriPattern'])
                        ->setActive($item['active'])
                        ->setPriority($item['priority']);

                    $em->persist($rule);
                }

                $em->flush();
                $connection->commit();
            } catch (\Throwable $e) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $e;
            }
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf(
                'Импорт правил BotGuard не выполнен: %s',
                $e->getMessage()
            ));

            return $this->redirectToRulesPage($request);
        }

        $this->addFlash('success', sprintf('Импорт правил BotGuard завершен: загружено %d правил.', count($importRules)));

        return $this->redirectToRulesPage($request);
    }

    private function resolveRetentionDays(EntityManagerInterface $em): int
    {
        return max(1, $this->getOrCreateSettings($em)->getRetentionDays());
    }

    private function getOrCreateSettings(EntityManagerInterface $em): BotGuardSettings
    {
        /** @var BotGuardSettings|null $settings */
        $settings = $em->getRepository(BotGuardSettings::class)->findOneBy([], ['id' => 'ASC']);

        if ($settings instanceof BotGuardSettings) {
            return $settings;
        }

        $settings = (new BotGuardSettings())
            ->setEnabled(true)
            ->setBlockEmptyUserAgent(true)
            ->setLoggingEnabled(true);

        $em->persist($settings);
        $em->flush();

        return $settings;
    }

    /**
     * @param mixed $decoded
     *
     * @return array<int, array{name: string, type: string, pattern: string, uriPattern: ?string, active: bool, priority: int}>
     */
    private function normalizeImportedRules($decoded): array
    {
        if (!is_array($decoded)) {
            throw new \RuntimeException('Некорректный формат JSON: ожидается объект.');
        }

        if (!isset($decoded['format']) || 'bot_guard_rules_v1' !== $decoded['format']) {
            throw new \RuntimeException('Некорректный формат файла: ожидается bot_guard_rules_v1.');
        }

        if (!isset($decoded['rules']) || !is_array($decoded['rules'])) {
            throw new \RuntimeException('Некорректный формат файла: отсутствует массив rules.');
        }

        $count = count($decoded['rules']);
        if ($count > self::IMPORT_MAX_RULES) {
            throw new \RuntimeException(sprintf('Слишком много правил в файле (максимум %d).', self::IMPORT_MAX_RULES));
        }

        $normalized = [];
        $allowedTypes = [
            BotGuardRule::TYPE_USER_AGENT_CONTAINS,
            BotGuardRule::TYPE_USER_AGENT_REGEX,
            BotGuardRule::TYPE_IP_EXACT,
            BotGuardRule::TYPE_URI_CONTAINS,
            BotGuardRule::TYPE_COOKIE_REQUIRED,
        ];

        foreach ($decoded['rules'] as $index => $row) {
            $rowNumber = $index + 1;

            if (!is_array($row)) {
                throw new \RuntimeException(sprintf('Правило #%d имеет некорректный формат.', $rowNumber));
            }

            $name = trim((string) ($row['name'] ?? ''));
            $type = trim((string) ($row['type'] ?? ''));
            $pattern = trim((string) ($row['pattern'] ?? ''));
            $uriPatternRaw = $row['uriPattern'] ?? null;
            $priorityRaw = $row['priority'] ?? 100;
            $activeRaw = $row['active'] ?? true;

            if ('' === $name) {
                throw new \RuntimeException(sprintf('В правиле #%d не заполнено поле name.', $rowNumber));
            }
            if (mb_strlen($name) > 255) {
                throw new \RuntimeException(sprintf('В правиле #%d поле name длиннее 255 символов.', $rowNumber));
            }

            if (!in_array($type, $allowedTypes, true)) {
                throw new \RuntimeException(sprintf('В правиле #%d указан неизвестный type: %s.', $rowNumber, $type));
            }

            if ('' === $pattern) {
                throw new \RuntimeException(sprintf('В правиле #%d не заполнено поле pattern.', $rowNumber));
            }
            if (mb_strlen($pattern) > 255) {
                throw new \RuntimeException(sprintf('В правиле #%d поле pattern длиннее 255 символов.', $rowNumber));
            }

            if (BotGuardRule::TYPE_USER_AGENT_REGEX === $type) {
                $this->assertRegexIsValid($pattern, $rowNumber);
            }

            $uriPattern = null;
            if (null !== $uriPatternRaw) {
                $uriPatternCandidate = trim((string) $uriPatternRaw);
                if ('' !== $uriPatternCandidate) {
                    if (mb_strlen($uriPatternCandidate) > 255) {
                        throw new \RuntimeException(sprintf('В правиле #%d поле uriPattern длиннее 255 символов.', $rowNumber));
                    }
                    $uriPattern = $uriPatternCandidate;
                }
            }

            if (is_string($priorityRaw) && !preg_match('/^-?\d+$/', trim($priorityRaw))) {
                throw new \RuntimeException(sprintf('В правиле #%d поле priority должно быть целым числом.', $rowNumber));
            }
            if (!is_int($priorityRaw) && !is_string($priorityRaw)) {
                throw new \RuntimeException(sprintf('В правиле #%d поле priority должно быть целым числом.', $rowNumber));
            }
            $priority = (int) $priorityRaw;

            if ($priority < -100000 || $priority > 100000) {
                throw new \RuntimeException(sprintf('В правиле #%d поле priority вне допустимого диапазона.', $rowNumber));
            }

            $active = $this->normalizeBoolean($activeRaw, $rowNumber);

            $normalized[] = [
                'name' => $name,
                'type' => $type,
                'pattern' => $pattern,
                'uriPattern' => $uriPattern,
                'active' => $active,
                'priority' => $priority,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $activeRaw
     */
    private function normalizeBoolean($activeRaw, int $rowNumber): bool
    {
        if (is_bool($activeRaw)) {
            return $activeRaw;
        }

        if (is_int($activeRaw)) {
            if (0 === $activeRaw || 1 === $activeRaw) {
                return 1 === $activeRaw;
            }

            throw new \RuntimeException(sprintf('В правиле #%d поле active должно быть true/false.', $rowNumber));
        }

        if (is_string($activeRaw)) {
            $normalized = strtolower(trim($activeRaw));
            if ('true' === $normalized || '1' === $normalized) {
                return true;
            }
            if ('false' === $normalized || '0' === $normalized) {
                return false;
            }
        }

        throw new \RuntimeException(sprintf('В правиле #%d поле active должно быть true/false.', $rowNumber));
    }

    private function assertRegexIsValid(string $pattern, int $rowNumber): void
    {
        set_error_handler(static function (): bool {
            return true;
        });

        try {
            if (false === preg_match($pattern, 'test')) {
                throw new \RuntimeException(sprintf('В правиле #%d регулярное выражение pattern некорректно.', $rowNumber));
            }
        } finally {
            restore_error_handler();
        }
    }

    private function redirectToRulesPage(Request $request): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer', '');
        if ('' !== $referer) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('darvin_admin_homepage');
    }

    private function redirectToSettingsIndex(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('admin_bot_guard_settings.'.$request->getLocale());
    }
}

