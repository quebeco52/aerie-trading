<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Data\ModelParam;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Biotechnology and Specialty Drug Manufacturers.
 *
 * Financial Physics:
 * - CapEx represents high-risk R&D that creates intangible patent assets.
 * - Clinical development is a phase-transition (Markov) process: pivotal readouts arrive as a
 *   Bernoulli hazard scaled by pipeline breadth and R&D replacement intensity, and succeed at
 *   published industry transition rates rather than as symmetric Gaussian tail draws.
 * - Approvals are permanent: an approved asset steps up the commercial franchise base and
 *   refreshes the portfolio's weighted-average remaining exclusivity. Only the upfront milestone
 *   payment is a single-quarter event.
 * - Loss of Exclusivity (LOE) is a discrete, scheduled REVENUE event, not a margin drift: an
 *   exclusivity clock expires and the exposed franchise erodes exponentially at modality-specific
 *   hazards (small molecule collapses within a year, biologics bleed over several years).
 * - Reverse operating leverage at LOE is produced by the engine itself (fixed costs are carried
 *   separately in the EBIT bridge), so this model only adds the price-defense discounting.
 * - Inelastic demand: highly immune to macroeconomic output gap recessions.
 *
 * Archetypes are expressed through StockModelTuning overrides rather than subclasses:
 * - Big pharma:      high EstablishedDrugWeight, high PatentProtectedRevenueShare, real LOE exposure.
 * - Clinical-stage:  PipelineDrugWeight dominant, LoeExposureShare 0.0 (nothing marketed to lose).
 * - Generic/specialty: PipelineDrugWeight ~0.0 and PatentProtectedRevenueShare 0.0, which collapses
 *   the readout hazard and secular growth to commodity manufacturing levels; the margin is the ticker's own.
 */
class BiotechBusinessModel extends StandardCorporateBusinessModel
{
    // --- Firm-Level Common Factor ---
    /** One-factor loading of each stream on the firm-wide demand innovation (rho^2 = 56%). A concentrated pipeline is one scientific bet: a readout, a label change or a safety signal moves marketed franchise and pipeline together. */
    public const FIRM_FACTOR_LOADING = 0.75;
    /** Two-factor loading on the persistent sector demand factor (rho_s^2 = 9%). Prescription demand is set by the molecule and its label, not by the therapeutics cycle. */
    public const SECTOR_FACTOR_LOADING = 0.30;

    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Prescriptions are non-discretionary; therapies rarely substitute. */
    public const OPERATING_CYCLICALITY = 0.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.20;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.20;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::BIOTECH;
    /** Multi-year API supply agreements and validated second sources hold the purchase price steady well past a spot move. */
    public const INPUT_COST_LAG_YEARS = 1.00;

    // --- Reporting Incentives ---
    /** Propensity to steer reported earnings toward consensus with accruals. Little marketed revenue to shift and milestone income is contractually dated, so there is not much to reclassify even when the incentive is there. */
    public const EARNINGS_MANAGEMENT_PROPENSITY = 0.25;

    // --- Pricing Power ---
    /** Exclusivity is administered pricing: a patented therapy on-label has no substitute, so list prices are set rather than met. Generic and specialty archetypes are tuned down per ticker. */
    public const PRICING_POWER_INDEX = 0.85;

    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. Clinical-stage science teams are paid heavily in equity. */
    public const STOCK_COMPENSATION_INTENSITY = 0.10;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for routine biotech pipeline drug progress. */
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    /** Standard forecasting error on pipeline trial progress and royalty milestones. */
    public const BASE_COVERAGE_ERROR = 0.05;
    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.10;
    /** Public consensus visibility during binary FDA regulatory announcements and Phase III readouts. */
    public const EVENT_BASE_VISIBILITY = 0.85;
    /** Minimum visibility floor during major clinical trial announcements. */
    public const EVENT_MIN_VISIBILITY = 0.70;

