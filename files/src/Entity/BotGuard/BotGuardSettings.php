<?php

declare(strict_types=1);

namespace App\Entity\BotGuard;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="bot_guard_settings")
 * @ORM\HasLifecycleCallbacks
 */
class BotGuardSettings
{
    /**
     * @var int|null
     *
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $enabled = true;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $blockEmptyUserAgent = true;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $loggingEnabled = true;

    /**
     * Включает обязательную cookie-проверку для всех страниц сайта.
     *
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $underAttack = false;

    /**
     * Белый список User-Agent для обхода проверок (включая режим «Под атакой»).
     * Значения разделяются переносом строки или запятой.
     *
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private $cookieWhitelistUserAgents;

    /**
     * Разрешает пропуск мягкой cookie-проверки для доверенного внешнего referrer.
     * Для страниц фильтров каталога из CMS referrer из поиска учитывается всегда (см. catalogFilterPagesSoftCheck).
     *
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $trustReferrer = false;

    /**
     * Домены доверенного referrer (по одному в строке). Пусто — встроенный список (Яндекс, Google, VK).
     *
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private $trustedReferrerDomains;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $pathRateLimitEnabled = true;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255)
     */
    private $pathRateLimitUriPattern = '/filtered';

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $pathRateLimitMaxRequests = 30;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $pathRateLimitWindowSeconds = 60;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $jsChallengeMinDelayMs = 1200;

