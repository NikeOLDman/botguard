<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BotGuardJsChallengeService
{
    public const VERIFY_PATH = '/_bot-guard/verify';
    private const NONCE_PREFIX = 'bot_guard.js_nonce.';
    private const NONCE_TTL_SECONDS = 600;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    /**
     * @return array{nonce: string, returnPath: string}
     */
    public function issue(Request $request, string $returnPath, int $minDelayMs): array
    {
        $returnPath = $this->sanitizeReturnPath($returnPath);
        $nonce = bin2hex(random_bytes(16));
        $payload = [
            'issuedAt' => microtime(true),
            'ip' => hash('sha256', BotGuardIpFingerprint::build((string) $request->getClientIp())),
            'ua' => hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', '')))),
            'return' => $returnPath,
            'minDelayMs' => max(500, $minDelayMs),
        ];

        $this->storeNonce($nonce, $payload);

        return [
            'nonce' => $nonce,
            'returnPath' => $returnPath,
        ];
    }

    /**
     * @return string|null Sanitized return path on success.
     */
    public function verify(Request $request, string $nonce): ?string
    {
        if (null === $this->cache || '' === trim($nonce)) {
            return null;
        }

        $key = self::NONCE_PREFIX.hash('sha256', $nonce);
        $missMarker = new \stdClass();

        try {
            $payload = $this->cache->get($key, function (ItemInterface $item) use ($missMarker) {
                $item->expiresAfter(1);

                return $missMarker;
            });
        } catch (\Throwable $e) {
            return null;
        }

        if ($payload === $missMarker || !is_array($payload)) {
            return null;
        }

        $this->cache->delete($key);

        $actualIpHash = hash('sha256', BotGuardIpFingerprint::build((string) $request->getClientIp()));
        $actualUaHash = hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', ''))));

        if (!hash_equals((string) ($payload['ip'] ?? ''), $actualIpHash)) {
            return null;
        }

        if (!hash_equals((string) ($payload['ua'] ?? ''), $actualUaHash)) {
            return null;
        }

        $issuedAt = (float) ($payload['issuedAt'] ?? 0);
        $minDelayMs = (int) ($payload['minDelayMs'] ?? 1200);
        $elapsedMs = (microtime(true) - $issuedAt) * 1000;

        if ($elapsedMs < $minDelayMs) {
            return null;
        }

        return $this->sanitizeReturnPath((string) ($payload['return'] ?? '/'));
    }

    public function buildChallengeHtml(string $nonce, string $returnPath, int $minDelayMs): string
    {
        $nonceEsc = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');
        $returnEsc = htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8');
        $verifyEsc = htmlspecialchars(self::VERIFY_PATH, ENT_QUOTES, 'UTF-8');
        $delay = max(500, $minDelayMs);

        return '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex,nofollow">'
            .'<title>Проверка на ботов</title>'
            .'<style>'
            .'@keyframes bg-spin{to{transform:rotate(360deg)}}'
            .'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            .'font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;color:#1a1a1a}'
            .'.bg-challenge{text-align:center;padding:32px 24px;max-width:400px}'
            .'.bg-challenge__spinner{width:40px;height:40px;margin:0 auto 20px;border:3px solid #d5dbe3;'
            .'border-top-color:#2563eb;border-radius:50%;animation:bg-spin .8s linear infinite}'
            .'.bg-challenge__title{margin:0 0 12px;font-size:20px;font-weight:600;line-height:1.3}'
            .'.bg-challenge__text{margin:0;font-size:15px;line-height:1.5;color:#4b5563}'
            .'</style></head><body>'
            .'<div class="bg-challenge" role="status" aria-live="polite">'
            .'<div class="bg-challenge__spinner" aria-hidden="true"></div>'
            .'<h1 class="bg-challenge__title">Проверка на ботов</h1>'
            .'<p class="bg-challenge__text">Выполняется автоматическая проверка, что вы не бот. Подождите несколько секунд.</p>'
            .'</div>'
            .'<noscript><p class="bg-challenge__text" style="position:fixed;bottom:16px;left:0;right:0;text-align:center;padding:0 16px">'
            .'Для продолжения включите JavaScript и обновите страницу.</p></noscript>'
            .'<script>(function(){var nonce="'.$nonceEsc.'";var ret="'.$returnEsc.'";var verifyUrl="'.$verifyEsc.'";'
            .'var delay='.$delay.';setTimeout(function(){var body=new URLSearchParams();body.set("nonce",nonce);body.set("return",ret);'
            .'fetch(verifyUrl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:body.toString(),credentials:"same-origin"})'
            .'.then(function(r){return r.json();}).then(function(d){if(d&&d.ok&&d.redirect){window.location.replace(d.redirect);return;}'
            .'window.location.reload();}).catch(function(){window.location.reload();});},delay);}());</script>'
            .'</body></html>';
    }

    private function sanitizeReturnPath(string $path): string
    {
        $path = trim($path);
        if ('' === $path || 0 !== strpos($path, '/')) {
            return '/';
        }

        if (false !== strpos($path, '//') || false !== strpos($path, '\\')) {
            return '/';
        }

        if (0 === strpos($path, self::VERIFY_PATH)) {
            return '/';
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeNonce(string $nonce, array $payload): void
    {
        if (null === $this->cache) {
            return;
        }

        $key = self::NONCE_PREFIX.hash('sha256', $nonce);

        try {
            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($payload): array {
                $item->expiresAfter(self::NONCE_TTL_SECONDS);

                return $payload;
            });
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }
}
