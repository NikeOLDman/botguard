<?php

declare(strict_types=1);

namespace App\Entity\BotGuard;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="bot_guard_form_settings")
 * @ORM\HasLifecycleCallbacks
 */
class BotGuardFormSettings
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
    private $enabled = false;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $protectCheckout = false;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $minFillSeconds = 3;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $minConfirmDelayMs = 400;

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
    private $rateLimitMaxRequests = 10;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $rateLimitWindowSeconds = 3600;

    /**
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private $blockedNames;

    /**
     * @var string|null
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private $blockedEmails;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $loggingEnabled = true;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $checkHoneypot = true;

    /**
     * @var string|null
     *
     * @ORM\Column(name="shield_logo_url", type="string", length=255, nullable=true)
     */
    private $shieldLogoUrl;

    /**
     * @var string
     *
     * @ORM\Column(name="shield_theme", type="string", length=16)
     */
    private $shieldTheme = 'blue';

    /**
     * @var \DateTimeInterface|null
     *
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return 'Bot Guard Form Settings';
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

    public function isProtectCheckout(): bool
    {
        return $this->protectCheckout;
    }

    public function setProtectCheckout(bool $protectCheckout): self
    {
        $this->protectCheckout = $protectCheckout;

        return $this;
    }

    public function getMinFillSeconds(): int
    {
        return $this->minFillSeconds;
    }

    public function setMinFillSeconds(int $minFillSeconds): self
    {
        if ($minFillSeconds < 1) {
            $minFillSeconds = 1;
        }
        if ($minFillSeconds > 120) {
            $minFillSeconds = 120;
        }

        $this->minFillSeconds = $minFillSeconds;

        return $this;
    }

    public function getMinConfirmDelayMs(): int
    {
        return $this->minConfirmDelayMs;
    }

    public function setMinConfirmDelayMs(int $minConfirmDelayMs): self
    {
        if ($minConfirmDelayMs < 200) {
            $minConfirmDelayMs = 200;
        }
        if ($minConfirmDelayMs > 5000) {
            $minConfirmDelayMs = 5000;
        }

        $this->minConfirmDelayMs = $minConfirmDelayMs;

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
        if ($rateLimitMaxRequests > 1000) {
            $rateLimitMaxRequests = 1000;
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
        if ($rateLimitWindowSeconds < 60) {
            $rateLimitWindowSeconds = 60;
        }
        if ($rateLimitWindowSeconds > 86400) {
            $rateLimitWindowSeconds = 86400;
        }

        $this->rateLimitWindowSeconds = $rateLimitWindowSeconds;

        return $this;
    }

    public function getBlockedNames(): ?string
    {
        return $this->blockedNames;
    }

    public function setBlockedNames(?string $blockedNames): self
    {
        $this->blockedNames = $blockedNames;

        return $this;
    }

    public function getBlockedEmails(): ?string
    {
        return $this->blockedEmails;
    }

    public function setBlockedEmails(?string $blockedEmails): self
    {
        $this->blockedEmails = $blockedEmails;

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

    public function isCheckHoneypot(): bool
    {
        return $this->checkHoneypot;
    }

    public function setCheckHoneypot(bool $checkHoneypot): self
    {
        $this->checkHoneypot = $checkHoneypot;

        return $this;
    }

    public function getShieldLogoUrl(): ?string
    {
        return $this->shieldLogoUrl;
    }

    public function setShieldLogoUrl(?string $shieldLogoUrl): self
    {
        $this->shieldLogoUrl = $shieldLogoUrl;

        return $this;
    }

    public function getShieldTheme(): string
    {
        return $this->shieldTheme;
    }

    public function setShieldTheme(string $shieldTheme): self
    {
        $this->shieldTheme = \App\BotGuard\Form\BotGuardFormShieldTheme::normalizeTheme($shieldTheme);

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    /**
     * @ORM\PrePersist
     * @ORM\PreUpdate
     */
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
