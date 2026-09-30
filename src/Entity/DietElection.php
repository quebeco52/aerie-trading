<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One vote for the Aerie Diet: the result, the economy it was cast on, and the talks and government that followed.
 *
 * Written by App\Service\Macro\Recorder\ElectionRecorder on the tick the vote is held, when the talks are already
 * settled; the government takes office formationDays later, and nothing shown before then may give it away. Party-keyed
 * maps are stored whole, keyed by App\Data\AerieDiet::PARTIES.
 */
#[ORM\Entity(repositoryClass: \App\Repository\DietElectionRepository::class)]
#[ORM\Table(name: 'diet_election')]
#[ORM\Index(name: 'idx_diet_election_sim_time', columns: ['sim_time'])]
class DietElection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Simulation time of the vote, in years. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $simTime;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $seats = [];

    /** @var array<string, float> */
    #[ORM\Column(type: Types::JSON)]
    private array $voteShares = [];

    /** @var array<string, float> Change in each party's share against the previous vote. */
    #[ORM\Column(type: Types::JSON)]
    private array $voteSwings = [];

    /** @var array<string, array<string, float>> Each party's position by axis, after the vote. */
    #[ORM\Column(type: Types::JSON)]
    private array $positions = [];

    /** @var list<string> The cabinet the talks produced. */
    #[ORM\Column(type: Types::JSON)]
    private array $coalition = [];

    /** @var list<string> The parties supporting that cabinet from outside; empty for a majority cabinet. */
    #[ORM\Column(type: Types::JSON)]
    private array $support = [];

    /** @var list<array{day: float, formateur: string, round: int, formed: bool, cabinet: list<string>, support: list<string>}> The talks, attempt by attempt. */
    #[ORM\Column(type: Types::JSON)]
    private array $formation = [];

    /** Days from the vote to the cabinet taking office; zero when one party won a majority. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $formationDays = 0.0;

    /** @var list<string> The governing parties going into the vote. */
    #[ORM\Column(type: Types::JSON)]
    private array $outgoingCoalition = [];

    /** Annualised real per-capita growth over the campaign, less trend. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $growthGap = 0.0;

    /** Annualised inflation over the term, less target. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $inflationGap = 0.0;

    /** The outgoing coalition's change in combined share. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $incumbentSwing = 0.0;

    /** Total electoral volatility (Pedersen index), as a fraction of the vote. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $volatility = 0.0;

    /** Whether the vote fell inside a financial crisis's window and carried its lift. */
    #[ORM\Column]
    private bool $crisisLift = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSimTime(): float
    {
        return $this->simTime;
    }

    public function setSimTime(float $simTime): static
    {
        $this->simTime = $simTime;

        return $this;
    }

    /** @return array<string, int> */
    public function getSeats(): array
    {
        return $this->seats;
    }

    /** @param array<string, int> $seats */
    public function setSeats(array $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    /** @return array<string, float> */
    public function getVoteShares(): array
    {
        return $this->voteShares;
    }

    /** @param array<string, float> $voteShares */
    public function setVoteShares(array $voteShares): static
    {
        $this->voteShares = $voteShares;

        return $this;
    }

    /** @return array<string, float> */
    public function getVoteSwings(): array
    {
        return $this->voteSwings;
    }

    /** @param array<string, float> $voteSwings */
    public function setVoteSwings(array $voteSwings): static
    {
        $this->voteSwings = $voteSwings;

        return $this;
    }

    /** @return array<string, array<string, float>> */
    public function getPositions(): array
    {
        return $this->positions;
    }

    /** @param array<string, array<string, float>> $positions */
    public function setPositions(array $positions): static
    {
        $this->positions = $positions;

        return $this;
    }

    /** @return list<string> */
    public function getSupport(): array
    {
        return $this->support;
    }

    /** @param list<string> $support */
    public function setSupport(array $support): static
    {
        $this->support = $support;

        return $this;
    }

    /** @return list<array{day: float, formateur: string, round: int, formed: bool, cabinet: list<string>, support: list<string>}> */
    public function getFormation(): array
    {
        return $this->formation;
    }

    /** @param list<array{day: float, formateur: string, round: int, formed: bool, cabinet: list<string>, support: list<string>}> $formation */
    public function setFormation(array $formation): static
    {
        $this->formation = $formation;

        return $this;
    }

    public function getFormationDays(): float
    {
        return $this->formationDays;
    }

    public function setFormationDays(float $formationDays): static
    {
        $this->formationDays = $formationDays;

        return $this;
    }

    /** Simulation time the cabinet the talks produced takes office. */
    public function getTakesOfficeAt(): float
    {
        return $this->simTime + ($this->formationDays / \App\Service\Math\FinancialConstants::DAYS_PER_YEAR);
    }

    /** @return list<string> */
    public function getCoalition(): array
    {
        return $this->coalition;
    }

    /** @param list<string> $coalition */
    public function setCoalition(array $coalition): static
    {
        $this->coalition = $coalition;

        return $this;
    }

    /** @return list<string> */
    public function getOutgoingCoalition(): array
    {
        return $this->outgoingCoalition;
    }

    /** @param list<string> $outgoingCoalition */
    public function setOutgoingCoalition(array $outgoingCoalition): static
    {
        $this->outgoingCoalition = $outgoingCoalition;

        return $this;
    }

    public function getGrowthGap(): float
    {
        return $this->growthGap;
    }

    public function setGrowthGap(float $growthGap): static
    {
        $this->growthGap = $growthGap;

        return $this;
    }

    public function getInflationGap(): float
    {
        return $this->inflationGap;
    }

    public function setInflationGap(float $inflationGap): static
    {
        $this->inflationGap = $inflationGap;

        return $this;
    }

    public function getIncumbentSwing(): float
    {
        return $this->incumbentSwing;
    }

    public function setIncumbentSwing(float $incumbentSwing): static
    {
        $this->incumbentSwing = $incumbentSwing;

        return $this;
    }

    public function getVolatility(): float
    {
        return $this->volatility;
    }

    public function setVolatility(float $volatility): static
    {
        $this->volatility = $volatility;

        return $this;
    }

    public function hasCrisisLift(): bool
    {
        return $this->crisisLift;
    }

    public function setCrisisLift(bool $crisisLift): static
    {
        $this->crisisLift = $crisisLift;

        return $this;
    }

    public function getRecordedAt(): \DateTimeInterface
    {
        return $this->recordedAt;
    }
}
