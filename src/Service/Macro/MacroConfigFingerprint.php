<?php

declare(strict_types=1);

namespace App\Service\Macro;

use App\Data\Politics\AerieCouncil;
use App\Data\Politics\AerieDiet;
use App\Service\Macro\Subsystem\Aggregate\ConsumerPrices;
use App\Service\Macro\Subsystem\Aggregate\DomesticDemand;
use App\Service\Macro\Subsystem\Aggregate\IndustryIndicators;
use App\Service\Macro\Subsystem\Aggregate\InventoryAndTiming;
use App\Service\Macro\Subsystem\Aggregate\MovingAverages;
use App\Service\Macro\Subsystem\Aggregate\NationalAccounts;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Macro\Subsystem\Aggregate\OutputGap;
use App\Service\Macro\Subsystem\Aggregate\ProductivityGrowth;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\CreditRisk;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\StochasticProcesses;
use App\Service\Math\TimeSeries;
use App\Service\Math\Valuation;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Council\StanceRevision;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\Diet\CoalitionFormation;
use App\Service\Politics\Diet\Election;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Diet\OpinionPolls;
use App\Service\Politics\Diet\PartyLeaders;
use App\Service\Politics\Policy\BudgetBargain;
use App\Service\Politics\Policy\Platforms;

/**
 * A short hash of every constant that parameterises the macro economy, stamped on each recorded quarter.
 *
 * A dump that spans a ticker restart can span a recalibration, and nothing in a row said which constants produced
 * it: two economies read as one run. The hash changes when any constant of the engine or its subsystems changes and
 * with nothing else, so the dump can split a run where the economy did.
 */
final class MacroConfigFingerprint
{
    // --- Parameter Classes ---
    /**
     * The classes whose constants are the macro economy's parameters: the shared ones, the engine's and its
     * subsystems', the Math formulas the engine steps with, and the government's, whose levers and appointments move
     * the economy. MacroConfigFingerprintTest makes every class with constants in those folders either listed here or
     * named as no parameter.
     */
    public const PARAMETER_CLASSES = [
        MacroConstants::class,
        MacroEngine::class,
        MonetaryPolicySubsystem::class,
        LaborMarketSubsystem::class,
        ProductivityGrowth::class,
        DomesticDemand::class,
        OutputGap::class,
        InventoryAndTiming::class,
        OpenEconomy::class,
        ConsumerPrices::class,
        IndustryIndicators::class,
        NationalAccounts::class,
        MovingAverages::class,
        CommodityLogisticsSubsystem::class,
        AssetMarketSubsystem::class,
        CreditFiscalSubsystem::class,
        SovereignFundSubsystem::class,
        CreditRisk::class,
        FinancialConstants::class,
        FirmEconomics::class,
        MacroTransmission::class,
        StochasticProcesses::class,
        TimeSeries::class,
        Valuation::class,
        AerieCouncil::class,
        AerieDiet::class,
        CouncilAppointments::class,
        StanceRevision::class,
        Exchequer::class,
        FairTradingBoard::class,
        FinancialRegulator::class,
        MonetaryAuthority::class,
        Office::class,
        SecuritiesCommission::class,
        SovereignReserveFund::class,
        CoalitionFormation::class,
        Election::class,
        ElectionForecast::class,
        OpinionPolls::class,
        PartyLeaders::class,
        BudgetBargain::class,
        Platforms::class,
    ];

    // --- Format ---

    /** Hex characters kept: 48 bits, ample to tell a handful of calibrations apart in one table. */
    private const LENGTH = 12;

    private ?string $fingerprint = null;

    /** The live economy's fingerprint, computed once per process. */
    public function fingerprint(): string
    {
        return $this->fingerprint ??= self::of(self::constantsOf(self::PARAMETER_CLASSES));
    }

    /**
     * Every constant each class declares, keyed by class.
     *
     * @param  list<class-string>                  $classes Classes to read the constants of.
     * @return array<string, array<string, mixed>>
     */
    public static function constantsOf(array $classes): array
    {
        $constants = [];
        foreach ($classes as $class) {
            $constants[$class] = (new \ReflectionClass($class))->getConstants();
        }

        return $constants;
    }

    /**
     * Fingerprint of a constants map, independent of the order the constants are declared in.
     *
     * @param array<string, array<string, mixed>> $constants Class => constant name => value.
     */
    public static function of(array $constants): string
    {
        ksort($constants);
        foreach ($constants as &$declared) {
            ksort($declared);
        }
        unset($declared);

        return substr(sha1(serialize($constants)), 0, self::LENGTH);
    }
}
