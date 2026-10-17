<?php
// Paired gate for the Diet's levers: the same economy (global mt_rand seeded per run) with no politics, and with the
// Diet voting and legislating on its own stream. Macro only, no fund. Quarterly rows as JSON lines.
// php gate.php <seedFrom> <seedTo> <years> <tpy> <arm: off|on|calendar|nocommittee|noregulator|step> <out> (calendar: no Diet, the election pulse alone;
// nocommittee: the Diet and the Monetary Authority on, but the committee's supermajority withheld from the economy;
// noregulator: everything on, but the Financial Regulator's requirement withheld (the opening one stays in force);
// step: no politics, the levers held where they stand, the bank capital requirement raised 1pp at year STEP_AT (default 10),
// at once or, with STEP_PHASE years, linearly over them)
require __DIR__ . '/../../../tests/bootstrap.php';
use App\Data\Politics\AerieDiet;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Politics\PoliticsEngine;
[$script, $seedFrom, $seedTo, $years, $tpy, $arm, $out] = $argv;
$fh = fopen($out, 'w');
$tpy = (int) $tpy;
$perQuarter = intdiv($tpy, 4);
for ($seed = (int) $seedFrom; $seed <= (int) $seedTo; ++$seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $redis = new class extends \Redis {
        private array $store = [];
        public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
        public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
    };
    $politics = in_array($arm, ['off', 'calendar', 'step'], true) ? null : new PoliticsEngine(MathUtility::ownStream($seed), $redis);
    $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
        new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
    $policy = $politics?->liveState()->policy();
    $p = new \App\DTO\PoliticsStateDTO();
    $ticks = (int) $years * $tpy;
    for ($i = 1; $i <= $ticks; ++$i) {
        $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
        if ($politics !== null) {
            $p = $politics->updatePolitics($m, 1.0 / $tpy);
            $policy = $p->policy();
            if ($arm === 'nocommittee') {
                $policy = new \App\DTO\GovernmentPolicyDTO(...(['authorityMajority' => null] + get_object_vars($policy)));
            }
            if ($arm === 'noregulator') {
                $policy = new \App\DTO\GovernmentPolicyDTO(...(['bankCapitalRequirement' => null] + get_object_vars($policy)));
            }
        } elseif ($arm === 'step') {
            $policy = new \App\DTO\GovernmentPolicyDTO(
                publicSpendingShare: $m->publicSpendingShare,
                corporateTaxPolicyShift: $m->corporateTaxPolicyShift, importTariffRate: $m->importTariffRate, laborForceGrowthRate: $m->laborForceGrowthRate, foreignBuyerTaxRate: $m->foreignBuyerTaxRate,
                mergerReviewLeniency: $m->mergerReviewLeniency, greenBeltStringency: $m->greenBeltStringency, carbonPrice: $m->carbonPrice,
                extractionStringency: $m->extractionStringency, stampDutyRate: $m->stampDutyRate, bankLevyRate: $m->bankLevyRate, electionPulse: $m->electionPulse,
                reserveDrawShare: $m->reserveDrawShare, consolidationTaxShare: $m->consolidationTaxShare, reserveHomeEquityShare: $m->reserveHomeEquityShare,
                utilityRateCaseGrantShare: $m->utilityRateCaseGrantShare, dividendTaxRate: $m->dividendTaxRate,
                bankCapitalRequirement: \App\Service\Math\FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + ((float) (getenv('STEP_SIZE') ?: 0.01)) * ((float) (getenv('STEP_PHASE') ?: 0.0) > 0.0
                    ? max(0.0, min(1.0, ($m->totalTime - (float) (getenv('STEP_AT') ?: 10.0)) / (float) getenv('STEP_PHASE')))
                    : ($m->totalTime >= (float) (getenv('STEP_AT') ?: 10.0) ? 1.0 : 0.0)),
            );
        } elseif ($arm === 'calendar') {
            // What the economy read before politics left it: the term's ramp and the vote's own tick, no talks.
            $vote = TimeSeries::crossedSimulatedBoundary($m->totalTime, 1.0 / $tpy, PoliticsEngine::ELECTION_TERM_YEARS);
            $policy = new \App\DTO\GovernmentPolicyDTO(
                publicSpendingShare: MacroConstants::PUBLIC_PURCHASES_TO_GDP,
                corporateTaxPolicyShift: 0.0,
                importTariffRate: 0.0,
                laborForceGrowthRate: MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE,
                foreignBuyerTaxRate: 0.0,
                mergerReviewLeniency: 0.0,
                greenBeltStringency: 0.0,
                carbonPrice: 0.0,
                extractionStringency: 0.0,
                stampDutyRate: \App\Service\Math\FinancialConstants::STAMP_DUTY_RATE,
                bankLevyRate: 0.0,
                reserveDrawShare: MacroConstants::RESERVE_DRAW_CEILING,
                consolidationTaxShare: CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE,
                reserveHomeEquityShare: \App\Service\Macro\Subsystem\SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE,
                utilityRateCaseGrantShare: \App\Service\Math\FirmEconomics::APPOINTED_COMMISSION_GRANTED_SHARE,
                dividendTaxRate: \App\Service\Math\Valuation::FOUNDING_DIVIDEND_TAX_RATE,
                electionPulse: PoliticsEngine::electionPulse($m->totalTime, $vote ? $m->totalTime : -1.0, -1.0),
            );
        }
        if ($i % $perQuarter === 0) {
            fwrite($fh, json_encode([
                's' => $seed, 't' => round($m->totalTime, 3), 'gap' => $m->outputGap, 'infl' => $m->inflation, 'core' => $m->coreGoodsInflation,
                'rate' => $m->policyRate, 'rstar' => $m->naturalRate, 'debt' => $m->sovereignDebtToGdp, 'def' => $m->primaryDeficitToGdp,
                'tax' => $m->corporateTaxRate, 'shift' => $m->corporateTaxPolicyShift, 'tariff' => $m->importTariffRate, 'lab' => $m->laborForceGrowthRate,
                'pop' => $m->immigrationPopulationShift, 'house' => $m->residentialPropertyIndex, 'pot' => $m->potentialGdpIndex, 'anchor' => $m->inflationAnchorDrift, 'be' => $m->tipsBreakeven, 'defl' => $m->gdpDeflator, 'nx' => $m->netExportGap,
                'green' => $m->greenBeltStringency, 'carbon' => $m->carbonPrice, 'extract' => $m->extractionStringency, 'duty' => $m->stampDutyRate, 'levy' => $m->bankLevyRate, 'power' => $m->wholesalePowerPriceIndex, 'starts' => $m->housingStartsIndex,
                'brake' => $p->lastCouncilBrakeAt, 'coal' => AerieDiet::governingParties($p->governingCoalition), 'sup' => AerieDiet::governingParties($p->supportParties), 'talks' => $p->coalitionTakesOfficeAt > $m->totalTime, 'epu' => $m->policyUncertaintyIndex, 'y10' => $m->yield10y, 'unemp' => $m->unemploymentRate,
                'bal' => $p->committeeBalance, 'maj' => $p->committeeMajority, 'gst' => $p->governorStance, 'cmed' => \App\Service\Politics\CouncilAppointments::median($p->councilStances),
                'req' => $m->bankCapitalRequirement, 'built' => $m->bankCapitalBuilt, 'sloos' => $m->sloosTighteningIndex, 'ccyb' => $m->countercyclicalBufferRate, 'cdrag' => $m->creditCrisisDrag, 'lastcrisis' => $m->lastCreditCrisisAt, 'hdti' => $m->householdDebtToIncome, 'cgap' => $m->creditToGdpGap,
                'regst' => $p->regulatorStance, 'creg' => \App\Service\Politics\CouncilAppointments::median($p->councilRegulationStances), 'regstart' => $p->regulatorTermStart, 'regname' => $p->regulatorName, 'seated' => $m->authorityCommitteeSeated, 'mmaj' => $m->authorityMajority,
                'cc' => \App\Service\Politics\PoliticsEngine::coalitionPosition($p->governingCoalition, $p->dietSeats, $p->partyPositions)[AerieDiet::AXIS_COUNCIL] ?? null,
                'cs' => \App\Service\Politics\PoliticsEngine::coalitionPosition($p->governingCoalition, $p->dietSeats, $p->partyPositions)[AerieDiet::AXIS_STATE] ?? null, 'g' => $m->governmentSpendingIndex, 'spend' => $m->publicSpendingShare, 'fbt' => $m->foreignBuyerTaxRate, 'cts' => $m->consolidationTaxShare ?? null, 'line' => class_exists(\App\Service\Politics\DebtBrake::class) ? \App\Service\Politics\DebtBrake::councilLine($p) : 0.90, 'wam' => $m->sovereignDebtAverageMaturity, 'ndebt' => $m->sovereignNetDebtToGdpEma, 'eff' => $m->sovereignCouponRate, 'pr' => $m->policyRate, 'meet' => $p->lastMeetingAt, 'votes' => $p->lastMeetingVotes, 'govstart' => $p->governorTermStart, 'mst' => $p->memberStances,
                'conc' => $m->authorityConcession, 'nairu' => $m->nairu, 'psg' => $m->productivitySupplyGap, 'pn' => $m->perceivedNeutralRate, 'tr' => $m->targetRate,
            ]) . "\n");
        }
    }
    fflush($fh);
}
