<?php

declare(strict_types=1);

namespace App\Entity\BotGuard;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="bot_guard_rule")
 * @ORM\HasLifecycleCallbacks
 */
class BotGuardRule
{
    public const TYPE_USER_AGENT_CONTAINS = 'user_agent_contains';
    public const TYPE_USER_AGENT_REGEX = 'user_agent_regex';
    public const TYPE_IP_EXACT = 'ip_exact';
    public const TYPE_URI_CONTAINS = 'uri_contains';

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
     * @ORM\Column(type="string", length=255)
     */
    private $name = '';

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=32)
     */
    private $type = self::TYPE_USER_AGENT_CONTAINS;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=255)
     */
    private $pattern = '';

    /**
     * Опциональное ограничение правила по URI.
     * Если пусто, правило применяется ко всем URI.
     *
     * @var string|null
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $uriPattern;

    /**
     * @var bool
     *
     * @ORM\Column(type="boolean")
     */
    private $active = true;

    /**
     * @var int
     *
     * @ORM\Column(type="integer")
     */
    private $priority = 100;

    /**
     * @var \DateTimeInterface|null
     *
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @var \DateTimeInterface|null
     *
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    /**
     * @ORM\PrePersist
     */
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @ORM\PreUpdate
     */
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : 'Bot rule #'.$this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function setPattern(string $pattern): self
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function getUriPattern(): ?string
    {
        return $this->uriPattern;
    }

    public function setUriPattern(?string $uriPattern): self
    {
        $this->uriPattern = $uriPattern;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}

