<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260917104247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corporate_report ADD unrealized_securities_mark NUMERIC(20, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE etf_history ADD nav NUMERIC(12, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE etfs ADD shares_outstanding NUMERIC(20, 4) DEFAULT \'250000000.0000\' NOT NULL, ADD arbitrage_band DOUBLE PRECISION DEFAULT 0 NOT NULL, ADD nav_premium DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks ADD analyst_price_target NUMERIC(12, 4) DEFAULT NULL, ADD securities_duration DOUBLE PRECISION DEFAULT NULL, ADD securities_carrying_yield DOUBLE PRECISION DEFAULT NULL, ADD unrealized_securities_mark NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD aoci_filtered TINYINT DEFAULT 1 NOT NULL, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0, CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT 0');
        $this->addSql('ALTER TABLE trade_orders ADD stop_price NUMERIC(18, 4) DEFAULT NULL, CHANGE order_type order_type VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corporate_report DROP unrealized_securities_mark');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE etfs DROP shares_outstanding, DROP arbitrage_band, DROP nav_premium');
        $this->addSql('ALTER TABLE etf_history DROP nav');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks DROP analyst_price_target, DROP securities_duration, DROP securities_carrying_yield, DROP unrealized_securities_mark, DROP aoci_filtered, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
        $this->addSql('ALTER TABLE trade_orders DROP stop_price, CHANGE order_type order_type VARCHAR(10) NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
