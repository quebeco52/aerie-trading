<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The department posts the Council fills, each held by one person for one fixed term: the term, when the term of the
 * holder sitting at Year 1 ends and who they are, the question the Council weighs candidates on, and the routine that
 * seats a holder (fill()). The backing value is the seat the appointment draws are hashed from.
 *
 * @phpstan-type Candidate array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}
 * @phpstan-type Head array{name: string, birth: float, termStart: float, stance: float, passedOver: list<Candidate>, appointedAt: float}
 */
enum Office: string
{
    /** The Monetary Authority's governor, who picks the rate committee (MonetaryAuthority). */
    case Governor = 'governor';
    /** The Financial Regulator's head, who sets the banks' capital rules (FinancialRegulator). */
    case Regulator = 'regulator';
    /** The Sovereign Reserve Fund's head, who sets its policy equity share (SovereignReserveFund). */
    case FundHead = 'fund';
    /** The Exchequer's head, who sets the debt's average maturity (Exchequer). */
    case ExchequerHead = 'exchequer';
    /** The Fair Trading Board's head, who sets the merger screens (FairTradingBoard). */
    case FairTradingHead = 'fair-trading';
    /** The Securities Commission's head, who sets margin and enforcement (SecuritiesCommission). */
    case SecuritiesHead = 'securities';
    /**
     * The Security Bureau's director: the District's state police, pursuing organised crime and foreign agents while
     * the streets, ports and borders are policed under concession. It sets no rule the economy reads.
     */
    case SecurityDirector = 'security';
    /**
     * The Coast Guard's commandant: the District's maritime police, holding its waters from the sounds to the shelf
     * while the port's quays are policed under concession. The Charter allows no navy, but it is armed and trained to
     * a navy's standard and answers to the Council alone. It sets no rule the economy reads.
     */
    case CoastGuardCommandant = 'coast-guard';
    /**
     * The State Guard's commander: the District's small standing land force, for hurricane relief, armed attacks
     * beyond the police's means and the defence of its institutions. The Charter allows no army, so in law it is a
     * guard, and only the Council can call it out. It sets no rule the economy reads.
     */
    case StateGuardCommander = 'state-guard';

    /** A post before its first holder is seated. */
    public const VACANT = ['name' => '', 'birth' => 0.0, 'termStart' => 0.0, 'stance' => 0.0, 'passedOver' => [], 'appointedAt' => -1.0];

    // --- Terms ---
    /** Length of the governor's single non-renewable term, in years (as at the ECB). */
    private const GOVERNOR_TERM_YEARS = 8.0;
    /** When the term of the governor sitting at Year 1 ends, in years after Year 1 began. */
    private const GOVERNOR_OPENING_TERM_END = 3.0;
    /**
     * Length of the Regulator's head's single term, in years: five, non-renewable (the ECB Supervisory Board chair's).
     */
    private const REGULATOR_TERM_YEARS = 5.0;
    /** When the term of the Regulator's head sitting at Year 1 ends, in years after Year 1 began. */
    private const REGULATOR_OPENING_TERM_END = 2.0;
    /** Length of the fund's head's single term, in years: five, fixed (NBIM's chief executive, GPIF's board). */
    private const FUND_HEAD_TERM_YEARS = 5.0;
    /** When the term of the fund's head sitting at Year 1 ends, in years after Year 1 began. */
    private const FUND_HEAD_OPENING_TERM_END = 4.0;
    /** Length of the Exchequer's head's single term, in years: the five most Council department heads serve. */
    private const EXCHEQUER_HEAD_TERM_YEARS = 5.0;
    /** When the term of the Exchequer's head sitting at Year 1 ends, in years after Year 1 began. */
    private const EXCHEQUER_HEAD_OPENING_TERM_END = 3.0;
    /**
     * Length of the Fair Trading Board's head's single term, in years: the five most Council department heads serve.
     */
    private const FAIR_TRADING_HEAD_TERM_YEARS = 5.0;
    /** When the term of the Fair Trading Board's head sitting at Year 1 ends, in years after Year 1 began. */
    private const FAIR_TRADING_HEAD_OPENING_TERM_END = 1.0;
    /**
     * Length of the Securities Commission's head's single term, in years: the five most Council department heads serve.
     */
    private const SECURITIES_HEAD_TERM_YEARS = 5.0;
    /** When the term of the Securities Commission's head sitting at Year 1 ends, in years after Year 1 began. */
    private const SECURITIES_HEAD_OPENING_TERM_END = 4.5;
    /** Length of the Security Bureau's director's single term, in years: ten (the FBI director's). */
    private const SECURITY_DIRECTOR_TERM_YEARS = 10.0;
    /** When the term of the director sitting at Year 1 ends, in years after Year 1 began. */
    private const SECURITY_DIRECTOR_OPENING_TERM_END = 6.0;
    /** Length of the Coast Guard's commandant's single term, in years: the five most Council department heads serve. */
    private const COAST_GUARD_COMMANDANT_TERM_YEARS = 5.0;
    /** When the term of the commandant sitting at Year 1 ends, in years after Year 1 began. */
    private const COAST_GUARD_COMMANDANT_OPENING_TERM_END = 2.5;
    /** Length of the State Guard's commander's single term, in years: the five most Council department heads serve. */
    private const STATE_GUARD_COMMANDER_TERM_YEARS = 5.0;
    /** When the term of the commander sitting at Year 1 ends, in years after Year 1 began. */
    private const STATE_GUARD_COMMANDER_OPENING_TERM_END = 3.5;

