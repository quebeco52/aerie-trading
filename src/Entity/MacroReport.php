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

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $targetRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $yield10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $yield10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield2y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield2yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield5y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield5yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield30y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $yield30yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $corporateTaxRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $equityRiskPremium = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private ?string $nominalGdpIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $marketVolatility = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $macroCreditSpread = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $macroCreditSpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $unemploymentRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $unemploymentRateEma = null;



    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $energyPriceIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $energyPriceIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $consumerSentimentIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $consumerSentimentIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $exchangeRateIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $exchangeRateIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $industrialMetalsIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $industrialMetalsIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $governmentSpendingIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $governmentSpendingIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $commercialPropertyIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $commercialPropertyIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $residentialPropertyIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $residentialPropertyIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $retailDefaultRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $retailDefaultRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $agriculturalCommodityIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $agriculturalCommodityIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $freightRateIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $freightRateIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $capitalStockOverhang = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $capitalStockOverhangEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $interbankLiquiditySpread = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $interbankLiquiditySpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $totalFactorProductivityIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private ?string $totalFactorProductivityIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tipsBreakeven = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $tipsBreakevenEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nsCurvature2 = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $jobVacanciesRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $jobVacanciesRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $laborTightness = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $laborTightnessEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $wageGrowth = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $wageGrowthEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $naturalRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $naturalRateEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $termPremium10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $termPremium10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $riskNeutral10y = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $riskNeutral10yEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $balanceSheetIntensity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nairu = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $nairuEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sovereignDebtToGdp = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $sovereignDebtToGdpEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $financialConditionsIndex = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $financialConditionsIndexEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $agriCostPushLag = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $supercoreInflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $coreGoodsInflationEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $cumulativeInflationGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $highYieldCreditSpreadEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $inventoryStockGapEma = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $energyInventoryIndexEma = null;

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

    public function getTargetRate(): ?string { return $this->targetRate; }
    public function setTargetRate(?string $targetRate): self { $this->targetRate = $targetRate; return $this; }

    public function getYield10y(): ?string { return $this->yield10y; }
    public function setYield10y(string $yield10y): self { $this->yield10y = $yield10y; return $this; }

    public function getYield10yEma(): ?string { return $this->yield10yEma; }
    public function setYield10yEma(string $yield10yEma): self { $this->yield10yEma = $yield10yEma; return $this; }

    public function getYield2y(): ?string { return $this->yield2y; }
    public function setYield2y(?string $yield2y): self { $this->yield2y = $yield2y; return $this; }

    public function getYield2yEma(): ?string { return $this->yield2yEma; }
    public function setYield2yEma(?string $yield2yEma): self { $this->yield2yEma = $yield2yEma; return $this; }

    public function getYield5y(): ?string { return $this->yield5y; }
    public function setYield5y(?string $yield5y): self { $this->yield5y = $yield5y; return $this; }

    public function getYield5yEma(): ?string { return $this->yield5yEma; }
    public function setYield5yEma(?string $yield5yEma): self { $this->yield5yEma = $yield5yEma; return $this; }

    public function getYield30y(): ?string { return $this->yield30y; }
    public function setYield30y(?string $yield30y): self { $this->yield30y = $yield30y; return $this; }

    public function getYield30yEma(): ?string { return $this->yield30yEma; }
    public function setYield30yEma(?string $yield30yEma): self { $this->yield30yEma = $yield30yEma; return $this; }

    public function getCorporateTaxRate(): ?string { return $this->corporateTaxRate; }
    public function setCorporateTaxRate(string $corporateTaxRate): self { $this->corporateTaxRate = $corporateTaxRate; return $this; }

    public function getEquityRiskPremium(): ?string { return $this->equityRiskPremium; }
    public function setEquityRiskPremium(string $equityRiskPremium): self { $this->equityRiskPremium = $equityRiskPremium; return $this; }

    public function getNominalGdpIndex(): ?string { return $this->nominalGdpIndex; }
    public function setNominalGdpIndex(string $nominalGdpIndex): self { $this->nominalGdpIndex = $nominalGdpIndex; return $this; }

    public function getMarketVolatility(): ?string { return $this->marketVolatility; }
    public function setMarketVolatility(string $marketVolatility): self { $this->marketVolatility = $marketVolatility; return $this; }

    public function getMacroCreditSpread(): ?string { return $this->macroCreditSpread; }
    public function setMacroCreditSpread(?string $macroCreditSpread): self { $this->macroCreditSpread = $macroCreditSpread; return $this; }

    public function getMacroCreditSpreadEma(): ?string { return $this->macroCreditSpreadEma; }
    public function setMacroCreditSpreadEma(?string $macroCreditSpreadEma): self { $this->macroCreditSpreadEma = $macroCreditSpreadEma; return $this; }

    public function getUnemploymentRate(): ?string { return $this->unemploymentRate; }
    public function setUnemploymentRate(string $unemploymentRate): self { $this->unemploymentRate = $unemploymentRate; return $this; }

    public function getUnemploymentRateEma(): ?string { return $this->unemploymentRateEma; }
    public function setUnemploymentRateEma(string $unemploymentRateEma): self { $this->unemploymentRateEma = $unemploymentRateEma; return $this; }



    public function getEnergyPriceIndex(): ?string { return $this->energyPriceIndex; }
    public function setEnergyPriceIndex(string $energyPriceIndex): self { $this->energyPriceIndex = $energyPriceIndex; return $this; }

    public function getEnergyPriceIndexEma(): ?string { return $this->energyPriceIndexEma; }
    public function setEnergyPriceIndexEma(string $energyPriceIndexEma): self { $this->energyPriceIndexEma = $energyPriceIndexEma; return $this; }

    public function getConsumerSentimentIndex(): ?string { return $this->consumerSentimentIndex; }
    public function setConsumerSentimentIndex(string $consumerSentimentIndex): self { $this->consumerSentimentIndex = $consumerSentimentIndex; return $this; }

    public function getConsumerSentimentIndexEma(): ?string { return $this->consumerSentimentIndexEma; }
    public function setConsumerSentimentIndexEma(string $consumerSentimentIndexEma): self { $this->consumerSentimentIndexEma = $consumerSentimentIndexEma; return $this; }

    public function getExchangeRateIndex(): ?string { return $this->exchangeRateIndex; }
    public function setExchangeRateIndex(string $exchangeRateIndex): self { $this->exchangeRateIndex = $exchangeRateIndex; return $this; }

    public function getExchangeRateIndexEma(): ?string { return $this->exchangeRateIndexEma; }
    public function setExchangeRateIndexEma(string $exchangeRateIndexEma): self { $this->exchangeRateIndexEma = $exchangeRateIndexEma; return $this; }

    public function getIndustrialMetalsIndex(): ?string { return $this->industrialMetalsIndex; }
    public function setIndustrialMetalsIndex(string $industrialMetalsIndex): self { $this->industrialMetalsIndex = $industrialMetalsIndex; return $this; }

    public function getIndustrialMetalsIndexEma(): ?string { return $this->industrialMetalsIndexEma; }
    public function setIndustrialMetalsIndexEma(string $industrialMetalsIndexEma): self { $this->industrialMetalsIndexEma = $industrialMetalsIndexEma; return $this; }

    public function getGovernmentSpendingIndex(): ?string { return $this->governmentSpendingIndex; }
    public function setGovernmentSpendingIndex(string $governmentSpendingIndex): self { $this->governmentSpendingIndex = $governmentSpendingIndex; return $this; }

    public function getGovernmentSpendingIndexEma(): ?string { return $this->governmentSpendingIndexEma; }
    public function setGovernmentSpendingIndexEma(string $governmentSpendingIndexEma): self { $this->governmentSpendingIndexEma = $governmentSpendingIndexEma; return $this; }

    public function getCommercialPropertyIndex(): ?string { return $this->commercialPropertyIndex; }
    public function setCommercialPropertyIndex(string $commercialPropertyIndex): self { $this->commercialPropertyIndex = $commercialPropertyIndex; return $this; }

    public function getCommercialPropertyIndexEma(): ?string { return $this->commercialPropertyIndexEma; }
    public function setCommercialPropertyIndexEma(string $commercialPropertyIndexEma): self { $this->commercialPropertyIndexEma = $commercialPropertyIndexEma; return $this; }

    public function getResidentialPropertyIndex(): ?string { return $this->residentialPropertyIndex; }
    public function setResidentialPropertyIndex(string $residentialPropertyIndex): self { $this->residentialPropertyIndex = $residentialPropertyIndex; return $this; }

    public function getResidentialPropertyIndexEma(): ?string { return $this->residentialPropertyIndexEma; }
    public function setResidentialPropertyIndexEma(string $residentialPropertyIndexEma): self { $this->residentialPropertyIndexEma = $residentialPropertyIndexEma; return $this; }

    public function getRetailDefaultRate(): ?string { return $this->retailDefaultRate; }
    public function setRetailDefaultRate(string $retailDefaultRate): self { $this->retailDefaultRate = $retailDefaultRate; return $this; }

    public function getRetailDefaultRateEma(): ?string { return $this->retailDefaultRateEma; }
    public function setRetailDefaultRateEma(string $retailDefaultRateEma): self { $this->retailDefaultRateEma = $retailDefaultRateEma; return $this; }

    public function getAgriculturalCommodityIndex(): ?string { return $this->agriculturalCommodityIndex; }
    public function setAgriculturalCommodityIndex(string $agriculturalCommodityIndex): self { $this->agriculturalCommodityIndex = $agriculturalCommodityIndex; return $this; }

    public function getAgriculturalCommodityIndexEma(): ?string { return $this->agriculturalCommodityIndexEma; }
    public function setAgriculturalCommodityIndexEma(string $agriculturalCommodityIndexEma): self { $this->agriculturalCommodityIndexEma = $agriculturalCommodityIndexEma; return $this; }

    public function getFreightRateIndex(): ?string { return $this->freightRateIndex; }
    public function setFreightRateIndex(string $freightRateIndex): self { $this->freightRateIndex = $freightRateIndex; return $this; }

    public function getFreightRateIndexEma(): ?string { return $this->freightRateIndexEma; }
    public function setFreightRateIndexEma(string $freightRateIndexEma): self { $this->freightRateIndexEma = $freightRateIndexEma; return $this; }

    public function getCapitalStockOverhang(): ?string { return $this->capitalStockOverhang; }
    public function setCapitalStockOverhang(string $capitalStockOverhang): self { $this->capitalStockOverhang = $capitalStockOverhang; return $this; }

    public function getCapitalStockOverhangEma(): ?string { return $this->capitalStockOverhangEma; }
    public function setCapitalStockOverhangEma(string $capitalStockOverhangEma): self { $this->capitalStockOverhangEma = $capitalStockOverhangEma; return $this; }

    public function getInterbankLiquiditySpread(): ?string { return $this->interbankLiquiditySpread; }
    public function setInterbankLiquiditySpread(string $interbankLiquiditySpread): self { $this->interbankLiquiditySpread = $interbankLiquiditySpread; return $this; }

    public function getInterbankLiquiditySpreadEma(): ?string { return $this->interbankLiquiditySpreadEma; }
    public function setInterbankLiquiditySpreadEma(string $interbankLiquiditySpreadEma): self { $this->interbankLiquiditySpreadEma = $interbankLiquiditySpreadEma; return $this; }

    public function getTotalFactorProductivityIndex(): ?string { return $this->totalFactorProductivityIndex; }
    public function setTotalFactorProductivityIndex(string $totalFactorProductivityIndex): self { $this->totalFactorProductivityIndex = $totalFactorProductivityIndex; return $this; }

    public function getTotalFactorProductivityIndexEma(): ?string { return $this->totalFactorProductivityIndexEma; }
    public function setTotalFactorProductivityIndexEma(string $totalFactorProductivityIndexEma): self { $this->totalFactorProductivityIndexEma = $totalFactorProductivityIndexEma; return $this; }

    public function getTipsBreakeven(): ?string { return $this->tipsBreakeven; }
    public function setTipsBreakeven(?string $tipsBreakeven): self { $this->tipsBreakeven = $tipsBreakeven; return $this; }

    public function getTipsBreakevenEma(): ?string { return $this->tipsBreakevenEma; }
    public function setTipsBreakevenEma(?string $tipsBreakevenEma): self { $this->tipsBreakevenEma = $tipsBreakevenEma; return $this; }

    public function getNsCurvature2(): ?string { return $this->nsCurvature2; }
    public function setNsCurvature2(?string $nsCurvature2): self { $this->nsCurvature2 = $nsCurvature2; return $this; }

    public function getJobVacanciesRate(): ?string { return $this->jobVacanciesRate; }
    public function setJobVacanciesRate(?string $jobVacanciesRate): self { $this->jobVacanciesRate = $jobVacanciesRate; return $this; }

    public function getJobVacanciesRateEma(): ?string { return $this->jobVacanciesRateEma; }
    public function setJobVacanciesRateEma(?string $jobVacanciesRateEma): self { $this->jobVacanciesRateEma = $jobVacanciesRateEma; return $this; }

    public function getLaborTightness(): ?string { return $this->laborTightness; }
    public function setLaborTightness(?string $laborTightness): self { $this->laborTightness = $laborTightness; return $this; }

    public function getLaborTightnessEma(): ?string { return $this->laborTightnessEma; }
    public function setLaborTightnessEma(?string $laborTightnessEma): self { $this->laborTightnessEma = $laborTightnessEma; return $this; }

    public function getWageGrowth(): ?string { return $this->wageGrowth; }
    public function setWageGrowth(?string $wageGrowth): self { $this->wageGrowth = $wageGrowth; return $this; }

    public function getWageGrowthEma(): ?string { return $this->wageGrowthEma; }
    public function setWageGrowthEma(?string $wageGrowthEma): self { $this->wageGrowthEma = $wageGrowthEma; return $this; }

    public function getNaturalRate(): ?string { return $this->naturalRate; }
    public function setNaturalRate(?string $naturalRate): self { $this->naturalRate = $naturalRate; return $this; }

    public function getNaturalRateEma(): ?string { return $this->naturalRateEma; }
    public function setNaturalRateEma(?string $naturalRateEma): self { $this->naturalRateEma = $naturalRateEma; return $this; }

    public function getTermPremium10y(): ?string { return $this->termPremium10y; }
    public function setTermPremium10y(?string $termPremium10y): self { $this->termPremium10y = $termPremium10y; return $this; }

    public function getTermPremium10yEma(): ?string { return $this->termPremium10yEma; }
    public function setTermPremium10yEma(?string $termPremium10yEma): self { $this->termPremium10yEma = $termPremium10yEma; return $this; }

    public function getRiskNeutral10y(): ?string { return $this->riskNeutral10y; }
    public function setRiskNeutral10y(?string $riskNeutral10y): self { $this->riskNeutral10y = $riskNeutral10y; return $this; }

    public function getRiskNeutral10yEma(): ?string { return $this->riskNeutral10yEma; }
    public function setRiskNeutral10yEma(?string $riskNeutral10yEma): self { $this->riskNeutral10yEma = $riskNeutral10yEma; return $this; }

    public function getBalanceSheetIntensity(): ?string { return $this->balanceSheetIntensity; }
    public function setBalanceSheetIntensity(?string $balanceSheetIntensity): self { $this->balanceSheetIntensity = $balanceSheetIntensity; return $this; }

    public function getNairu(): ?string { return $this->nairu; }
    public function setNairu(?string $nairu): self { $this->nairu = $nairu; return $this; }

    public function getNairuEma(): ?string { return $this->nairuEma; }
    public function setNairuEma(?string $nairuEma): self { $this->nairuEma = $nairuEma; return $this; }

    public function getSovereignDebtToGdp(): ?string { return $this->sovereignDebtToGdp; }
    public function setSovereignDebtToGdp(?string $sovereignDebtToGdp): self { $this->sovereignDebtToGdp = $sovereignDebtToGdp; return $this; }

    public function getSovereignDebtToGdpEma(): ?string { return $this->sovereignDebtToGdpEma; }
    public function setSovereignDebtToGdpEma(?string $sovereignDebtToGdpEma): self { $this->sovereignDebtToGdpEma = $sovereignDebtToGdpEma; return $this; }

    public function getFinancialConditionsIndex(): ?string { return $this->financialConditionsIndex; }
    public function setFinancialConditionsIndex(?string $financialConditionsIndex): self { $this->financialConditionsIndex = $financialConditionsIndex; return $this; }

    public function getFinancialConditionsIndexEma(): ?string { return $this->financialConditionsIndexEma; }
    public function setFinancialConditionsIndexEma(?string $financialConditionsIndexEma): self { $this->financialConditionsIndexEma = $financialConditionsIndexEma; return $this; }

    public function getAgriCostPushLag(): ?string { return $this->agriCostPushLag; }
    public function setAgriCostPushLag(?string $agriCostPushLag): self { $this->agriCostPushLag = $agriCostPushLag; return $this; }

    public function getSupercoreInflationEma(): ?string { return $this->supercoreInflationEma; }
    public function setSupercoreInflationEma(?string $supercoreInflationEma): self { $this->supercoreInflationEma = $supercoreInflationEma; return $this; }

    public function getCoreGoodsInflationEma(): ?string { return $this->coreGoodsInflationEma; }
    public function setCoreGoodsInflationEma(?string $coreGoodsInflationEma): self { $this->coreGoodsInflationEma = $coreGoodsInflationEma; return $this; }

    public function getCumulativeInflationGapEma(): ?string { return $this->cumulativeInflationGapEma; }
    public function setCumulativeInflationGapEma(?string $cumulativeInflationGapEma): self { $this->cumulativeInflationGapEma = $cumulativeInflationGapEma; return $this; }

    public function getHighYieldCreditSpreadEma(): ?string { return $this->highYieldCreditSpreadEma; }
    public function setHighYieldCreditSpreadEma(?string $highYieldCreditSpreadEma): self { $this->highYieldCreditSpreadEma = $highYieldCreditSpreadEma; return $this; }

    public function getInventoryStockGapEma(): ?string { return $this->inventoryStockGapEma; }
    public function setInventoryStockGapEma(?string $inventoryStockGapEma): self { $this->inventoryStockGapEma = $inventoryStockGapEma; return $this; }

    public function getEnergyInventoryIndexEma(): ?string { return $this->energyInventoryIndexEma; }
    public function setEnergyInventoryIndexEma(?string $energyInventoryIndexEma): self { $this->energyInventoryIndexEma = $energyInventoryIndexEma; return $this; }
}