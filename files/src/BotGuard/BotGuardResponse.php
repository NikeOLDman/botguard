<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BotGuardResponse
{
    public static function rateLimited(Request $request): Response
    {
        $unavailable = (bool) $request->attributes->get(BotGuardRateLimiter::UNAVAILABLE_ATTRIBUTE, false);
        $status = $unavailable ? 503 : 429;
        $headers = [
            'Retry-After' => (string) max(1, (int) $request->attributes->get(BotGuardRateLimiter::RETRY_AFTER_ATTRIBUTE, 60)),
            'Cache-Control' => 'no-store, private',
            'X-Bot-Guard' => $unavailable ? 'unavailable' : 'rate-limit',
        ];
        if (0 === strpos($request->getPathInfo(), '/_bot-guard/')) {
            return new JsonResponse(['ok' => false, 'error' => $unavailable ? 'temporarily_unavailable' : 'rate_limit'], $status, $headers);
        }

        return new Response($unavailable ? 'Сервис временно недоступен. Повторите запрос позже.' : 'Слишком много запросов. Повторите запрос позже.', $status, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
