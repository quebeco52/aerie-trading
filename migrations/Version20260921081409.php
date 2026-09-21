<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260921081409 extends AbstractMigration
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
        $this->addSql('ALTER TABLE macro_report ADD qe_active TINYINT DEFAULT NULL, ADD qe_intensity NUMERIC(10, 4) DEFAULT NULL, ADD qt_active TINYINT DEFAULT NULL, ADD qt_intensity NUMERIC(10, 4) DEFAULT NULL, ADD balance_sheet_hold_timer NUMERIC(10, 4) DEFAULT NULL, ADD inversion_duration NUMERIC(10, 4) DEFAULT NULL, ADD equity_market_cap NUMERIC(22, 2) DEFAULT NULL, ADD equity_market_cap_ema NUMERIC(22, 2) DEFAULT NULL, ADD residential_wealth_trend NUMERIC(10, 4) DEFAULT NULL, ADD demand_shock NUMERIC(10, 4) DEFAULT NULL, ADD potential_gdp_index NUMERIC(10, 4) DEFAULT NULL, ADD gdp_deflator NUMERIC(10, 4) DEFAULT NULL, ADD inventory_stock_gap NUMERIC(10, 4) DEFAULT NULL, ADD energy_price_shock NUMERIC(10, 4) DEFAULT NULL, ADD policy_uncertainty_index NUMERIC(10, 4) DEFAULT NULL, ADD policy_uncertainty_index_ema NUMERIC(10, 4) DEFAULT NULL, ADD supercore_inflation NUMERIC(10, 4) DEFAULT NULL, ADD core_goods_inflation NUMERIC(10, 4) DEFAULT NULL, ADD cumulative_inflation_gap NUMERIC(10, 4) DEFAULT NULL, ADD ns_level NUMERIC(10, 4) DEFAULT NULL, ADD ns_slope NUMERIC(10, 4) DEFAULT NULL, ADD ns_slope_ema NUMERIC(10, 4) DEFAULT NULL, ADD structural_slope NUMERIC(10, 4) DEFAULT NULL, ADD ns_curvature NUMERIC(10, 4) DEFAULT NULL, ADD ns_beta1 NUMERIC(10, 4) DEFAULT NULL, ADD credit_to_gdp_trend NUMERIC(10, 4) DEFAULT NULL, ADD household_debt_service_trend NUMERIC(10, 4) DEFAULT NULL, ADD household_debt_service_gap NUMERIC(10, 4) DEFAULT NULL, ADD high_yield_credit_spread NUMERIC(10, 4) DEFAULT NULL, ADD last_credit_crisis_at NUMERIC(14, 6) DEFAULT NULL, ADD last_catastrophe_at NUMERIC(14, 6) DEFAULT NULL, ADD last_catastrophe_severity NUMERIC(10, 4) DEFAULT NULL, ADD event_cooldown_timer NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0, CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE macro_report DROP qe_active, DROP qe_intensity, DROP qt_active, DROP qt_intensity, DROP balance_sheet_hold_timer, DROP inversion_duration, DROP equity_market_cap, DROP equity_market_cap_ema, DROP residential_wealth_trend, DROP demand_shock, DROP potential_gdp_index, DROP gdp_deflator, DROP inventory_stock_gap, DROP energy_price_shock, DROP policy_uncertainty_index, DROP policy_uncertainty_index_ema, DROP supercore_inflation, DROP core_goods_inflation, DROP cumulative_inflation_gap, DROP ns_level, DROP ns_slope, DROP ns_slope_ema, DROP structural_slope, DROP ns_curvature, DROP ns_beta1, DROP credit_to_gdp_trend, DROP household_debt_service_trend, DROP household_debt_service_gap, DROP high_yield_credit_spread, DROP last_credit_crisis_at, DROP last_catastrophe_at, DROP last_catastrophe_severity, DROP event_cooldown_timer');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
