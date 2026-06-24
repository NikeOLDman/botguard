<?php

declare(strict_types=1);

namespace App\Controller;

use App\BotGuard\BotGuardDecider;
use App\BotGuard\BotGuardJsChallengeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class BotGuardChallengeController extends AbstractController
{
    /**
     * @var BotGuardJsChallengeService
     */
    private $jsChallenge;

    /**
     * @var BotGuardDecider
     */
    private $decider;

    public function __construct(BotGuardJsChallengeService $jsChallenge, BotGuardDecider $decider)
    {
        $this->jsChallenge = $jsChallenge;
        $this->decider = $decider;
    }

    /**
     * @Route("/_bot-guard/verify", name="bot_guard_verify", methods={"POST"})
     */
    public function verify(Request $request): JsonResponse
    {
        $nonce = trim((string) $request->request->get('nonce', ''));
        $returnPath = $this->jsChallenge->verify($request, $nonce);

        if (null === $returnPath) {
            return new JsonResponse(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $response = new JsonResponse(['ok' => true, 'redirect' => $returnPath]);
        $response->headers->setCookie($this->createAccessCookie($request));
        $response->headers->setCookie($this->createJsChallengeCookie($request));

        return $response;
    }

    private function createAccessCookie(Request $request): Cookie
    {
        return Cookie::create(
            BotGuardDecider::ACCESS_COOKIE_NAME,
            $this->decider->buildAccessCookieValue($request),
            new \DateTimeImmutable('+'.(3600 * 6).' seconds'),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    private function createJsChallengeCookie(Request $request): Cookie
    {
        return Cookie::create(
            BotGuardDecider::JS_COOKIE_NAME,
            $this->decider->buildJsCookieValue($request),
            new \DateTimeImmutable('+900 seconds'),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }
}
