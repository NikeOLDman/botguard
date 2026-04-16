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
}

