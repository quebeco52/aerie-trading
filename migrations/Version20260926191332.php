<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260926191332 extends AbstractMigration
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
        $this->addSql('ALTER TABLE macro_report ADD sovereign_fund_to_gdp NUMERIC(10, 4) DEFAULT NULL, ADD sovereign_fund_domestic_weight NUMERIC(10, 6) DEFAULT NULL, ADD sovereign_fund_target_weight NUMERIC(10, 6) DEFAULT NULL, ADD sovereign_fund_ownership_share NUMERIC(10, 6) DEFAULT NULL, ADD sovereign_fund_draw_to_gdp NUMERIC(10, 6) DEFAULT NULL, ADD sovereign_fund_rebalance_share NUMERIC(10, 6) DEFAULT NULL, ADD sovereign_fund_rebalance_months_left NUMERIC(10, 4) DEFAULT NULL, ADD foreign_equity_index NUMERIC(15, 4) DEFAULT NULL, ADD last_sovereign_rebalance_at NUMERIC(14, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0, CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE macro_report DROP sovereign_fund_to_gdp, DROP sovereign_fund_domestic_weight, DROP sovereign_fund_target_weight, DROP sovereign_fund_ownership_share, DROP sovereign_fund_draw_to_gdp, DROP sovereign_fund_rebalance_share, DROP sovereign_fund_rebalance_months_left, DROP foreign_equity_index, DROP last_sovereign_rebalance_at');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
