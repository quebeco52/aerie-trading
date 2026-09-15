<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915164536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bond_history ADD sim_time DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_bond_history_sim_time ON bond_history (bond_id, sim_time)');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE etf_history ADD sim_time DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_etf_sim_time ON etf_history (etf_id, sim_time)');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stock_history ADD sim_time DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_stock_sim_time ON stock_history (stock_id, sim_time)');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_bond_history_sim_time ON bond_history');
        $this->addSql('ALTER TABLE bond_history DROP sim_time');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('DROP INDEX idx_etf_sim_time ON etf_history');
        $this->addSql('ALTER TABLE etf_history DROP sim_time');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
        $this->addSql('DROP INDEX idx_stock_sim_time ON stock_history');
        $this->addSql('ALTER TABLE stock_history DROP sim_time');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
