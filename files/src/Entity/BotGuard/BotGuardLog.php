<?php

declare(strict_types=1);

namespace App\Entity\BotGuard;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="bot_guard_log",
 *     indexes={
 *         @ORM\Index(name="idx_bot_guard_log_blocked_at", columns={"blocked_at"}),
 *         @ORM\Index(name="idx_bot_guard_log_ip", columns={"ip"}),
 *         @ORM\Index(name="idx_bot_guard_log_reason", columns={"reason"})
 *     }
 * )
 * @ORM\HasLifecycleCallbacks
 */
class BotGuardLog
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
     * @var string
     *
     * @ORM\Column(type="string", length=50)
     */
    private $reason = '';

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $ruleName;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $rulePattern;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", length=45, nullable=true)
     */
    private $ip;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", length=10, nullable=true)
     */
    private $method;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $uri;

    /**
     * @var string|null
     *
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $userAgent;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $statusCode = 403;

    /**
     * @var \DateTimeInterface|null
     *
     * @ORM\Column(name="blocked_at", type="datetime")
     */
    private $blockedAt;

    /**
     * @ORM\PrePersist
     */
    public function onPrePersist(): void
    {
        if (null === $this->blockedAt) {
            $this->blockedAt = new \DateTimeImmutable();
        }
    }

    public function __toString(): string
    {
        return sprintf('%s %s', $this->reason, (string) $this->uri);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function getRuleName(): ?string
    {
        return $this->ruleName;
    }

    public function setRuleName(?string $ruleName): self
    {
        $this->ruleName = $ruleName;

        return $this;
    }

    public function getRulePattern(): ?string
    {
        return $this->rulePattern;
    }

    public function setRulePattern(?string $rulePattern): self
    {
        $this->rulePattern = $rulePattern;

        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): self
    {
        $this->method = $method;

        return $this;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function setUri(?string $uri): self
    {
        $this->uri = $uri;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getBlockedAt(): ?\DateTimeInterface
    {
        return $this->blockedAt;
    }

    public function setBlockedAt(\DateTimeInterface $blockedAt): self
    {
        $this->blockedAt = $blockedAt;

        return $this;
    }
}