    /** Length of the post's single term, in years. */
    public function termYears(): float
    {
        return match ($this) {
            self::Governor => self::GOVERNOR_TERM_YEARS,
            self::Regulator => self::REGULATOR_TERM_YEARS,
            self::FundHead => self::FUND_HEAD_TERM_YEARS,
            self::ExchequerHead => self::EXCHEQUER_HEAD_TERM_YEARS,
            self::FairTradingHead => self::FAIR_TRADING_HEAD_TERM_YEARS,
            self::SecuritiesHead => self::SECURITIES_HEAD_TERM_YEARS,
            self::SecurityDirector => self::SECURITY_DIRECTOR_TERM_YEARS,
            self::CoastGuardCommandant => self::COAST_GUARD_COMMANDANT_TERM_YEARS,
            self::StateGuardCommander => self::STATE_GUARD_COMMANDER_TERM_YEARS,
        };
    }

    /** When the term of the holder sitting at Year 1 ends, in years after Year 1 began. */
    public function openingTermEnd(): float
    {
        return match ($this) {
            self::Governor => self::GOVERNOR_OPENING_TERM_END,
            self::Regulator => self::REGULATOR_OPENING_TERM_END,
            self::FundHead => self::FUND_HEAD_OPENING_TERM_END,
            self::ExchequerHead => self::EXCHEQUER_HEAD_OPENING_TERM_END,
            self::FairTradingHead => self::FAIR_TRADING_HEAD_OPENING_TERM_END,
            self::SecuritiesHead => self::SECURITIES_HEAD_OPENING_TERM_END,
            self::SecurityDirector => self::SECURITY_DIRECTOR_OPENING_TERM_END,
            self::CoastGuardCommandant => self::COAST_GUARD_COMMANDANT_OPENING_TERM_END,
            self::StateGuardCommander => self::STATE_GUARD_COMMANDER_OPENING_TERM_END,
        };
    }

    /** Who held the post at Year 1. */
    public function openingHolder(): string
    {
        return match ($this) {
            self::Governor => AerieCouncil::OPENING_GOVERNOR,
            self::Regulator => AerieCouncil::OPENING_REGULATOR,
            self::FundHead => AerieCouncil::OPENING_FUND_HEAD,
            self::ExchequerHead => AerieCouncil::OPENING_EXCHEQUER_HEAD,
            self::FairTradingHead => AerieCouncil::OPENING_FAIR_TRADING_HEAD,
            self::SecuritiesHead => AerieCouncil::OPENING_SECURITIES_HEAD,
            self::SecurityDirector => AerieCouncil::OPENING_SECURITY_DIRECTOR,
            self::CoastGuardCommandant => AerieCouncil::OPENING_COAST_GUARD_COMMANDANT,
            self::StateGuardCommander => AerieCouncil::OPENING_STATE_GUARD_COMMANDER,
        };
    }

    /** The headline the post's filling makes, after the opening (App\Service\Event\ShockEvent). */
    public function appointmentEvent(): string
    {
        return match ($this) {
            self::Governor => ShockEvent::GOVERNOR_APPOINTED,
            self::Regulator => ShockEvent::REGULATOR_APPOINTED,
            self::FundHead => ShockEvent::FUND_HEAD_APPOINTED,
            self::ExchequerHead => ShockEvent::EXCHEQUER_HEAD_APPOINTED,
            self::FairTradingHead => ShockEvent::FAIR_TRADING_HEAD_APPOINTED,
            self::SecuritiesHead => ShockEvent::SECURITIES_HEAD_APPOINTED,
            self::SecurityDirector => ShockEvent::SECURITY_DIRECTOR_APPOINTED,
            self::CoastGuardCommandant => ShockEvent::COAST_GUARD_COMMANDANT_APPOINTED,
            self::StateGuardCommander => ShockEvent::STATE_GUARD_COMMANDER_APPOINTED,
        };
    }

