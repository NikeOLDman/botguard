<?php

declare(strict_types=1);

namespace App\Controller;

use App\BotGuard\Form\BotGuardFormProtectionService;
use App\BotGuard\Form\BotGuardFormSettingsProvider;
use App\BotGuard\Form\BotGuardFormTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class BotGuardFormTokenController extends AbstractController
{
    /**
     * @var BotGuardFormSettingsProvider
     */
    private $settings;

    /**
     * @var BotGuardFormTokenService
     */
    private $tokenService;

    public function __construct(BotGuardFormSettingsProvider $settings, BotGuardFormTokenService $tokenService)
    {
        $this->settings = $settings;
        $this->tokenService = $tokenService;
    }

    /**
     * @Route("/_bot-guard/form-token", name="bot_guard_form_token", methods={"POST"})
     */
    public function issue(Request $request): JsonResponse
    {
        if (!$this->settings->isEnabled()) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $data = $this->settings->getData();
        $formAction = trim((string) $request->request->get('formAction', ''));
        $issuedAt = (int) $request->request->get(BotGuardFormTokenService::ISSUED_AT_FIELD, 0);

        if ('' === $formAction || $issuedAt < 1) {
            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $issued = $this->tokenService->issue(
            $request,
            $formAction,
            $issuedAt,
            (int) ($data['minConfirmDelayMs'] ?? 400)
        );

        return new JsonResponse([
            'ok' => true,
            'token' => $issued['token'],
            'minConfirmDelayMs' => $issued['minConfirmDelayMs'],
        ]);
    }
}
