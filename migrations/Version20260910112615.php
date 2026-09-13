<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910112615 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stock_history ADD open_price NUMERIC(20, 8) DEFAULT NULL, ADD high_price NUMERIC(20, 8) DEFAULT NULL, ADD low_price NUMERIC(20, 8) DEFAULT NULL, ADD volume BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE stocks ADD turnover_ratio DOUBLE PRECISION DEFAULT NULL, ADD impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
        $this->addSql('ALTER TABLE trade_orders ADD spread_cost NUMERIC(18, 4) DEFAULT NULL, ADD impact_cost NUMERIC(18, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks DROP turnover_ratio, DROP impact_variance_ema, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
        $this->addSql('ALTER TABLE stock_history DROP open_price, DROP high_price, DROP low_price, DROP volume');
        $this->addSql('ALTER TABLE trade_orders DROP spread_cost, DROP impact_cost');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
