<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Entity\DietElection;
use App\Repository\DietElectionRepository;

/** The election history a headless politics run records, served to GovernmentPageBuilder without a database. */
final class InMemoryDietElections extends DietElectionRepository
{
    /** @var list<DietElection> */
    public array $rows = [];

    public function __construct()
    {
    }

    public function findChronological(): array
    {
        return $this->rows;
    }

    public function findLatest(): ?DietElection
    {
        return $this->rows === [] ? null : $this->rows[array_key_last($this->rows)];
    }
}