    // --- Dual-Stream Biotech Portfolio Architecture ---
    /** Baseline fraction of revenue derived from commercially marketed, patent-protected established pharmaceuticals. */
    public const ESTABLISHED_DRUG_WEIGHT = 0.70;
    /** Baseline fraction of revenue derived from recently launched assets plus collaboration and milestone income. */
    public const PIPELINE_DRUG_WEIGHT    = 0.30;
    /** Volatility multiplier for recurring commercial prescription therapeutic volumes. */
    public const COMMERCIAL_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier for lumpy pre-commercial clinical milestone and licensing revenue. */
    public const PIPELINE_VARIANCE_SCALAR = 0.45;
    /** AR(1) persistence of recurring commercial prescription volumes across quarters. */
    public const COMMERCIAL_PERSISTENCE_PHI = 0.40;
    /** AR(1) persistence of lumpy collaboration and milestone revenue across quarters. */
    public const PIPELINE_PERSISTENCE_PHI = 0.10;

    // --- Persisted Structural State Keys ---
    /** Cumulative commercial franchise index: permanent revenue base carried across quarters. */
    public const STATE_FRANCHISE_INDEX = 'state:commercial_franchise';
    /** Next quarter's structural revenue multiplier, 1 + weight x (franchise - 1): the approved book the firm's capital now earns on. */
    public const STATE_STRUCTURAL_MULTIPLIER = 'state:structural_franchise_multiplier';
    /** Quarters of marketing exclusivity remaining on the portfolio before the next patent cliff. */
    public const STATE_EXCLUSIVITY_QUARTERS = 'state:exclusivity_quarters';
    /** Quarters elapsed inside an active loss-of-exclusivity erosion window (0.0 = no active cliff). */
    public const STATE_LOE_ELAPSED_QUARTERS = 'state:loe_elapsed_quarters';
    /** Live share of marketed revenue still under patent or regulatory exclusivity protection. */
    public const STATE_PROTECTED_SHARE = 'state:patent_protected_share';
    /** Marketed revenue still under exclusivity, in franchise-index units: a cliff takes it away at onset, an approval adds to it. */
    public const STATE_PROTECTED_FRANCHISE = 'state:protected_franchise';
    /** Share of the marketed franchise the active (or next) loss of exclusivity erodes, fixed for the whole window. */
    public const STATE_LOE_EXPOSURE = 'state:loe_exposure';
    /** Next quarter's known erosion of the marketed book as a share of structural revenue: what the exclusivity clock already says, handed to expected revenue. */
    public const STATE_KNOWN_COMMERCIAL_SHIFT = 'state:known_commercial_shift';
    /** Prior-quarter R&D reinvestment ratio relative to the patent replacement rate. */
    public const STATE_RND_REPLACEMENT_RATIO = 'state:rnd_replacement_ratio';

    // --- Clinical Development Hazard (industry phase-transition rates) ---
    /** Expected pivotal readouts per quarter at full pipeline concentration, before R&D intensity scaling. */
    public const PIVOTAL_READOUT_HAZARD = 0.40;
    /** Probability a pivotal Phase III trial meets its primary endpoint (industry Phase III transition rate). */
    public const PHASE_III_SUCCESS_RATE = 0.58;
    /** Probability a filed NDA/BLA clears regulatory review after a successful pivotal readout. */
    public const REGULATORY_APPROVAL_RATE = 0.91;
    /** Elasticity of readout frequency to R&D reinvestment above or below the patent replacement rate. */
    public const READOUT_HAZARD_RND_ELASTICITY = 0.60;
    /** Lower clamp on the R&D intensity multiplier applied to the readout hazard. */
    public const MIN_READOUT_HAZARD_MULTIPLIER = 0.25;
    /** Upper clamp on the R&D intensity multiplier applied to the readout hazard. */
    public const MAX_READOUT_HAZARD_MULTIPLIER = 2.00;
    /** Ceiling on the quarterly probability that a portfolio produces a pivotal readout. */
    public const MAX_QUARTERLY_READOUT_HAZARD = 0.50;

