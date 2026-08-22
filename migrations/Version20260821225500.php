<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add interbank_liquidity_spread and interbank_liquidity_spread_ema to macro_report.
 */
final class Version20260821225500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add interbank_liquidity_spread and interbank_liquidity_spread_ema to macro_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report ADD interbank_liquidity_spread NUMERIC(10, 4) NOT NULL, ADD interbank_liquidity_spread_ema NUMERIC(10, 4) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report DROP interbank_liquidity_spread, DROP interbank_liquidity_spread_ema');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
