<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MacroReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $recordedAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $inflation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $inflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $outputGap = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $outputGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $policyRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $policyRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $yield10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $yield10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $corporateTaxRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $equityRiskPremium = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private ?string $nominalGdpIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $marketVolatility = null;

    // --- Standard Getters & Setters ---

    public function getId(): ?int { return $this->id; }
    
    public function getRecordedAt(): ?\DateTimeInterface { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeInterface $recordedAt): self { $this->recordedAt = $recordedAt; return $this; }

    public function getInflation(): ?string { return $this->inflation; }
    public function setInflation(string $inflation): self { $this->inflation = $inflation; return $this; }

    public function getInflationEma(): ?string { return $this->inflationEma; }
    public function setInflationEma(string $inflationEma): self { $this->inflationEma = $inflationEma; return $this; }

    public function getOutputGap(): ?string { return $this->outputGap; }
    public function setOutputGap(string $outputGap): self { $this->outputGap = $outputGap; return $this; }

    public function getOutputGapEma(): ?string { return $this->outputGapEma; }
    public function setOutputGapEma(string $outputGapEma): self { $this->outputGapEma = $outputGapEma; return $this; }

    public function getPolicyRate(): ?string { return $this->policyRate; }
    public function setPolicyRate(string $policyRate): self { $this->policyRate = $policyRate; return $this; }

    public function getPolicyRateEma(): ?string { return $this->policyRateEma; }
    public function setPolicyRateEma(string $policyRateEma): self { $this->policyRateEma = $policyRateEma; return $this; }

    public function getYield10y(): ?string { return $this->yield10y; }
    public function setYield10y(string $yield10y): self { $this->yield10y = $yield10y; return $this; }

    public function getYield10yEma(): ?string { return $this->yield10yEma; }
    public function setYield10yEma(string $yield10yEma): self { $this->yield10yEma = $yield10yEma; return $this; }

    public function getCorporateTaxRate(): ?string { return $this->corporateTaxRate; }
    public function setCorporateTaxRate(string $corporateTaxRate): self { $this->corporateTaxRate = $corporateTaxRate; return $this; }

    public function getEquityRiskPremium(): ?string { return $this->equityRiskPremium; }
    public function setEquityRiskPremium(string $equityRiskPremium): self { $this->equityRiskPremium = $equityRiskPremium; return $this; }

    public function getNominalGdpIndex(): ?string { return $this->nominalGdpIndex; }
    public function setNominalGdpIndex(string $nominalGdpIndex): self { $this->nominalGdpIndex = $nominalGdpIndex; return $this; }

    public function getMarketVolatility(): ?string { return $this->marketVolatility; }
    public function setMarketVolatility(string $marketVolatility): self { $this->marketVolatility = $marketVolatility; return $this; }
}