    // --- Approval Persistence & Franchise Physics ---
    /** Permanent step-up in the commercial revenue base contributed by a newly launched approved asset. */
    public const APPROVAL_FRANCHISE_STEP = 0.12;
    /** Upper bound on the cumulative commercial franchise index (portfolio launch capacity). */
    public const MAX_FRANCHISE_INDEX = 3.00;
    /** Lower bound on the commercial franchise index after repeated generic erosion cycles. */
    public const MIN_FRANCHISE_INDEX = 0.15;
    /** Marketing exclusivity granted to a newly approved asset, in quarters (~10 years of effective patent life). */
    public const NEW_APPROVAL_EXCLUSIVITY_QUARTERS = 40.0;
    /** Single-quarter upfront and milestone payment multiplier on collaboration revenue at approval. */
    public const APPROVAL_MILESTONE_REV_MULT = 1.20;
    /** Launch-quarter variable cost penalty from commercial build-out and payer access spending. */
    public const APPROVAL_LAUNCH_COST_MARGIN_PENALTY = 0.03;
    /** Collaboration and milestone revenue retained in the quarter a pivotal trial misses its endpoint. */
    public const TRIAL_FAILURE_PIPELINE_RETENTION = 0.60;
    /** Variable margin penalty from expensing a terminated late-stage development program. */
    public const TRIAL_FAILURE_MARGIN_PENALTY = 0.10;
    /** Continuous variable margin sensitivity to interim Phase II/III clinical trial readouts. */
    public const CONTINUOUS_PIPELINE_MARGIN_SENSITIVITY = 0.015;

    // --- Exclusivity Clock & Loss of Exclusivity (LOE) Erosion ---
    /** Default remaining marketing exclusivity on the commercial portfolio, in quarters (~7 years). */
    public const DEFAULT_EXCLUSIVITY_QUARTERS = 28.0;
    /** Default share of commercial revenue exposed to the next loss of exclusivity. */
    public const DEFAULT_LOE_EXPOSURE_SHARE = 0.35;
    /** Default share of marketed revenue still under patent or regulatory exclusivity protection. */
    public const DEFAULT_PATENT_PROTECTED_SHARE = 0.85;
    /** Default share of the commercial book made up of biologics rather than small molecules. */
    public const DEFAULT_BIOLOGIC_REVENUE_SHARE = 0.50;
    /** Quarterly erosion hazard for small-molecule brands at generic entry: -ln(0.15)/4, ~85% volume loss in one year. */
    public const SMALL_MOLECULE_LOE_HAZARD = 0.4742;
    /** Quarterly erosion hazard for biologics under biosimilar entry: -ln(0.60)/10, ~40% loss over two and a half years. */
    public const BIOLOGIC_LOE_HAZARD = 0.0511;
    /** Quarters an erosion window runs before the residual off-patent revenue is folded into the permanent base. */
    public const LOE_EROSION_WINDOW_QUARTERS = 12.0;
    /** Variable margin penalty from price concessions defending an off-patent brand against generic entrants. */
    public const LOE_PRICE_DEFENSE_MARGIN_PENALTY = 0.04;

    // --- Patent Moat & Capital Structure Rails ---
    /** Operating margin mean reversion speed: slower speed reflects multi-year patent monopoly protection. */
    public const PATENT_REVERSION_SPEED    = 2.5;
    /** Minimum interest coverage ratio required to permit recapitalization for binary R&D models. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Target leverage as a share of the debt tolerance; below it the firm is under-levered. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    // --- Secular Growth Rails ---
    /** Secular growth for a fully patent-protected branded portfolio (demographics plus branded pricing). */
    public const PATENTED_SECULAR_GROWTH_RATE = 0.045;
    /** Secular growth for unprotected generic manufacturing, where volume gains offset relentless price erosion. */
    public const GENERIC_SECULAR_GROWTH_RATE  = 0.010;

    public function getReversionSpeed(): float
    {
        return 0.15;
    }

    public function getMoatSpread(): float
    {
        return 0.015;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        return 0.125;
    }

