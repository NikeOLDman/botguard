<?php

declare(strict_types=1);

namespace App\Entity\BotGuard;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="bot_guard_system_metric",
 *     indexes={
 *         @ORM\Index(name="idx_bot_guard_system_metric_sampled_at", columns={"sampled_at"})
 *     }
 * )
 */
class BotGuardSystemMetric
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
     * @var \DateTimeInterface
     *
     * @ORM\Column(name="sampled_at", type="datetime")
     */
    private $sampledAt;

    /**
     * @var float|null
     *
     * @ORM\Column(name="load_1", type="float", nullable=true)
     */
    private $load1;

    /**
     * @var float|null
     *
     * @ORM\Column(name="load_5", type="float", nullable=true)
     */
    private $load5;

    /**
     * @var float|null
     *
     * @ORM\Column(name="load_15", type="float", nullable=true)
     */
    private $load15;

    /**
     * @var float|null
     *
     * @ORM\Column(name="mem_total_mb", type="float", nullable=true)
     */
    private $memTotalMb;

    /**
     * @var float|null
     *
     * @ORM\Column(name="mem_used_mb", type="float", nullable=true)
     */
    private $memUsedMb;

    /**
     * @var float|null
     *
     * @ORM\Column(name="mem_used_percent", type="float", nullable=true)
     */
    private $memUsedPercent;

    /**
     * @var string
     *
     * @ORM\Column(type="string", length=64)
     */
    private $source = '';

    public function __construct()
    {
        $this->sampledAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->sampledAt->format('Y-m-d H:i:s');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSampledAt(): \DateTimeInterface
    {
        return $this->sampledAt;
    }

    public function setSampledAt(\DateTimeInterface $sampledAt): self
    {
        $this->sampledAt = $sampledAt;

        return $this;
    }

    public function getLoad1(): ?float
    {
        return $this->load1;
    }

    public function setLoad1(?float $load1): self
    {
        $this->load1 = $load1;

        return $this;
    }

    public function getLoad5(): ?float
    {
        return $this->load5;
    }

    public function setLoad5(?float $load5): self
    {
        $this->load5 = $load5;

        return $this;
    }

    public function getLoad15(): ?float
    {
        return $this->load15;
    }

    public function setLoad15(?float $load15): self
    {
        $this->load15 = $load15;

        return $this;
    }

    public function getMemTotalMb(): ?float
    {
        return $this->memTotalMb;
    }

    public function setMemTotalMb(?float $memTotalMb): self
    {
        $this->memTotalMb = $memTotalMb;

        return $this;
    }

    public function getMemUsedMb(): ?float
    {
        return $this->memUsedMb;
    }

    public function setMemUsedMb(?float $memUsedMb): self
    {
        $this->memUsedMb = $memUsedMb;

        return $this;
    }

    public function getMemUsedPercent(): ?float
    {
        return $this->memUsedPercent;
    }

    public function setMemUsedPercent(?float $memUsedPercent): self
    {
        $this->memUsedPercent = $memUsedPercent;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }
}
