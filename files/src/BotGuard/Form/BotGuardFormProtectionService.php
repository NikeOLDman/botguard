<?php

declare(strict_types=1);

namespace App\BotGuard\Form;

use App\BotGuard\BotGuardRateLimiter;
use Darvin\Utils\HttpFoundation\AjaxResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BotGuardFormProtectionService
{
    public const ROUTE_ORDER_SUBMIT = 'darvin_order_submit';
    public const ROUTE_CHECKOUT = 'darvin_ecommerce_checkout';

    /**
     * @var BotGuardFormSettingsProvider
     */
    private $settings;

    /**
     * @var BotGuardFormTokenService
     */
    private $tokenService;

    /**
     * @var BotGuardFormBlocklistMatcher
     */
    private $blocklist;

    /**
     * @var BotGuardRateLimiter
     */
    private $rateLimiter;

    public function __construct(
        BotGuardFormSettingsProvider $settings,
        BotGuardFormTokenService $tokenService,
        BotGuardFormBlocklistMatcher $blocklist,
        BotGuardRateLimiter $rateLimiter
    ) {
        $this->settings = $settings;
        $this->tokenService = $tokenService;
        $this->blocklist = $blocklist;
        $this->rateLimiter = $rateLimiter;
    }

    public function isProtectedRequest(Request $request): bool
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            return false;
        }

        $route = (string) $request->attributes->get('_route', '');
        if (self::ROUTE_ORDER_SUBMIT === $route) {
            return true;
        }

        $data = $this->settings->getData();

        return !empty($data['protectCheckout']) && self::ROUTE_CHECKOUT === $route;
    }

    public function validate(Request $request): ?string
    {
        if (!$this->settings->isEnabled()) {
            return null;
        }

        $settings = $this->settings->getData();

        if (!empty($settings['rateLimitEnabled'])
            && $this->rateLimiter->isExceeded(
                $request,
                'form',
                (int) $settings['rateLimitMaxRequests'],
                (int) $settings['rateLimitWindowSeconds'],
                false
            )
        ) {
            return 'rate_limit';
        }

        if (!empty($settings['checkHoneypot']) && $this->isHoneypotFilled($request)) {
            return 'honeypot';
        }

        $name = $this->extractFieldValue($request, 'name');
        $email = $this->extractFieldValue($request, 'email');

        if ($this->blocklist->isBlockedName($name, (string) ($settings['blockedNames'] ?? ''))) {
            return 'blocked_name';
        }

        if ($this->blocklist->isBlockedEmail($email, (string) ($settings['blockedEmails'] ?? ''))) {
            return 'blocked_email';
        }

        $token = (string) $request->request->get(BotGuardFormTokenService::TOKEN_FIELD, '');
        $issuedAt = (int) $request->request->get(BotGuardFormTokenService::ISSUED_AT_FIELD, 0);
        $confirmedAt = (int) $request->request->get(BotGuardFormTokenService::CONFIRMED_AT_FIELD, 0);
        $formAction = (string) $request->getPathInfo();

        return $this->tokenService->verify(
            $request,
            $formAction,
            $token,
            $issuedAt,
            $confirmedAt,
            (int) ($settings['minFillSeconds'] ?? 3),
            (int) ($settings['minConfirmDelayMs'] ?? 400)
        );
    }

    public function createRejectResponse(Request $request, string $reason): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new AjaxResponse('', false, 'form_shield.rejected');
        }

        return new Response('', Response::HTTP_FORBIDDEN);
    }

    public function shouldLog(): bool
    {
        return !empty($this->settings->getData()['loggingEnabled']);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublicConfig(): array
    {
        $settings = $this->settings->getData();

        if (empty($settings['enabled'])) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'protectCheckout' => !empty($settings['protectCheckout']),
            'minConfirmDelayMs' => (int) ($settings['minConfirmDelayMs'] ?? 400),
            'tokenUrl' => '/_bot-guard/form-token',
            'tokenField' => BotGuardFormTokenService::TOKEN_FIELD,
            'issuedAtField' => BotGuardFormTokenService::ISSUED_AT_FIELD,
            'confirmedAtField' => BotGuardFormTokenService::CONFIRMED_AT_FIELD,
            'logoUrl' => '/assets/images/bot-guard/logo.png',
            'brandName' => 'Твердыня',
        ];
    }

    private function isHoneypotFilled(Request $request): bool
    {
        return '' !== trim($this->extractFieldValue($request, 'title'));
    }

    private function extractFieldValue(Request $request, string $field): string
    {
        $direct = $request->request->get($field);
        if (is_string($direct) && '' !== trim($direct)) {
            return trim($direct);
        }

        $bag = $request->request->all();
        $value = $this->findNestedValue($bag, $field);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return mixed|null
     */
    private function findNestedValue(array $data, string $field)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $nested = $this->findNestedValue($value, $field);
                if (null !== $nested && '' !== $nested) {
                    return $nested;
                }
                continue;
            }

            if ((string) $key === $field && is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }
}
