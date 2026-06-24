<?php

declare(strict_types=1);

namespace App\BotGuard\Form;

use App\BotGuard\BotGuardIpFingerprint;
use Symfony\Component\HttpFoundation\Request;

final class BotGuardFormTokenService
{
    public const TOKEN_FIELD = '_bg_form_token';
    public const ISSUED_AT_FIELD = '_bg_form_issued_at';
    public const CONFIRMED_AT_FIELD = '_bg_form_confirmed_at';

    private const TOKEN_TTL_SECONDS = 600;

    /**
     * @var string
     */
    private $appSecret;

    public function __construct(string $appSecret)
    {
        $this->appSecret = $appSecret;
    }

    /**
     * @return array{token: string, minConfirmDelayMs: int}
     */
    public function issue(Request $request, string $formAction, int $issuedAt, int $minConfirmDelayMs): array
    {
        $issuedAt = max(1, $issuedAt);
        $minConfirmDelayMs = max(200, $minConfirmDelayMs);
        $expiresAt = time() + self::TOKEN_TTL_SECONDS;

        $payload = [
            'v' => 1,
            'exp' => $expiresAt,
            'action' => hash('sha256', $this->normalizeAction($formAction)),
            'ip' => hash('sha256', BotGuardIpFingerprint::build((string) $request->getClientIp())),
            'ua' => hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', '')))),
            'issued' => $issuedAt,
            'minDelay' => $minConfirmDelayMs,
            'rnd' => bin2hex(random_bytes(6)),
        ];

        return [
            'token' => $this->encode($payload),
            'minConfirmDelayMs' => $minConfirmDelayMs,
        ];
    }

    public function verify(
        Request $request,
        string $formAction,
        string $token,
        int $issuedAt,
        int $confirmedAt,
        int $minFillSeconds,
        int $minConfirmDelayMs
    ): ?string {
        if ('' === trim($token)) {
            return 'missing_token';
        }

        $payload = $this->decode($token);
        if (null === $payload) {
            return 'invalid_token';
        }

        $expiresAt = (int) ($payload['exp'] ?? 0);
        if ($expiresAt < time()) {
            return 'expired_token';
        }

        $expectedAction = hash('sha256', $this->normalizeAction($formAction));
        if (!hash_equals($expectedAction, (string) ($payload['action'] ?? ''))) {
            return 'action_mismatch';
        }

        $actualIpHash = hash('sha256', BotGuardIpFingerprint::build((string) $request->getClientIp()));
        if (!hash_equals((string) ($payload['ip'] ?? ''), $actualIpHash)) {
            return 'ip_mismatch';
        }

        $actualUaHash = hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', ''))));
        if (!hash_equals((string) ($payload['ua'] ?? ''), $actualUaHash)) {
            return 'ua_mismatch';
        }

        $payloadIssued = (int) ($payload['issued'] ?? 0);
        if ($payloadIssued !== $issuedAt) {
            return 'issued_mismatch';
        }

        $now = time();
        if ($issuedAt > 0 && ($now - $issuedAt) < max(1, $minFillSeconds)) {
            return 'filled_too_fast';
        }

        $tokenMinDelay = max(200, (int) ($payload['minDelay'] ?? $minConfirmDelayMs));
        if ($confirmedAt > 0 && ($confirmedAt - $issuedAt) * 1000 < $tokenMinDelay) {
            return 'confirmed_too_fast';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ('' === $encoded || '' === $signature) {
            return null;
        }

        $expected = hash_hmac('sha256', $encoded, $this->appSecret);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $json) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->appSecret);

        return $encoded.'.'.$signature;
    }

    private function normalizeAction(string $formAction): string
    {
        $formAction = trim($formAction);
        if ('' === $formAction) {
            return '/';
        }

        $path = parse_url($formAction, PHP_URL_PATH);

        return is_string($path) && '' !== $path ? $path : $formAction;
    }
}