    /**
     * Мягкая cookie-проверка для всех URL с /filtered/ и страниц фильтров из CMS (вместо strict JS).
     *
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $catalogFilterPagesSoftCheck = true;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $blockStatusCode = 403;

    /**
     * Срок хранения логов блокировок в днях.
     *
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $retentionDays = 60;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $rateLimitEnabled = true;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $rateLimitMaxRequests = 60;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $rateLimitWindowSeconds = 60;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $reduceLoggingUnderAttack = true;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $autoUnderAttackEnabled = false;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $autoUnderAttackCpuPercent = 95;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $autoUnderAttackMemPercent = 95;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $autoUnderAttackDurationMinutes = 3;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $autoUnderAttackReleasePercent = 75;

    /**
     * @var \DateTimeInterface|null
     *
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function onSave(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return 'Bot Guard Settings';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isBlockEmptyUserAgent(): bool
    {
        return $this->blockEmptyUserAgent;
    }

    public function setBlockEmptyUserAgent(bool $blockEmptyUserAgent): self
    {
        $this->blockEmptyUserAgent = $blockEmptyUserAgent;

        return $this;
    }

    public function isLoggingEnabled(): bool
    {
        return $this->loggingEnabled;
    }

    public function setLoggingEnabled(bool $loggingEnabled): self
    {
        $this->loggingEnabled = $loggingEnabled;

        return $this;
    }

    public function isUnderAttack(): bool
    {
        return $this->underAttack;
    }

    public function setUnderAttack(bool $underAttack): self
    {
        $this->underAttack = $underAttack;

        return $this;
    }

    public function getCookieWhitelistUserAgents(): ?string
    {
        return $this->cookieWhitelistUserAgents;
    }

    public function setCookieWhitelistUserAgents(?string $cookieWhitelistUserAgents): self
    {
        $this->cookieWhitelistUserAgents = $cookieWhitelistUserAgents;

        return $this;
    }

    public function isTrustReferrer(): bool
    {
        return $this->trustReferrer;
    }

    public function setTrustReferrer(bool $trustReferrer): self
    {
        $this->trustReferrer = $trustReferrer;

        return $this;
    }

    public function getBlockStatusCode(): int
    {
        return $this->blockStatusCode;
    }

    public function setBlockStatusCode(int $blockStatusCode): self
    {
        if ($blockStatusCode < 400 || $blockStatusCode > 599) {
            $blockStatusCode = 403;
        }

        $this->blockStatusCode = $blockStatusCode;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $retentionDays): self
    {
        if ($retentionDays < 1) {
            $retentionDays = 1;
        }

        if ($retentionDays > 3650) {
            $retentionDays = 3650;
        }

        $this->retentionDays = $retentionDays;

        return $this;
    }

    public function isRateLimitEnabled(): bool
    {
        return $this->rateLimitEnabled;
    }

    public function setRateLimitEnabled(bool $rateLimitEnabled): self
    {
        $this->rateLimitEnabled = $rateLimitEnabled;

        return $this;
    }

    public function getRateLimitMaxRequests(): int
    {
        return $this->rateLimitMaxRequests;
    }

    public function setRateLimitMaxRequests(int $rateLimitMaxRequests): self
    {
        if ($rateLimitMaxRequests < 1) {
            $rateLimitMaxRequests = 1;
        }

        if ($rateLimitMaxRequests > 10000) {
            $rateLimitMaxRequests = 10000;
        }

        $this->rateLimitMaxRequests = $rateLimitMaxRequests;

        return $this;
    }

    public function getRateLimitWindowSeconds(): int
    {
        return $this->rateLimitWindowSeconds;
    }

    public function setRateLimitWindowSeconds(int $rateLimitWindowSeconds): self
    {
        if ($rateLimitWindowSeconds < 10) {
            $rateLimitWindowSeconds = 10;
        }

        if ($rateLimitWindowSeconds > 3600) {
            $rateLimitWindowSeconds = 3600;
        }

        $this->rateLimitWindowSeconds = $rateLimitWindowSeconds;

        return $this;
    }

    public function isReduceLoggingUnderAttack(): bool
    {
        return $this->reduceLoggingUnderAttack;
    }

    public function setReduceLoggingUnderAttack(bool $reduceLoggingUnderAttack): self
    {
        $this->reduceLoggingUnderAttack = $reduceLoggingUnderAttack;

        return $this;
    }

    public function isAutoUnderAttackEnabled(): bool
    {
        return $this->autoUnderAttackEnabled;
    }

    public function setAutoUnderAttackEnabled(bool $autoUnderAttackEnabled): self
    {
        $this->autoUnderAttackEnabled = $autoUnderAttackEnabled;

        return $this;
    }

    public function getAutoUnderAttackCpuPercent(): int
    {
        return $this->autoUnderAttackCpuPercent;
    }

    public function setAutoUnderAttackCpuPercent(int $autoUnderAttackCpuPercent): self
    {
        if ($autoUnderAttackCpuPercent < 50) {
            $autoUnderAttackCpuPercent = 50;
        }

        if ($autoUnderAttackCpuPercent > 100) {
            $autoUnderAttackCpuPercent = 100;
        }

        $this->autoUnderAttackCpuPercent = $autoUnderAttackCpuPercent;

        return $this;
    }

    public function getAutoUnderAttackMemPercent(): int
    {
        return $this->autoUnderAttackMemPercent;
    }

    public function setAutoUnderAttackMemPercent(int $autoUnderAttackMemPercent): self
    {
        if ($autoUnderAttackMemPercent < 50) {
            $autoUnderAttackMemPercent = 50;
        }

        if ($autoUnderAttackMemPercent > 100) {
            $autoUnderAttackMemPercent = 100;
        }

        $this->autoUnderAttackMemPercent = $autoUnderAttackMemPercent;

        return $this;
    }

    public function getAutoUnderAttackDurationMinutes(): int
    {
        return $this->autoUnderAttackDurationMinutes;
    }

    public function setAutoUnderAttackDurationMinutes(int $autoUnderAttackDurationMinutes): self
    {
        if ($autoUnderAttackDurationMinutes < 1) {
            $autoUnderAttackDurationMinutes = 1;
        }

        if ($autoUnderAttackDurationMinutes > 120) {
            $autoUnderAttackDurationMinutes = 120;
        }

        $this->autoUnderAttackDurationMinutes = $autoUnderAttackDurationMinutes;

        return $this;
    }

    public function getAutoUnderAttackReleasePercent(): int
    {
        return $this->autoUnderAttackReleasePercent;
    }

    public function setAutoUnderAttackReleasePercent(int $autoUnderAttackReleasePercent): self
    {
        if ($autoUnderAttackReleasePercent < 40) {
            $autoUnderAttackReleasePercent = 40;
        }

        if ($autoUnderAttackReleasePercent > 99) {
            $autoUnderAttackReleasePercent = 99;
        }

        $this->autoUnderAttackReleasePercent = $autoUnderAttackReleasePercent;

        return $this;
    }

    public function getTrustedReferrerDomains(): ?string
    {
        return $this->trustedReferrerDomains;
    }

    public function setTrustedReferrerDomains(?string $trustedReferrerDomains): self
    {
        $this->trustedReferrerDomains = $trustedReferrerDomains;

        return $this;
    }

    public function isPathRateLimitEnabled(): bool
    {
        return $this->pathRateLimitEnabled;
    }

    public function setPathRateLimitEnabled(bool $pathRateLimitEnabled): self
    {
        $this->pathRateLimitEnabled = $pathRateLimitEnabled;

        return $this;
    }

    public function getPathRateLimitUriPattern(): string
    {
        return $this->pathRateLimitUriPattern;
    }

    public function setPathRateLimitUriPattern(string $pathRateLimitUriPattern): self
    {
        $pathRateLimitUriPattern = trim($pathRateLimitUriPattern);
        if ('' === $pathRateLimitUriPattern) {
            $pathRateLimitUriPattern = '/filtered';
        }

        $this->pathRateLimitUriPattern = $pathRateLimitUriPattern;

        return $this;
    }

    public function getPathRateLimitMaxRequests(): int
    {
        return $this->pathRateLimitMaxRequests;
    }

    public function setPathRateLimitMaxRequests(int $pathRateLimitMaxRequests): self
    {
        if ($pathRateLimitMaxRequests < 1) {
            $pathRateLimitMaxRequests = 1;
        }

        if ($pathRateLimitMaxRequests > 10000) {
            $pathRateLimitMaxRequests = 10000;
        }

        $this->pathRateLimitMaxRequests = $pathRateLimitMaxRequests;

        return $this;
    }

    public function getPathRateLimitWindowSeconds(): int
    {
        return $this->pathRateLimitWindowSeconds;
    }

    public function setPathRateLimitWindowSeconds(int $pathRateLimitWindowSeconds): self
    {
        if ($pathRateLimitWindowSeconds < 10) {
            $pathRateLimitWindowSeconds = 10;
        }

        if ($pathRateLimitWindowSeconds > 3600) {
            $pathRateLimitWindowSeconds = 3600;
        }

        $this->pathRateLimitWindowSeconds = $pathRateLimitWindowSeconds;

        return $this;
    }

    public function getJsChallengeMinDelayMs(): int
    {
        return $this->jsChallengeMinDelayMs;
    }

    public function setJsChallengeMinDelayMs(int $jsChallengeMinDelayMs): self
    {
        if ($jsChallengeMinDelayMs < 500) {
            $jsChallengeMinDelayMs = 500;
        }

        if ($jsChallengeMinDelayMs > 10000) {
            $jsChallengeMinDelayMs = 10000;
        }

        $this->jsChallengeMinDelayMs = $jsChallengeMinDelayMs;

        return $this;
    }

    public function isCatalogFilterPagesSoftCheck(): bool
    {
        return $this->catalogFilterPagesSoftCheck;
    }

    public function setCatalogFilterPagesSoftCheck(bool $catalogFilterPagesSoftCheck): self
    {
        $this->catalogFilterPagesSoftCheck = $catalogFilterPagesSoftCheck;

        return $this;
    }
}

