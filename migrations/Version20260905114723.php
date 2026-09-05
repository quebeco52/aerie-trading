<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260905114723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE macro_report ADD capacity_utilization_rate NUMERIC(10, 4) DEFAULT NULL, ADD capacity_utilization_rate_ema NUMERIC(10, 4) DEFAULT NULL, ADD recession_probability NUMERIC(10, 4) DEFAULT NULL, ADD recession_probability_ema NUMERIC(10, 4) DEFAULT NULL, ADD corporate_default_rate NUMERIC(10, 4) DEFAULT NULL, ADD corporate_default_rate_ema NUMERIC(10, 4) DEFAULT NULL, ADD sloos_tightening_index NUMERIC(10, 4) DEFAULT NULL, ADD sloos_tightening_index_ema NUMERIC(10, 4) DEFAULT NULL, ADD supply_chain_pressure_index NUMERIC(10, 4) DEFAULT NULL, ADD supply_chain_pressure_index_ema NUMERIC(10, 4) DEFAULT NULL, ADD refining_crack_spread NUMERIC(10, 4) DEFAULT NULL, ADD refining_crack_spread_ema NUMERIC(10, 4) DEFAULT NULL, ADD deal_activity_index NUMERIC(10, 4) DEFAULT NULL, ADD deal_activity_index_ema NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE macro_report DROP capacity_utilization_rate, DROP capacity_utilization_rate_ema, DROP recession_probability, DROP recession_probability_ema, DROP corporate_default_rate, DROP corporate_default_rate_ema, DROP sloos_tightening_index, DROP sloos_tightening_index_ema, DROP supply_chain_pressure_index, DROP supply_chain_pressure_index_ema, DROP refining_crack_spread, DROP refining_crack_spread_ema, DROP deal_activity_index, DROP deal_activity_index_ema');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