    /**
     * Branded portfolios compound on demographics and branded pricing; unprotected generic
     * manufacturing barely grows because volume gains are consumed by price erosion.
     */
    public function getSecularGrowthRate(Stock $stock): float
    {
        $protectedShare = $this->resolveProtectedShare($stock);

        return self::GENERIC_SECULAR_GROWTH_RATE
            + ((self::PATENTED_SECULAR_GROWTH_RATE - self::GENERIC_SECULAR_GROWTH_RATE) * $protectedShare);
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.20, 'revenue_weight' => 0.80];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        // Biotechs are structural secular growth stories driven by R&D and demographics, making them
        // largely decoupled from standard macro business cycles. The demand shift carries instead what the
        // exclusivity clock already says about this quarter: a patent cliff is dated and analysts read it, so
        // the erosion belongs in EXPECTED revenue. It runs through utilization so the cost base is slow to
        // follow a collapsing brand; the approved book itself is structural (getStructuralRevenueMultiplier).
        return [
            'macro_demand_shift' => (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_KNOWN_COMMERCIAL_SHIFT] ?? 0.0),
            'pricing_power_multiplier' => 1.0,
        ];
    }

    /**
     * The approved franchise scales what the firm's capital earns and the cost base with it: a launched drug is
     * new capacity, not overtime on the old one. Before the state exists the franchise rode in the demand shift,
     * so the neutral 1.0 keeps that quarter's expected revenue consistent with the shift it was persisted with.
     */
    public function getStructuralRevenueMultiplier(Stock $stock): float
    {
        return max(self::MIN_FRANCHISE_INDEX, (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_STRUCTURAL_MULTIPLIER] ?? 1.0));
    }

    /**
     * The erosion factor the exclusivity clock will apply next quarter, from the state being persisted now:
     * the clock ticks once more, a cliff that is due starts eroding, an active erosion advances. Nothing here
     * is drawn, so the forecast and next quarter's physics agree exactly.
     */
    private function nextQuarterErosionFactor(float $clock, float $elapsed, float $exposureShare, float $biologicShare): float
    {
        if ($elapsed > 0.0) {
            $elapsed += 1.0;
        } elseif ($clock - 1.0 <= 0.0 && $exposureShare > 0.0) {
            $elapsed = 1.0;
        }

        return 1.0 - ($exposureShare * $this->loeErodedShare($biologicShare, $elapsed));
    }

    /**
     * The share of the marketed book the next loss of exclusivity takes: the ticker's exposure, but never more than
     * is still protected, since revenue already off patent cannot lose exclusivity a second time.
     */
    private function resolveCliffExposure(float $exposureParam, float $protectedFranchise, float $franchise): float
    {
        return max(0.0, min(1.0, $exposureParam, $protectedFranchise / max(self::MIN_FRANCHISE_INDEX, $franchise)));
    }

    /**
     * Share of the exposed franchise lost after `elapsed` quarters off exclusivity: the revenue-weighted mixture
     * of the two modality survival curves, each decaying on its own hazard.
     */
    private function loeErodedShare(float $biologicShare, float $elapsed): float
    {
        if ($elapsed <= 0.0) {
            return 0.0;
        }

        return ($biologicShare * (1.0 - exp(-self::BIOLOGIC_LOE_HAZARD * $elapsed)))
            + ((1.0 - $biologicShare) * (1.0 - exp(-self::SMALL_MOLECULE_LOE_HAZARD * $elapsed)));
    }

    /**
     * Biotech is driven by a persistent commercial franchise that approvals step up and patent
     * cliffs erode, plus lumpy collaboration income exposed to binary pivotal trial readouts.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EstablishedDrugWeight->value       => self::ESTABLISHED_DRUG_WEIGHT,
            ModelParam::PipelineDrugWeight->value          => self::PIPELINE_DRUG_WEIGHT,
            ModelParam::LoeExposureShare->value            => self::DEFAULT_LOE_EXPOSURE_SHARE,
            ModelParam::ExclusivityQuarters->value         => self::DEFAULT_EXCLUSIVITY_QUARTERS,
            ModelParam::BiologicRevenueShare->value        => self::DEFAULT_BIOLOGIC_REVENUE_SHARE,
            ModelParam::PatentProtectedRevenueShare->value => self::DEFAULT_PATENT_PROTECTED_SHARE,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'commercial_therapeutics'       => $params[ModelParam::EstablishedDrugWeight],
            'pipeline_licensing_milestones' => $params[ModelParam::PipelineDrugWeight],
        ]);

        $establishedWeight = $activeWeights['commercial_therapeutics'];
        $pipelineWeight    = $activeWeights['pipeline_licensing_milestones'];

        // Independent stream Z-scores with AR(1) persistence
        $establishedZ = $streams->generateZ('commercial_therapeutics', self::COMMERCIAL_PERSISTENCE_PHI);
        $pipelineZ    = $streams->generateZ('pipeline_licensing_milestones', self::PIPELINE_PERSISTENCE_PHI);

        // --- Exclusivity Clock: schedule the next patent cliff and advance any active erosion ---
        $franchise = $streams->getPersistedState(self::STATE_FRANCHISE_INDEX, 1.0);
        $clock     = $streams->getPersistedState(self::STATE_EXCLUSIVITY_QUARTERS, $params[ModelParam::ExclusivityQuarters]);
        $elapsed   = $streams->getPersistedState(self::STATE_LOE_ELAPSED_QUARTERS, 0.0);
        $protectedFranchise = $streams->getPersistedState(
            self::STATE_PROTECTED_FRANCHISE,
            $franchise * max(0.0, min(1.0, $params[ModelParam::PatentProtectedRevenueShare]))
        );
        $exposureShare = $elapsed > 0.0
            ? $streams->getPersistedState(self::STATE_LOE_EXPOSURE, $this->resolveCliffExposure($params[ModelParam::LoeExposureShare], $protectedFranchise, $franchise))
            : $this->resolveCliffExposure($params[ModelParam::LoeExposureShare], $protectedFranchise, $franchise);

        $eventType = null;
        if ($elapsed > 0.0) {
            $elapsed += 1.0;
        } else {
            $clock -= 1.0;
            if ($clock <= 0.0 && $exposureShare > 0.0) {
                $elapsed = 1.0;
                $eventType = ShockEvent::BIOTECH_PATENT_CLIFF;
                // The whole exposed book goes off patent the day generics may enter; erosion of its sales follows.
                $protectedFranchise = max(0.0, $protectedFranchise - ($exposureShare * $franchise));
            }
        }

        // Exponential LOE erosion of the exposed franchise, each modality on its own hazard.
        $biologicShare = max(0.0, min(1.0, $params[ModelParam::BiologicRevenueShare]));
        $erodedFraction = $exposureShare * $this->loeErodedShare($biologicShare, $elapsed);
        $erosionFactor  = 1.0 - $erodedFraction;

        $commercialMultiplier = $franchise * $erosionFactor;

        // Expected revenue already carries the approved franchise (structural multiplier) and this quarter's
        // known erosion (demand shift), both persisted last quarter; strip them to get the base the franchise
        // and the erosion are applied to, so neither is counted twice.
        $knownShift = $streams->getPersistedState(self::STATE_KNOWN_COMMERCIAL_SHIFT, 0.0);
        $baseRevenue = $expectedRevenue / (max(0.1, 1.0 + $knownShift) * $this->getStructuralRevenueMultiplier($stock));

        $reimbursementBaseline = \App\Service\Macro\MacroEngine::TARGET_INFLATION - \App\Service\Macro\MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET;
        $reimbursementShift    = $macroState->reimbursementRateGrowth - $reimbursementBaseline;
        $establishedRevenue    = max(0.0, $baseRevenue * $establishedWeight * $commercialMultiplier * (1.0 + ($establishedZ * ($baselineVol * self::COMMERCIAL_VARIANCE_SCALAR)) + $reimbursementShift));
        $pipelineRevenue       = max(0.0, $baseRevenue * $pipelineWeight    * (1.0 + ($pipelineZ    * ($baselineVol * self::PIPELINE_VARIANCE_SCALAR))));

        $preEventPipelineRevenue = $pipelineRevenue;
        $preErosionEstablished   = $erosionFactor > 0.0 ? ($establishedRevenue / $erosionFactor) : $establishedRevenue;

        // Continuous pipeline clinical progress smoothly adjusts variable margin. Trial spend itself is
        // capitalized as intangible R&D CapEx in this architecture, so progress reads through as
        // higher-value royalty and collaboration mix rather than as near-term operating expense.
        $patentModifier = -self::CONTINUOUS_PIPELINE_MARGIN_SENSITIVITY * $pipelineZ * $pipelineWeight;

        // --- Pivotal Readout: Bernoulli hazard scaled by pipeline breadth and R&D replacement intensity ---
        $rndRatio = $streams->getPersistedState(self::STATE_RND_REPLACEMENT_RATIO, 1.0);
        $rndMultiplier = min(
            self::MAX_READOUT_HAZARD_MULTIPLIER,
            max(self::MIN_READOUT_HAZARD_MULTIPLIER, pow(max(0.01, $rndRatio), self::READOUT_HAZARD_RND_ELASTICITY))
        );
        $readoutHazard = min(self::MAX_QUARTERLY_READOUT_HAZARD, self::PIVOTAL_READOUT_HAZARD * $pipelineWeight * $rndMultiplier);

        if ($readoutHazard > 0.0 && $mathUtility->checkProbability($readoutHazard)) {
            if ($mathUtility->checkProbability(self::PHASE_III_SUCCESS_RATE * self::REGULATORY_APPROVAL_RATE)) {
                // Upfront and milestone cash lands this quarter; the launched asset is permanent.
                $pipelineRevenue *= self::APPROVAL_MILESTONE_REV_MULT;
                $patentModifier  += self::APPROVAL_LAUNCH_COST_MARGIN_PENALTY * $pipelineWeight;

                // Revenue-weighted average remaining exclusivity: the new asset is APPROVAL_FRANCHISE_STEP of the
                // existing book whatever its size, so the franchise level cancels out of the weights.
                $clock = ($clock + (self::APPROVAL_FRANCHISE_STEP * self::NEW_APPROVAL_EXCLUSIVITY_QUARTERS))
                    / (1.0 + self::APPROVAL_FRANCHISE_STEP);
                $protectedFranchise += self::APPROVAL_FRANCHISE_STEP * $franchise;
                $franchise = min(self::MAX_FRANCHISE_INDEX, $franchise * (1.0 + self::APPROVAL_FRANCHISE_STEP));

                // A patent cliff onset is the larger structural event, so it keeps the public narrative.
                $eventType ??= ShockEvent::BIOTECH_DRUG_APPROVAL;
            } else {
                // A failed pivotal trial destroys future optionality and collaboration income.
                // It does NOT touch marketed commercial revenue: the asset never had any.
                $pipelineRevenue *= self::TRIAL_FAILURE_PIPELINE_RETENTION;
                $patentModifier  += self::TRIAL_FAILURE_MARGIN_PENALTY * $pipelineWeight;
                $eventType ??= ShockEvent::BIOTECH_TRIAL_SETBACK;
            }
        }

        if ($elapsed > 0.0) {
            // Price concessions defending the off-patent brand. Reverse operating leverage against
            // the collapsing revenue base is produced by the engine's fixed cost bridge.
            $patentModifier += self::LOE_PRICE_DEFENSE_MARGIN_PENALTY * $exposureShare * $establishedWeight;
        }

        $streamRevenues = [
            'commercial_therapeutics'       => $establishedRevenue,
            'pipeline_licensing_milestones' => $pipelineRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // API, fill-finish and technician costs reach the cost base behind long supply agreements and are
        // recovered on-label at exclusivity pricing.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $patentModifier + $inputCostDrag);

        // Once the erosion window closes, the residual off-patent level becomes the permanent base
        // and the clock is reset to the next franchise's remaining patent life.
        $marketedFranchise = $franchise * $erosionFactor;
        if ($elapsed >= self::LOE_EROSION_WINDOW_QUARTERS) {
            $franchise = max(self::MIN_FRANCHISE_INDEX, $marketedFranchise);
            $marketedFranchise = $franchise;
            $elapsed = 0.0;
            $clock = max($clock, $params[ModelParam::ExclusivityQuarters]);
        }

        // The off-patent residual stays unprotected after it is folded into the base; only approvals restore the
        // protected share. It lowers secular growth from the following quarter.
        $protectedShare = max(0.0, min(1.0, $protectedFranchise / max(self::MIN_FRANCHISE_INDEX, $marketedFranchise)));
        $nextExposure = $elapsed > 0.0
            ? $exposureShare
            : $this->resolveCliffExposure($params[ModelParam::LoeExposureShare], $protectedFranchise, $franchise);

        $streams->registerState(self::STATE_FRANCHISE_INDEX, $franchise);
        $streams->registerState(self::STATE_EXCLUSIVITY_QUARTERS, $clock);
        $streams->registerState(self::STATE_LOE_ELAPSED_QUARTERS, $elapsed);
        $streams->registerState(self::STATE_PROTECTED_SHARE, $protectedShare);
        $streams->registerState(self::STATE_PROTECTED_FRANCHISE, $protectedFranchise);
        $streams->registerState(self::STATE_LOE_EXPOSURE, $nextExposure);
        $streams->registerState(self::STATE_RND_REPLACEMENT_RATIO, $rndRatio);
        $structuralMultiplier = max(self::MIN_FRANCHISE_INDEX, 1.0 + ($establishedWeight * ($franchise - 1.0)));
        $streams->registerState(self::STATE_STRUCTURAL_MULTIPLIER, $structuralMultiplier);
        $streams->registerState(
            self::STATE_KNOWN_COMMERCIAL_SHIFT,
            $establishedWeight * $franchise * ($this->nextQuarterErosionFactor($clock, $elapsed, $nextExposure, $biologicShare) - 1.0) / $structuralMultiplier
        );

        // What was expected of each stream, the known franchise and erosion included: only the draw is a surprise.
        $establishedBase = max(1.0, $baseRevenue * $establishedWeight * $commercialMultiplier);
        $pipelineBase    = max(1.0, $baseRevenue * $pipelineWeight);
        $establishedShock = ($establishedRevenue - $establishedBase) / $establishedBase;
        $pipelineShock    = ($pipelineRevenue - $pipelineBase) / $pipelineBase;
        $observableShockZ = ($establishedShock * $establishedWeight) + ($pipelineShock * $pipelineWeight);

        // Standardize the discrete event's revenue impact into Z units of pipeline dispersion.
        $eventZ = null;
        if ($eventType !== null) {
            $eventRevenueDelta = ($pipelineRevenue - $preEventPipelineRevenue)
                + ($establishedRevenue - $preErosionEstablished);
            $eventSigma = max(1.0, $expectedRevenue * $baselineVol * self::PIPELINE_VARIANCE_SCALAR);
            $eventZ = $eventRevenueDelta / $eventSigma;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$establishedZ, $pipelineZ], $eventZ),
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        // Dual-mode: FDA/trial announcements are binary public events (85% visible, 70% floor).
        // Routine operational variance is low-visibility (~20%, 10% floor).
        return new SectorCoverageProfile(
            baseVisibility: self::BASE_COVERAGE_VISIBILITY,
            errorStdDev: self::BASE_COVERAGE_ERROR,
            minVisibility: self::BASE_COVERAGE_MIN_VISIBILITY,
            eventBaseVisibility: self::EVENT_BASE_VISIBILITY,
            eventMinVisibility: self::EVENT_MIN_VISIBILITY,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // Patents provide multi-year protection, so excess margins revert more slowly than standard tech
        return self::PATENT_REVERSION_SPEED;
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.10; // Clinical drug inventory and specialized biologic materials buffer
    }

    /**
     * R&D pays off through the pipeline only: the replacement ratio sets next quarter's pivotal readout hazard (a
     * portfolio starved of research stops producing late-stage readouts) and nothing else. The shared margin
     * drift would pay it twice, since approvals already step the franchise up and the exclusivity clock already
     * erodes an unreplaced book.
     */
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $this->persistState($stock, self::STATE_RND_REPLACEMENT_RATIO, max(0.0, $reinvestmentRatio));
    }

    /** Clinical-stage biotechs trade entirely on pipeline rNPV and cash runway, not on book. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return 0.0;
    }


    /**
     * Live share of revenue under patent or regulatory exclusivity, tracked by the physics loop and
     * falling as scheduled losses of exclusivity erode the marketed franchise.
     */
    private function resolveProtectedShare(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PatentProtectedRevenueShare->value => self::DEFAULT_PATENT_PROTECTED_SHARE,
        ]);
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $share = (float) ($momentum[self::STATE_PROTECTED_SHARE] ?? $params[ModelParam::PatentProtectedRevenueShare]);

        return max(0.0, min(1.0, $share));
    }

    /**
     * Merges a single structural state value into the stock's persisted stream state map.
     * Used outside the quarterly physics pass, where no StreamContext is in scope.
     */
    private function persistState(Stock $stock, string $key, float $value): void
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $momentum[$key] = $value;
        $stock->setEarningsMomentumZ($momentum);
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     * Biotech's physics is R&D-cycle driven: the only macro reads are the administered reimbursement update and
     * the real wage gap behind the labor share of the input basket.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'reimbursement_rate_growth',
            'real_wage_gap',
        ];
    }
}
