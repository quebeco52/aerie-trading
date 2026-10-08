<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Data\ModelParam;
use App\DTO\ModelParameters;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Biotechnology and Specialty Drug Manufacturers.
 *
 * Financial Physics:
 * - CapEx represents high-risk R&D that creates intangible patent assets.
 * - Clinical development is a phase-transition (Markov) chain: a stock of late-stage programmes reads out at one
 *   over the Phase III duration each, succeeds at published industry transition rates, and is refilled by R&D.
 * - The marketed book is a ledger of drug cohorts, each with its own peak size, Bass launch ramp and exclusivity
 *   expiry. An approval adds a cohort; at expiry the cohort erodes on its modality hazard (small molecules collapse
 *   within a year, biologics bleed over several) and is never folded back into a protected base.
 * - Peak size per approval is set by the replacement identity: at replacement R&D the expected launches exactly
 *   replace the revenue that expiries take, so the book is stationary.
 * - Reverse operating leverage at LOE is produced by the engine itself (fixed costs are carried
 *   separately in the EBIT bridge), so this model only adds the price-defense discounting.
 * - Inelastic demand: highly immune to macroeconomic output gap recessions.
 *
 * Archetypes are expressed through StockModelTuning overrides rather than subclasses:
 * - Big pharma:      high EstablishedDrugWeight, high PatentProtectedRevenueShare, several late-stage assets.
 * - Clinical-stage:  PipelineDrugWeight dominant, LoeExposureShare 0.0 (nothing marketed to lose).
 * - Generic/specialty: LateStageAssetCount 0.0 and PatentProtectedRevenueShare 0.0: the whole book is mature
 *   established product, nothing launches and nothing expires; the margin is the ticker's own.
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
    /** Stock-based compensation (ASC 718) on a marketed book: 1.71% of revenue, US pharmaceutical drugs (Damodaran, Employee data by industry, 228 firms). */
    public const MARKETED_STOCK_COMPENSATION_INTENSITY = 0.0171;
    /** Stock-based compensation on pipeline and collaboration revenue, whose science teams are paid in equity: 7.20%, US biotechnology (Damodaran, 496 firms). */
    public const PIPELINE_STOCK_COMPENSATION_INTENSITY = 0.0720;

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
    /** Marketed commercial book in franchise units (1.0 = the seeded book): every cohort on its launch, exclusivity and erosion path. */
    public const STATE_FRANCHISE_INDEX = 'state:commercial_franchise';
    /** Next quarter's structural revenue multiplier, 1 + weight x (launched book - 1): the approved book the firm's capital now earns on. */
    public const STATE_STRUCTURAL_MULTIPLIER = 'state:structural_franchise_multiplier';
    /** Live share of marketed revenue still under patent or regulatory exclusivity protection. */
    public const STATE_PROTECTED_SHARE = 'state:patent_protected_share';
    /** Next quarter's known erosion of the marketed book as a share of structural revenue: what the exclusivity dates already say, handed to expected revenue. */
    public const STATE_KNOWN_COMMERCIAL_SHIFT = 'state:known_commercial_shift';
    /** Prior-quarter R&D reinvestment relative to what keeps the book level: replacement plus capital growth with the firm's market. */
    public const STATE_RND_REPLACEMENT_RATIO = 'state:rnd_replacement_ratio';
    /** Late-stage (Phase III) programmes in the pipeline: every readout draws it down, R&D refills it. */
    public const STATE_LATE_STAGE_ASSETS = 'state:late_stage_assets';
    /** Prefix of the cohort ledger, one slot per marketed drug: state:cohort:{slot}:{peak|adoption|exclusivity}. */
    public const STATE_COHORT_PREFIX = 'state:cohort:';
    /** Off-patent biologic revenue moved out of the ledger after its erosion window, still decaying on the biosimilar hazard. */
    public const STATE_OFF_PATENT_BIOLOGIC = 'state:off_patent_biologic';
    /** Off-patent small-molecule revenue moved out of the ledger after its erosion window, still decaying on the generic hazard. */
    public const STATE_OFF_PATENT_SMALL_MOLECULE = 'state:off_patent_small_molecule';
    /** Mature established-products book the lore seeds as already off patent: its exclusivity rents are gone and it is held like a generic line. */
    public const STATE_ESTABLISHED_PRODUCTS = 'state:established_products';

    // --- Late-Stage Pipeline (phase-transition rates) ---
    /** Mean Phase III duration in years (DiMasi, Grabowski & Hansen 2016, ~30.5 months): each late-stage programme reads out at a hazard of one over it. */
    public const PHASE_III_DURATION_YEARS = 2.54;
    /** Probability a pivotal Phase III trial meets its primary endpoint (BIO/Informa/QLS 2021, programmes 2011-2020: 57.8%). */
    public const PHASE_III_SUCCESS_RATE = 0.578;
    /** Probability a filed NDA/BLA clears regulatory review (BIO/Informa/QLS 2021, programmes 2011-2020: 90.6%). */
    public const REGULATORY_APPROVAL_RATE = 0.906;
    /** Value-weighted late-stage programmes of a branded portfolio with no lore of its own. */
    public const DEFAULT_LATE_STAGE_ASSETS = 3.0;
    /** Elasticity of Phase III entries to R&D reinvestment above or below the patent replacement rate. */
    public const PIPELINE_REFILL_RND_ELASTICITY = 0.60;
    /** Lower clamp on the R&D intensity multiplier applied to Phase III entries. */
    public const MIN_PIPELINE_REFILL_MULTIPLIER = 0.25;
    /** Upper clamp on the R&D intensity multiplier applied to Phase III entries. */
    public const MAX_PIPELINE_REFILL_MULTIPLIER = 2.00;

    // --- Launch Ramp (Bass diffusion) ---
    /** Imitation-to-innovation ratio q/p of the Bass adoption curve (Sultan, Farley & Lehmann 1990 meta-analysis: q 0.38, p 0.03). */
    public const BASS_IMITATION_RATIO = 12.667;
    /** Years from launch to peak sales: ~8 for first-in-class, 3-4 for followers (Robey & David 2018), about a third of approvals first-in-class (Lanthier et al. 2013). */
    public const LAUNCH_YEARS_TO_PEAK = 5.0;
    /** Share of plateau adoption reached at peak sales on the cumulative Bass curve. */
    public const LAUNCH_PEAK_ADOPTION = 0.90;

    // --- Approved Cohorts ---
    /** Slots in the marketed-drug ledger: ~10 protected launches and ~3 in their erosion window at steady state, plus room for the seeded book. */
    public const MAX_COHORTS = 24;
    /** Protected cohorts the lore book is seeded as: the lead franchise plus followers on evenly staggered expiries. */
    public const SEED_PROTECTED_COHORTS = 4;
    /** Lower bound on the structural franchise multiplier, so the revenue base a quarter is stripped back to stays finite. */
    public const MIN_FRANCHISE_INDEX = 0.15;
    /** Market exclusivity granted to a newly approved asset, in quarters (~12.6 years for NMEs above $100m in sales, Grabowski, Long, Mortimer & Boyo 2016). */
    public const NEW_APPROVAL_EXCLUSIVITY_QUARTERS = 50.0;
    /** Swing in collaboration and milestone revenue between a pivotal success and a miss, centred on the odds (+29% / -31% at a 52% approval rate) so the stream stays unbiased. */
    public const READOUT_MILESTONE_SWING = 0.60;
    /** Launch-quarter variable cost penalty from commercial build-out and payer access spending. */
    public const APPROVAL_LAUNCH_COST_MARGIN_PENALTY = 0.03;
    /** Variable margin penalty from expensing the wind-down of a terminated late-stage development program. */
    public const TRIAL_FAILURE_MARGIN_PENALTY = 0.10;
    /** Continuous variable margin sensitivity to interim Phase II/III clinical trial readouts. */
    public const CONTINUOUS_PIPELINE_MARGIN_SENSITIVITY = 0.015;

    // --- Loss of Exclusivity (LOE) Erosion ---
    /** Default remaining marketing exclusivity on the lead franchise, in quarters (~7 years). */
    public const DEFAULT_EXCLUSIVITY_QUARTERS = 28.0;
    /** Default share of commercial revenue in the lead franchise, the next to lose exclusivity. */
    public const DEFAULT_LOE_EXPOSURE_SHARE = 0.35;
    /** Default share of marketed revenue still under patent or regulatory exclusivity protection. */
    public const DEFAULT_PATENT_PROTECTED_SHARE = 0.85;
    /** Default share of the commercial book made up of biologics rather than small molecules. */
    public const DEFAULT_BIOLOGIC_REVENUE_SHARE = 0.50;
    /** Quarterly erosion hazard for small-molecule brands at generic entry: -ln(0.15)/4, ~85% volume loss in one year (Grabowski, Long & Mortimer 2014: 16% brand share at one year). */
    public const SMALL_MOLECULE_LOE_HAZARD = 0.4742;
    /** Quarterly erosion hazard for biologics under biosimilar entry: -ln(0.60)/10, ~40% loss over two and a half years. */
    public const BIOLOGIC_LOE_HAZARD = 0.0511;
    /** Quarters an expired cohort's erosion runs through utilization before the capacity base follows it down. */
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
    /** US retail prescription drug spending as a share of nominal GDP in 2000: $122.0B of $10,251B (CMS National Health Expenditure, Health, United States 2020-2021, Table HExpType). Brands hold a share of spending that is largely unchanged over two decades (IQVIA), so they grow at the total's rate. */
    public const PATENTED_SHARE_2000 = 0.01190;
    /** The same share in 2019: $369.7B of $21,381B. */
    public const PATENTED_SHARE_2019 = 0.01729;
    /** Years between the two prescription-spending benchmarks. */
    public const PATENTED_SHARE_WINDOW_YEARS = 19.0;
    /** US generic drug spending as a share of nominal GDP in 2010: ~$78B (IMS, second-hand) of $15,049B. */
    public const GENERIC_SHARE_2010 = 0.00518;
    /** The same share in 2018: $103B, 21.3% of $482B invoice spending (IQVIA, Medicine Use and Spending in the U.S.), of $20,533B. */
    public const GENERIC_SHARE_2018 = 0.00502;
    /** Years between the two generic benchmarks. */
    public const GENERIC_SHARE_WINDOW_YEARS = 8.0;

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
     * Trend real growth plus the measured drift in each book's share of GDP, blended by the share still under
     * exclusivity: branded spending outgrew the economy, generic spending shrank against it as prices eroded.
     */
    public function getSecularGrowthRate(Stock $stock): float
    {
        $protectedShare = $this->resolveProtectedShare($stock);
        $generic = self::genericSecularGrowthRate();

        return $generic + ((self::patentedSecularGrowthRate() - $generic) * $protectedShare);
    }

    /** Secular real growth of a fully patent-protected branded book. */
    public static function patentedSecularGrowthRate(): float
    {
        return MacroEngine::TREND_REAL_GROWTH + MathUtility::gdpShareDrift(self::PATENTED_SHARE_2000, self::PATENTED_SHARE_2019, self::PATENTED_SHARE_WINDOW_YEARS);
    }

    /** Secular real growth of an unprotected generic book. */
    public static function genericSecularGrowthRate(): float
    {
        return MacroEngine::TREND_REAL_GROWTH + MathUtility::gdpShareDrift(self::GENERIC_SHARE_2010, self::GENERIC_SHARE_2018, self::GENERIC_SHARE_WINDOW_YEARS);
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.20, 'revenue_weight' => 0.80];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        // Biotechs are structural secular growth stories driven by R&D and demographics, making them
        // largely decoupled from standard macro business cycles. The demand shift carries instead what the
        // exclusivity dates already say about this quarter: a patent cliff is dated and analysts read it, so
        // the erosion belongs in EXPECTED revenue. It runs through utilization so the cost base is slow to
        // follow a collapsing brand; the launched book itself is structural (getStructuralRevenueMultiplier).
        return [
            'macro_demand_shift' => (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_KNOWN_COMMERCIAL_SHIFT] ?? 0.0),
            'pricing_power_multiplier' => 1.0,
        ];
    }

    /**
     * The launched book scales what the firm's capital earns and the cost base with it: a launched drug is
     * new capacity, not overtime on the old one. Before the state exists the franchise rode in the demand shift,
     * so the neutral 1.0 keeps that quarter's expected revenue consistent with the shift it was persisted with.
     */
    public function getStructuralRevenueMultiplier(Stock $stock): float
    {
        return max(self::MIN_FRANCHISE_INDEX, (float) (($stock->getEarningsMomentumZ() ?? [])[self::STATE_STRUCTURAL_MULTIPLIER] ?? 1.0));
    }

    /**
     * Peak quarterly revenue of one approval, in franchise units, from the replacement identity: at the target
     * pipeline, approvals per quarter x peak x lifetime revenue per unit of peak equals the protected book the lore
     * seeds, so launches replace what expiries take and the book is stationary at replacement R&D.
     */
    public function approvalPeakSize(float $targetAssets, float $protectedShare, float $biologicShare): float
    {
        $approvalsPerQuarter = max(0.0, $targetAssets) * $this->readoutHazardPerQuarter() * self::PHASE_III_SUCCESS_RATE * self::REGULATORY_APPROVAL_RATE;
        if ($approvalsPerQuarter <= 0.0) {
            return 0.0;
        }

        return max(0.0, $protectedShare) / ($approvalsPerQuarter * $this->lifetimeRevenuePerPeak($biologicShare));
    }

    /**
     * Quarters of peak revenue a launched drug earns over its life: the Bass ramp to its exclusivity expiry, then
     * the erosion tail of each modality to zero. Run on the same ledger mechanics the physics uses.
     */
    public function lifetimeRevenuePerPeak(float $biologicShare): float
    {
        $book = [
            'cohorts' => [['peak' => 1.0, 'adoption' => 0.0, 'exclusivity' => self::NEW_APPROVAL_EXCLUSIVITY_QUARTERS]],
            'biologic' => 0.0,
            'smallMolecule' => 0.0,
            'established' => 0.0,
        ];

        $total = 0.0;
        while ($book['cohorts'] !== []) {
            $book = $this->advanceBook($book)['book'];
            $total += $this->measureBook($book, $biologicShare)['marketed'];
            $book = $this->foldErodedCohorts($book, $biologicShare);
        }

        // What was folded out keeps decaying geometrically on each modality's hazard.
        $biologicRetention = exp(-self::BIOLOGIC_LOE_HAZARD);
        $smallMoleculeRetention = exp(-self::SMALL_MOLECULE_LOE_HAZARD);

        return $total
            + ($book['biologic'] * $biologicRetention / (1.0 - $biologicRetention))
            + ($book['smallMolecule'] * $smallMoleculeRetention / (1.0 - $smallMoleculeRetention));
    }

    /** Per-programme readout hazard over one quarter: one over the Phase III duration. */
    private function readoutHazardPerQuarter(): float
    {
        return 1.0 / (FinancialConstants::QUARTERS_PER_YEAR * self::PHASE_III_DURATION_YEARS);
    }

    /**
     * One quarter of cumulative Bass adoption (Bass 1969), stepped on the closed form so the ramp is exact at any
     * horizon: F(t) = (1 - e^{-(p+q)t}) / (1 + (q/p) e^{-(p+q)t}). The speed p+q is fixed by the time to peak.
     */
    private function bassAdoptionStep(float $adoption): float
    {
        $adoption = max(0.0, min(1.0, $adoption));
        $ratio = self::BASS_IMITATION_RATIO;
        $peakDecay = (1.0 - self::LAUNCH_PEAK_ADOPTION) / (1.0 + ($ratio * self::LAUNCH_PEAK_ADOPTION));
        $speed = -log($peakDecay) / self::LAUNCH_YEARS_TO_PEAK;

        $decay = ((1.0 - $adoption) / (1.0 + ($ratio * $adoption))) * exp(-$speed / FinancialConstants::QUARTERS_PER_YEAR);

        return (1.0 - $decay) / (1.0 + ($ratio * $decay));
    }

    /**
     * Share of an expired cohort lost after `elapsed` quarters off exclusivity: the revenue-weighted mixture of the
     * two modality survival curves, each decaying on its own hazard.
     */
    private function loeErodedShare(float $biologicShare, float $elapsed): float
    {
        if ($elapsed <= 0.0) {
            return 0.0;
        }

        return ($biologicShare * (1.0 - exp(-self::BIOLOGIC_LOE_HAZARD * $elapsed)))
            + ((1.0 - $biologicShare) * (1.0 - exp(-self::SMALL_MOLECULE_LOE_HAZARD * $elapsed)));
    }

    private static function cohortKey(int $slot, string $field): string
    {
        return self::STATE_COHORT_PREFIX . $slot . ':' . $field;
    }

    /**
     * The marketed book carried in from last quarter, or the lore book on first use.
     *
     * @return array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float}
     */
    private function openBook(StreamContext $streams, ModelParameters $params): array
    {
        $cohorts = [];
        for ($slot = 0; $slot < self::MAX_COHORTS; $slot++) {
            $peak = $streams->getPersistedState(self::cohortKey($slot, 'peak'), -1.0);
            if ($peak < 0.0) {
                break;
            }
            $cohorts[] = [
                'peak' => $peak,
                'adoption' => $streams->getPersistedState(self::cohortKey($slot, 'adoption'), 1.0),
                'exclusivity' => $streams->getPersistedState(self::cohortKey($slot, 'exclusivity'), 0.0),
            ];
        }

        if ($cohorts === [] && $streams->getPersistedState(self::STATE_LATE_STAGE_ASSETS, -1.0) < 0.0) {
            return $this->seedBook($streams, $params);
        }

        return [
            'cohorts' => $cohorts,
            'biologic' => $streams->getPersistedState(self::STATE_OFF_PATENT_BIOLOGIC, 0.0),
            'smallMolecule' => $streams->getPersistedState(self::STATE_OFF_PATENT_SMALL_MOLECULE, 0.0),
            'established' => $streams->getPersistedState(self::STATE_ESTABLISHED_PRODUCTS, 0.0),
        ];
    }

    /**
     * Translates the lore into a ledger: the lead franchise holds the exposure share and expires on the lore clock,
     * the rest of the protected book follows in equal cohorts on expiries staggered out to a new approval's life,
     * and the unprotected remainder is mature established product. All of it is at plateau. A firm that already
     * reported keeps the size of book it carried, so the switch does not move revenue; the split is the lore's,
     * since a share drained under the old single-clock physics would be held as established product for good.
     *
     * @return array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float}
     */
    private function seedBook(StreamContext $streams, ModelParameters $params): array
    {
        $book = max(0.0, $streams->getPersistedState(self::STATE_FRANCHISE_INDEX, 1.0));
        $protectedShare = max(0.0, min(1.0, $params[ModelParam::PatentProtectedRevenueShare]));
        $lead = max(0.0, min($protectedShare, $params[ModelParam::LoeExposureShare]));
        $leadExpiry = max(1.0, $params[ModelParam::ExclusivityQuarters]);
        $followers = self::SEED_PROTECTED_COHORTS - 1;
        $spacing = max(0.0, self::NEW_APPROVAL_EXCLUSIVITY_QUARTERS - $leadExpiry) / self::SEED_PROTECTED_COHORTS;

        $cohorts = [];
        if ($lead > 0.0) {
            $cohorts[] = ['peak' => $lead * $book, 'adoption' => 1.0, 'exclusivity' => $leadExpiry];
        }
        $followerSize = ($protectedShare - $lead) / $followers;
        for ($j = 1; $followerSize > 0.0 && $j <= $followers; $j++) {
            $cohorts[] = ['peak' => $followerSize * $book, 'adoption' => 1.0, 'exclusivity' => $leadExpiry + ($j * $spacing)];
        }

        return [
            'cohorts' => $cohorts,
            'biologic' => 0.0,
            'smallMolecule' => 0.0,
            'established' => (1.0 - $protectedShare) * $book,
        ];
    }

    /**
     * Ages the book one quarter: exclusivity runs down, protected cohorts climb their launch ramp, and the folded
     * off-patent revenue decays on its hazards. Nothing here is drawn, so the forecast persisted last quarter and
     * this quarter's book agree exactly.
     *
     * @param array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float} $book
     * @return array{book: array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float}, expired: float}
     */
    private function advanceBook(array $book): array
    {
        $expired = 0.0;
        $cohorts = [];
        foreach ($book['cohorts'] as $cohort) {
            $exclusivity = $cohort['exclusivity'] - 1.0;
            $adoption = $cohort['adoption'];
            if ($exclusivity > 0.0) {
                $adoption = $this->bassAdoptionStep($adoption);
            } elseif ($cohort['exclusivity'] > 0.0) {
                // Generic entry freezes the ramp: the patients the brand had are what the erosion starts from.
                $expired += $cohort['peak'] * $adoption;
            }
            $cohorts[] = ['peak' => $cohort['peak'], 'adoption' => $adoption, 'exclusivity' => $exclusivity];
        }

        return [
            'book' => [
                'cohorts' => $cohorts,
                'biologic' => $book['biologic'] * exp(-self::BIOLOGIC_LOE_HAZARD),
                'smallMolecule' => $book['smallMolecule'] * exp(-self::SMALL_MOLECULE_LOE_HAZARD),
                'established' => $book['established'],
            ],
            'expired' => $expired,
        ];
    }

    /**
     * The book in franchise units: what is protected, what the capacity base carries (cohorts inside their erosion
     * window at their pre-expiry level) and what is actually marketed.
     *
     * @param array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float} $book
     * @return array{protected: float, launched: float, marketed: float, window: float}
     */
    private function measureBook(array $book, float $biologicShare): array
    {
        $protected = 0.0;
        $window = 0.0;
        $windowMarketed = 0.0;
        foreach ($book['cohorts'] as $cohort) {
            $level = $cohort['peak'] * $cohort['adoption'];
            if ($cohort['exclusivity'] > 0.0) {
                $protected += $level;
            } else {
                $window += $level;
                $windowMarketed += $level * (1.0 - $this->loeErodedShare($biologicShare, 1.0 - $cohort['exclusivity']));
            }
        }
        $offPatent = $book['biologic'] + $book['smallMolecule'] + $book['established'];

        return [
            'protected' => $protected,
            'launched' => $protected + $window + $offPatent,
            'marketed' => $protected + $windowMarketed + $offPatent,
            'window' => $window,
        ];
    }

    /**
     * Moves cohorts whose erosion window has closed out of the ledger into the off-patent stocks, each modality at
     * what is left of it; from here on the capacity base follows their decay.
     *
     * @param array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float} $book
     * @return array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float}
     */
    private function foldErodedCohorts(array $book, float $biologicShare): array
    {
        $kept = [];
        foreach ($book['cohorts'] as $cohort) {
            $elapsed = 1.0 - $cohort['exclusivity'];
            if ($cohort['exclusivity'] > 0.0 || $elapsed < self::LOE_EROSION_WINDOW_QUARTERS) {
                $kept[] = $cohort;
                continue;
            }
            $level = $cohort['peak'] * $cohort['adoption'];
            $book['biologic'] += $biologicShare * $level * exp(-self::BIOLOGIC_LOE_HAZARD * $elapsed);
            $book['smallMolecule'] += (1.0 - $biologicShare) * $level * exp(-self::SMALL_MOLECULE_LOE_HAZARD * $elapsed);
        }
        $book['cohorts'] = $kept;

        return $book;
    }

    /**
     * Adds a launched drug to the ledger. A full ledger first moves the longest-expired cohort out early; one with
     * nothing expired merges the launch into the latest-expiring cohort, the closest in age.
     *
     * @param array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float} $book
     * @return array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float}
     */
    private function admitCohort(array $book, float $peak, float $biologicShare): array
    {
        $launch = ['peak' => $peak, 'adoption' => 0.0, 'exclusivity' => self::NEW_APPROVAL_EXCLUSIVITY_QUARTERS];
        if (count($book['cohorts']) < self::MAX_COHORTS) {
            $book['cohorts'][] = $launch;

            return $book;
        }

        $oldest = null;
        $youngest = null;
        foreach ($book['cohorts'] as $slot => $cohort) {
            if ($cohort['exclusivity'] <= 0.0 && ($oldest === null || $cohort['exclusivity'] < $book['cohorts'][$oldest]['exclusivity'])) {
                $oldest = $slot;
            }
            if ($youngest === null || $cohort['exclusivity'] > $book['cohorts'][$youngest]['exclusivity']) {
                $youngest = $slot;
            }
        }

        if ($oldest !== null) {
            $cohort = $book['cohorts'][$oldest];
            $level = $cohort['peak'] * $cohort['adoption'];
            $elapsed = 1.0 - $cohort['exclusivity'];
            $book['biologic'] += $biologicShare * $level * exp(-self::BIOLOGIC_LOE_HAZARD * $elapsed);
            $book['smallMolecule'] += (1.0 - $biologicShare) * $level * exp(-self::SMALL_MOLECULE_LOE_HAZARD * $elapsed);
            $book['cohorts'][$oldest] = $launch;

            return $book;
        }

        $host = $book['cohorts'][(int) $youngest];
        $merged = $host['peak'] + $peak;
        $book['cohorts'][(int) $youngest] = [
            'peak' => $merged,
            'adoption' => $merged > 0.0 ? ($host['peak'] * $host['adoption']) / $merged : 0.0,
            'exclusivity' => $host['exclusivity'],
        ];

        return $book;
    }

    /**
     * @param array{cohorts: list<array{peak: float, adoption: float, exclusivity: float}>, biologic: float, smallMolecule: float, established: float} $book
     */
    private function persistBook(StreamContext $streams, array $book): void
    {
        foreach (array_values($book['cohorts']) as $slot => $cohort) {
            $streams->registerState(self::cohortKey($slot, 'peak'), $cohort['peak']);
            $streams->registerState(self::cohortKey($slot, 'adoption'), $cohort['adoption']);
            $streams->registerState(self::cohortKey($slot, 'exclusivity'), $cohort['exclusivity']);
        }
        $streams->registerState(self::STATE_OFF_PATENT_BIOLOGIC, $book['biologic']);
        $streams->registerState(self::STATE_OFF_PATENT_SMALL_MOLECULE, $book['smallMolecule']);
        $streams->registerState(self::STATE_ESTABLISHED_PRODUCTS, $book['established']);
    }

    /**
     * Equity pay follows the business the firm runs: a big pharma's sales force and plants are paid like any
     * manufacturer's, a clinical-stage company's scientists largely in stock. The two industry rates are blended
     * on the firm's own marketed and pipeline weights.
     */
    public function getStockCompensationIntensity(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::EstablishedDrugWeight->value => self::ESTABLISHED_DRUG_WEIGHT,
            ModelParam::PipelineDrugWeight->value    => self::PIPELINE_DRUG_WEIGHT,
        ]);
        $marketed = max(0.0, $params[ModelParam::EstablishedDrugWeight]);
        $pipeline = max(0.0, $params[ModelParam::PipelineDrugWeight]);
        if ($marketed + $pipeline <= 0.0) {
            return self::PIPELINE_STOCK_COMPENSATION_INTENSITY;
        }

        return (($marketed * self::MARKETED_STOCK_COMPENSATION_INTENSITY) + ($pipeline * self::PIPELINE_STOCK_COMPENSATION_INTENSITY)) / ($marketed + $pipeline);
    }

    /**
     * Biotech is driven by a ledger of marketed drug cohorts that launches add to and patent expiries erode, plus
     * lumpy collaboration income exposed to binary pivotal trial readouts.
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
            ModelParam::LateStageAssetCount->value         => self::DEFAULT_LATE_STAGE_ASSETS,
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

        // --- Marketed Book: age every cohort one quarter on its known launch and exclusivity schedule ---
        $biologicShare = max(0.0, min(1.0, $params[ModelParam::BiologicRevenueShare]));
        $aged = $this->advanceBook($this->openBook($streams, $params));
        $book = $aged['book'];
        $measured = $this->measureBook($book, $biologicShare);

        $eventType = $aged['expired'] > 0.0 ? ShockEvent::BIOTECH_PATENT_CLIFF : null;

        // Expected revenue already carries the launched book (structural multiplier) and this quarter's known
        // erosion (demand shift), both persisted last quarter; strip them to get the base the book is applied to,
        // so neither is counted twice.
        $knownShift = $streams->getPersistedState(self::STATE_KNOWN_COMMERCIAL_SHIFT, 0.0);
        $baseRevenue = $expectedRevenue / (max(0.1, 1.0 + $knownShift) * $this->getStructuralRevenueMultiplier($stock));

        $reimbursementBaseline = \App\Service\Macro\MacroEngine::TARGET_INFLATION - \App\Service\Macro\MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET;
        $reimbursementShift    = $macroState->reimbursementRateGrowth - $reimbursementBaseline;
        $establishedRevenue    = max(0.0, $baseRevenue * $establishedWeight * $measured['marketed'] * (1.0 + ($establishedZ * ($baselineVol * self::COMMERCIAL_VARIANCE_SCALAR)) + $reimbursementShift));
        $pipelineRevenue       = max(0.0, $baseRevenue * $pipelineWeight    * (1.0 + ($pipelineZ    * ($baselineVol * self::PIPELINE_VARIANCE_SCALAR))));

        $preEventPipelineRevenue = $pipelineRevenue;

        // Continuous pipeline clinical progress smoothly adjusts variable margin. Trial spend itself is
        // capitalized as intangible R&D CapEx in this architecture, so progress reads through as
        // higher-value royalty and collaboration mix rather than as near-term operating expense.
        $patentModifier = -self::CONTINUOUS_PIPELINE_MARGIN_SENSITIVITY * $pipelineZ * $pipelineWeight;

        // --- Pivotal Readouts: each late-stage programme reads out at one over the Phase III duration ---
        $targetAssets = max(0.0, $params[ModelParam::LateStageAssetCount]);
        $assets = max(0.0, $streams->getPersistedState(self::STATE_LATE_STAGE_ASSETS, $targetAssets));
        $readouts = min((int) ceil($assets), $mathUtility->generatePoissonCount($assets * $this->readoutHazardPerQuarter()));

        $successRate = self::PHASE_III_SUCCESS_RATE * self::REGULATORY_APPROVAL_RATE;
        $approvals = 0;
        $failures = 0;
        for ($i = 0; $i < $readouts; $i++) {
            if ($mathUtility->checkProbability($successRate)) {
                $approvals++;
            } else {
                $failures++;
            }
        }

        // R&D refills Phase III: at the replacement rate entries match the expected readouts of the target pipeline,
        // so a failure is a programme gone until research replaces it.
        $rndRatio = $streams->getPersistedState(self::STATE_RND_REPLACEMENT_RATIO, 1.0);
        $refillMultiplier = min(
            self::MAX_PIPELINE_REFILL_MULTIPLIER,
            max(self::MIN_PIPELINE_REFILL_MULTIPLIER, pow(max(0.01, $rndRatio), self::PIPELINE_REFILL_RND_ELASTICITY))
        );
        $assets = max(0.0, $assets - $readouts) + ($targetAssets * $this->readoutHazardPerQuarter() * $refillMultiplier);

        $peakSize = $this->approvalPeakSize($targetAssets, max(0.0, min(1.0, $params[ModelParam::PatentProtectedRevenueShare])), $biologicShare);
        for ($i = 0; $i < $approvals; $i++) {
            $book = $this->admitCohort($book, $peakSize, $biologicShare);
        }

        // Upfront and milestone cash on a success, lost collaboration income on a miss: both are news against the
        // odds the expected stream already carries.
        $readoutNews = ($approvals * (1.0 - $successRate)) - ($failures * $successRate);
        $pipelineRevenue *= max(0.0, 1.0 + (self::READOUT_MILESTONE_SWING * $readoutNews));

        if ($approvals > 0) {
            // The launched asset ramps in from next quarter.
            $patentModifier  += self::APPROVAL_LAUNCH_COST_MARGIN_PENALTY * $pipelineWeight * $approvals;
            // A patent cliff onset is the larger structural event, so it keeps the public narrative.
            $eventType ??= ShockEvent::BIOTECH_DRUG_APPROVAL;
        }
        if ($failures > 0) {
            // A failed pivotal trial destroys future optionality and collaboration income. It does NOT touch
            // marketed commercial revenue: the asset never had any.
            $patentModifier  += self::TRIAL_FAILURE_MARGIN_PENALTY * $pipelineWeight * $failures;
            $eventType ??= ShockEvent::BIOTECH_TRIAL_SETBACK;
        }

        if ($measured['window'] > 0.0) {
            // Price concessions defending the off-patent brands. Reverse operating leverage against
            // the collapsing revenue base is produced by the engine's fixed cost bridge.
            $patentModifier += self::LOE_PRICE_DEFENSE_MARGIN_PENALTY * ($measured['window'] / max(self::MIN_FRANCHISE_INDEX, $measured['launched'])) * $establishedWeight;
        }

        $streamRevenues = [
            'commercial_therapeutics'       => $establishedRevenue,
            'pipeline_licensing_milestones' => $pipelineRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        // The mix adapts to the draws, not to the book: the book already scales the commercial stream, and a weight
        // that chased it would count each launch and expiry a second time.
        $streams->recordStreamShares([
            'commercial_therapeutics'       => $establishedRevenue / max(self::MIN_FRANCHISE_INDEX, $measured['marketed']),
            'pipeline_licensing_milestones' => $pipelineRevenue,
        ]);

        // API, fill-finish and technician costs reach the cost base behind long supply agreements and are
        // recovered on-label at exclusivity pricing.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $patentModifier + $inputCostDrag);

        // Revenue still under exclusivity sets secular growth from the following quarter. A launch earns nothing in
        // its approval quarter, so it reaches the share as it ramps.
        $protectedShare = $measured['marketed'] > 0.0 ? max(0.0, min(1.0, $measured['protected'] / $measured['marketed'])) : 0.0;

        $book = $this->foldErodedCohorts($book, $biologicShare);
        $this->persistBook($streams, $book);

        // Next quarter's book is fully known now: the launched part is capacity, the in-window erosion is a known
        // shortfall of utilization.
        $next = $this->measureBook($this->advanceBook($book)['book'], $biologicShare);
        $structuralMultiplier = max(self::MIN_FRANCHISE_INDEX, 1.0 + ($establishedWeight * ($next['launched'] - 1.0)));

        $streams->registerState(self::STATE_FRANCHISE_INDEX, $measured['marketed']);
        $streams->registerState(self::STATE_PROTECTED_SHARE, $protectedShare);
        $streams->registerState(self::STATE_LATE_STAGE_ASSETS, $assets);
        $streams->registerState(self::STATE_RND_REPLACEMENT_RATIO, $rndRatio);
        $streams->registerState(self::STATE_STRUCTURAL_MULTIPLIER, $structuralMultiplier);
        $streams->registerState(
            self::STATE_KNOWN_COMMERCIAL_SHIFT,
            $establishedWeight * ($next['marketed'] - $next['launched']) / $structuralMultiplier
        );

        // What was expected of each stream, the known book included: only the draw is a surprise.
        $establishedBase = max(1.0, $baseRevenue * $establishedWeight * $measured['marketed']);
        $pipelineBase    = max(1.0, $baseRevenue * $pipelineWeight);
        $establishedShock = ($establishedRevenue - $establishedBase) / $establishedBase;
        $pipelineShock    = ($pipelineRevenue - $pipelineBase) / $pipelineBase;
        $observableShockZ = ($establishedShock * $establishedWeight) + ($pipelineShock * $pipelineWeight);

        // Standardize the discrete event into Z units of pipeline dispersion. A readout moves the asset's expected
        // peak revenue by its outcome less the odds already priced; an expiry is dated, so its erosion is no news.
        $eventZ = null;
        if ($eventType !== null) {
            $assetNews = $readoutNews * $peakSize;
            $eventRevenueDelta = ($pipelineRevenue - $preEventPipelineRevenue)
                + ($baseRevenue * $establishedWeight * $assetNews);
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
     * R&D pays off through the pipeline only: the reinvestment ratio sets next quarter's Phase III entries (a
     * portfolio starved of research stops producing late-stage programmes) and nothing else. The shared margin
     * drift would pay it twice, since launches already add to the book and expiries already erode an unreplaced one.
     *
     * The ratio is struck against depreciation, but a firm whose capital grows with its market spends
     * (g + delta) / delta of it by perpetual inventory, and capital x turnover already turns that growth into revenue.
     * Only spend above that trend refills the pipeline faster than the book needs.
     */
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $depreciationRate = (float) $stock->getDepreciationRate();
        $trendRatio = $depreciationRate > 0.0 ? 1.0 + ($this->getSecularGrowthRate($stock) / $depreciationRate) : 1.0;

        $this->persistState($stock, self::STATE_RND_REPLACEMENT_RATIO, max(0.0, $reinvestmentRatio) / $trendRatio);
    }

    /** Clinical-stage biotechs trade entirely on pipeline rNPV and cash runway, not on book. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return 0.0;
    }


    /**
     * Live share of revenue under patent or regulatory exclusivity, tracked by the physics loop and
     * falling as scheduled losses of exclusivity erode the marketed book.
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
