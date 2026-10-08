<?php

declare(strict_types=1);

namespace App\Service\View;

use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Politics\FinancialRegulator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Financial Regulator's own page: its head and the two rules it sets, the core capital banks must hold and the most
 * a home buyer may borrow against the property, with the banks it supervises measured against the first and household
 * borrowing against the second.
 */
final class RegulatorPageBuilder
{
    // --- Supervision Table ---
    /** Headroom over the requirement and buffer under which a bank is shown as close to it: one percentage point. */
    private const NEAR_REQUIREMENT_HEADROOM = 0.01;

    public function __construct(
        private readonly GovernmentPageBuilder $government,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /** @return array<string, mixed> */
    public function build(MacroStateDTO $macro, PoliticsStateDTO $politics): array
    {
        $panel = $this->government->regulator($politics, $macro);
        if ($panel !== null) {
            // The mortgage limit each regime brings, beside the capital rule the panel already carries.
            $panel['head']['ltvCap'] = $politics->regulatorLtvCap;
            foreach ($politics->regulatorPassedOver as $i => $candidate) {
                if (isset($panel['head']['passedOver'][$i])) {
                    $panel['head']['passedOver'][$i]['ltvCap'] = FinancialRegulator::ltvCapForStance((float) $candidate['regulation']);
                }
            }
        }
        // What a bank must hold to pay out: the requirement plus the buffer in force, as the macro and each bank's payout stop read it.
        $required = $politics->bankCapitalRequirement + $macro->countercyclicalBufferRate;

        $caps = array_values(array_filter(FinancialRegulator::OBSERVED_LTV_CAPS, static fn(?float $cap): bool => $cap !== null));

        return [
            'regulator' => $panel,
            'required' => $required,
            'banks' => $this->banks($required),
            'mortgage' => [
                'cap' => $macro->mortgageLtvCap,
                // The level household borrowing is held below its uncapped path, from the cut built so far.
                'heldBelow' => 1.0 - exp(-$macro->ltvCutBuilt),
                'debtToIncome' => $macro->householdDebtToIncome,
                'tightestCap' => $caps === [] ? null : min($caps),
                'loosestCap' => $caps === [] ? null : max($caps),
                'regimes' => count(FinancialRegulator::OBSERVED_LTV_CAPS),
                'uncapped' => count(FinancialRegulator::OBSERVED_LTV_CAPS) - count($caps),
            ],
        ];
    }

    /** @return list<array{ticker: string, name: string, cet1: float, headroom: float, status: string}> Banks by capital ratio, lowest first. */
    private function banks(float $required): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT s.ticker, s.name, r.cet1_ratio
             FROM corporate_report r
             JOIN stocks s ON s.id = r.stock_id
             WHERE r.id IN (SELECT MAX(id) FROM corporate_report WHERE cet1_ratio IS NOT NULL GROUP BY stock_id)
             ORDER BY r.cet1_ratio ASC'
        );

        $banks = [];
        foreach ($rows as $row) {
            $cet1 = (float) $row['cet1_ratio'];
            $headroom = $cet1 - $required;
            $banks[] = [
                'ticker' => (string) $row['ticker'],
                'name' => (string) $row['name'],
                'cet1' => $cet1,
                'headroom' => $headroom,
                'status' => match (true) {
                    $headroom < 0.0 => 'below',
                    $headroom < self::NEAR_REQUIREMENT_HEADROOM => 'near',
                    default => 'clear',
                },
            ];
        }

        return $banks;
    }
}