    /** When the term in progress at a moment began. */
    public function termStart(float $time): float
    {
        return CouncilAppointments::termStart($time, $this->openingTermEnd(), $this->termYears());
    }

    /** When the holder in office at a moment leaves. */
    public function termEnd(float $time): float
    {
        return $this->termStart($time) + $this->termYears();
    }

    /**
     * The appointer's target: the sitting Council's median stance on the post's question, or on its own three
     * (CouncilAppointments::councilMedians()) for a post that sets no rule.
     *
     * @return array<string, float>
     */
    public function target(PoliticsState $state): array
    {
        return match ($this) {
            self::Governor => [CouncilAppointments::AXIS_MONEY => CouncilAppointments::median($state->councilStances)],
            self::Regulator => [CouncilAppointments::AXIS_REGULATION => CouncilAppointments::median($state->councilRegulationStances)],
            self::FundHead => [CouncilAppointments::AXIS_FUND => CouncilAppointments::median($state->councilFundStances)],
            self::ExchequerHead => [CouncilAppointments::AXIS_DEBT => CouncilAppointments::median($state->councilDebtStances)],
            self::FairTradingHead => [CouncilAppointments::AXIS_COMPETITION => CouncilAppointments::median($state->councilCompetitionStances)],
            self::SecuritiesHead => [CouncilAppointments::AXIS_MARKETS => CouncilAppointments::median($state->councilMarketsStances)],
            self::SecurityDirector, self::CoastGuardCommandant, self::StateGuardCommander => CouncilAppointments::councilMedians($state),
        };
    }

    /**
     * Seats a holder when the post is vacant and at each term's end: the shortlisted candidate nearest the appointer's
     * target, or for a term begun before Year 1 the holder then sitting, under their own name and on the District's
     * opening policy. The outgoing holder is retired, and an appointment after the opening is dated.
     *
     * @return array{0: Candidate, 1: Head}|null The one seated and the outgoing holder (vacant at the opening); null
     *                                           when the term in progress already has its holder.
     */
    public function fill(PoliticsState $state): ?array
    {
        $outgoing = $state->head($this);
        $term = $this->termStart($state->totalTime);
        if ($outgoing['name'] !== '' && abs($outgoing['termStart'] - $term) < 1e-9) {
            return null;
        }

        [$chosen, $passedOver] = CouncilAppointments::appoint((int) $state->authoritySalt, $this->value, $term, $this->target($state), CouncilAppointments::reservedNames($state));
        $stance = $this->stance($chosen);
        if ($term < 0.0) {
            $chosen['name'] = $this->openingHolder();
            $stance = $this->openingStance() ?? $stance;
            // The governor sitting at Year 1 keeps the candidates drawn beside them as passed over; the others none.
            $passedOver = $this === self::Governor ? $passedOver : [];
        }

        CouncilAppointments::retire($state, $outgoing['name']);
        $state->heads[$this->value] = [
            'name' => $chosen['name'],
            'birth' => $chosen['birth'],
            'termStart' => $term,
            'stance' => $stance,
            'passedOver' => $passedOver,
            'appointedAt' => $outgoing['name'] === '' ? $outgoing['appointedAt'] : $state->totalTime,
        ];

        return [$chosen, $outgoing];
    }

    /**
     * A candidate's stance on the post's question; 0 for a post that sets no rule.
     *
     * @param Candidate $candidate
     */
    private function stance(array $candidate): float
    {
        return match ($this) {
            self::Governor => $candidate['stance'],
            self::Regulator => $candidate['regulation'],
            self::FundHead => $candidate['fund'],
            self::ExchequerHead => $candidate['debt'],
            self::FairTradingHead => $candidate['competition'],
            self::SecuritiesHead => $candidate['markets'],
            self::SecurityDirector, self::CoastGuardCommandant, self::StateGuardCommander => 0.0,
        };
    }

    /** The stance the holder sitting at Year 1 runs, the District's opening policy; null keeps the one drawn. */
    private function openingStance(): ?float
    {
        return match ($this) {
            self::Regulator => FinancialRegulator::stance(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT),
            self::FundHead => SovereignReserveFund::stance(SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE),
            self::ExchequerHead => Exchequer::stance(CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY),
            self::FairTradingHead => FairTradingBoard::stance(FairTradingBoard::FOUNDING_LENIENCY),
            // The middle stance, at which every rule keeps its founding value (SecuritiesCommission::along()).
            self::SecuritiesHead => 0.0,
            self::Governor, self::SecurityDirector, self::CoastGuardCommandant, self::StateGuardCommander => null,
        };
    }
}
