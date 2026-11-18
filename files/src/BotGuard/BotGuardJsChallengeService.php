<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;

/** Signed, short-lived proof of work. Issuing a challenge writes no cache files. */
final class BotGuardJsChallengeService
{
    public const VERIFY_PATH = '/_bot-guard/verify';
    private $appSecret;
    private $rateLimiter;
    private $difficulty;

    public function __construct(string $appSecret, BotGuardRateLimiter $rateLimiter, int $difficulty = 16)
    {
        $this->appSecret = $appSecret;
        $this->rateLimiter = $rateLimiter;
        $this->difficulty = max(8, min(20, $difficulty));
    }

    public function issue(Request $request, string $returnPath, int $minDelayMs): array
    {
        $returnPath = $this->sanitizeReturnPath($returnPath);
        $payload = [
            'v' => 3,
            'iat' => microtime(true),
            'exp' => time() + 600,
            'ip' => hash('sha256', BotGuardIpFingerprint::normalize((string) $request->getClientIp())),
            'ua' => hash('sha256', strtolower(trim((string) $request->headers->get('User-Agent', '')))),
            'return' => $returnPath,
            'delay' => max(500, min(10000, $minDelayMs)),
            'bits' => $this->difficulty,
            'rnd' => bin2hex(random_bytes(16)),
        ];
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');

        return ['nonce' => $encoded.'.'.hash_hmac('sha256', $encoded, $this->appSecret), 'returnPath' => $returnPath];
    }

    public function verify(Request $request, string $nonce, string $solution = ''): ?string
    {
        if (strlen($nonce) > 12000 || '' === $solution || strlen($solution) > 12 || !ctype_digit($solution)) {
            return null;
        }
        [$encoded, $signature] = array_pad(explode('.', $nonce, 2), 2, '');
        if (!hash_equals(hash_hmac('sha256', $encoded, $this->appSecret), $signature)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true), true);
        if (!is_array($payload) || 3 !== ($payload['v'] ?? null) || (int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        $ipHash = hash('sha256', BotGuardIpFingerprint::normalize((string) $request->getClientIp()));
        $uaHash = hash('sha256', strtolower(trim((string) $request->headers->get('User-Agent', ''))));
        if (!hash_equals((string) ($payload['ip'] ?? ''), $ipHash) || !hash_equals((string) ($payload['ua'] ?? ''), $uaHash)) {
            return null;
        }
        if ((microtime(true) - (float) ($payload['iat'] ?? 0)) * 1000 < (int) ($payload['delay'] ?? 500)) {
            return null;
        }
        $bits = (int) ($payload['bits'] ?? 0);
        if ($bits < 8 || $bits > 20) {
            return null;
        }
        $hash = hash('sha256', hash('sha256', $nonce).':'.$solution);
        if (hexdec(substr($hash, 0, 8)) >= 2 ** (32 - $bits)) {
            return null;
        }
        if ($this->rateLimiter->consume($request, 'challenge_once', hash('sha256', $nonce), 1, max(1, (int) $payload['exp'] - time()))) {
            return null;
        }

        return $this->sanitizeReturnPath((string) ($payload['return'] ?? '/'));
    }

    public function buildChallengeHtml(string $nonce, string $returnPath, int $minDelayMs): string
    {
        $config = json_encode([
            'nonce' => $nonce,
            'seed' => hash('sha256', $nonce),
            'bits' => $this->difficulty,
            'delay' => max(500, min(10000, $minDelayMs)),
            'verify' => self::VERIFY_PATH,
            'worker' => '/assets/bot-guard/pow.js',
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return BotGuardChallengePage::render($config);
    }

    private function sanitizeReturnPath(string $path): string
    {
        $path = trim($path);
        $decoded = rawurldecode((string) parse_url($path, PHP_URL_PATH));
        if ('' === $path || '/' !== $path[0] || 0 === strpos($path, '//') || 0 === strpos($decoded, '//') || false !== strpos($decoded, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) || 0 === strpos($decoded, '/_bot-guard')) {
            return '/';
        }

        return $path;
    }
}
