<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261008190125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE election_odds (id INT AUTO_INCREMENT NOT NULL, sim_time DOUBLE PRECISION NOT NULL, vote_at DOUBLE PRECISION NOT NULL, leaders JSON NOT NULL, cabinets JSON NOT NULL, seats JSON NOT NULL, recorded_at DATETIME NOT NULL, INDEX idx_election_odds_vote_at (vote_at, sim_time), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE rate_decision (id INT AUTO_INCREMENT NOT NULL, sim_time DOUBLE PRECISION NOT NULL, rate DOUBLE PRECISION NOT NULL, rate_change DOUBLE PRECISION NOT NULL, votes JSON NOT NULL, governor VARCHAR(120) NOT NULL, committee_majority SMALLINT NOT NULL, cabinet_pressing TINYINT NOT NULL, giving_ground TINYINT NOT NULL, recorded_at DATETIME NOT NULL, INDEX idx_rate_decision_sim_time (sim_time), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE corporate_report ADD consensus_eps NUMERIC(20, 6) DEFAULT NULL, ADD reported_eps NUMERIC(20, 6) DEFAULT NULL, ADD consensus_revenue NUMERIC(30, 4) DEFAULT NULL, ADD total_time NUMERIC(14, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE macro_report CHANGE countercyclical_buffer_rate_ema sahm_recession_indicator NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE season_entries CHANGE max_drawdown max_drawdown DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_dt sum_dt DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_return sum_return DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_return_sq sum_return_sq DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_benchmark sum_benchmark DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_benchmark_sq sum_benchmark_sq DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_cross sum_cross DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE sum_risk_free sum_risk_free DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE seasons CHANGE benchmark_reinvest_factor benchmark_reinvest_factor DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0, CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE announcement_variance_ema announcement_variance_ema DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE election_odds');
        $this->addSql('DROP TABLE rate_decision');
        $this->addSql('ALTER TABLE corporate_report DROP consensus_eps, DROP reported_eps, DROP consensus_revenue, DROP total_time');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE macro_report CHANGE sahm_recession_indicator countercyclical_buffer_rate_ema NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE seasons CHANGE benchmark_reinvest_factor benchmark_reinvest_factor DOUBLE PRECISION DEFAULT \'1\' NOT NULL');
        $this->addSql('ALTER TABLE season_entries CHANGE max_drawdown max_drawdown DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_dt sum_dt DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_return sum_return DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_return_sq sum_return_sq DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_benchmark sum_benchmark DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_benchmark_sq sum_benchmark_sq DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_cross sum_cross DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE sum_risk_free sum_risk_free DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE announcement_variance_ema announcement_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
