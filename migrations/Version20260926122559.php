<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926122559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE macro_report ADD quarter_diagnostics JSON DEFAULT NULL, ADD config_fingerprint VARCHAR(16) DEFAULT NULL, ADD ticks_per_year INT DEFAULT NULL, ADD market_volatility_ema NUMERIC(10, 4) DEFAULT NULL, ADD energy_base_price NUMERIC(10, 4) DEFAULT NULL, ADD ns_base_term_premium NUMERIC(10, 4) DEFAULT NULL, ADD ns_long_end_premium NUMERIC(10, 4) DEFAULT NULL, ADD energy_cost_push_lag NUMERIC(10, 6) DEFAULT NULL, ADD demand_disaster_shock NUMERIC(10, 6) DEFAULT NULL, ADD demand_disaster_compensation NUMERIC(10, 6) DEFAULT NULL, CHANGE sovereign_risk_spread sovereign_risk_spread NUMERIC(10, 6) DEFAULT NULL, CHANGE sovereign_risk_spread_ema sovereign_risk_spread_ema NUMERIC(10, 6) DEFAULT NULL, CHANGE excess_bond_premium excess_bond_premium NUMERIC(10, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0, CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE macro_report DROP quarter_diagnostics, DROP config_fingerprint, DROP ticks_per_year, DROP market_volatility_ema, DROP energy_base_price, DROP ns_base_term_premium, DROP ns_long_end_premium, DROP energy_cost_push_lag, DROP demand_disaster_shock, DROP demand_disaster_compensation, CHANGE excess_bond_premium excess_bond_premium NUMERIC(10, 4) DEFAULT NULL, CHANGE sovereign_risk_spread sovereign_risk_spread NUMERIC(10, 4) DEFAULT NULL, CHANGE sovereign_risk_spread_ema sovereign_risk_spread_ema NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
