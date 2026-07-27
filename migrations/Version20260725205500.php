<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add unemployment, money supply, and energy price index to macro_report
 */
final class Version20260725205500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new macroeconomic variables to macro_report table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report ADD unemployment_rate NUMERIC(10, 4) NOT NULL, ADD unemployment_rate_ema NUMERIC(10, 4) NOT NULL, ADD money_supply NUMERIC(10, 4) NOT NULL, ADD money_velocity NUMERIC(10, 4) NOT NULL, ADD energy_price_index NUMERIC(10, 4) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report DROP unemployment_rate, DROP unemployment_rate_ema, DROP money_supply, DROP money_velocity, DROP energy_price_index');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
