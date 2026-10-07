<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007120456 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notifications (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, body LONGTEXT DEFAULT NULL, ticker VARCHAR(20) DEFAULT NULL, link VARCHAR(255) DEFAULT NULL, sim_time DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_6000B0D3A76ED395 (user_id), INDEX idx_notification_user_created (user_id, created_at), INDEX idx_notification_user_read (user_id, read_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE price_alerts (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(20) NOT NULL, direction VARCHAR(5) NOT NULL, target_price NUMERIC(18, 4) NOT NULL, price_at_creation NUMERIC(18, 4) NOT NULL, created_at DATETIME NOT NULL, triggered_at DATETIME DEFAULT NULL, triggered_price NUMERIC(18, 4) DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_BAE3683BA76ED395 (user_id), INDEX idx_price_alert_ticker_open (ticker, triggered_at), INDEX idx_price_alert_user (user_id, triggered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE season_entries (id INT AUTO_INCREMENT NOT NULL, joined_time DOUBLE PRECISION NOT NULL, start_value NUMERIC(30, 2) NOT NULL, benchmark_start_level DOUBLE PRECISION NOT NULL, last_value DOUBLE PRECISION NOT NULL, peak_value DOUBLE PRECISION NOT NULL, last_benchmark_level DOUBLE PRECISION NOT NULL, max_drawdown DOUBLE PRECISION DEFAULT 0 NOT NULL, periods INT DEFAULT 0 NOT NULL, sum_dt DOUBLE PRECISION DEFAULT 0 NOT NULL, sum_return DOUBLE PRECISION DEFAULT 0 NOT NULL, sum_return_sq DOUBLE PRECISION DEFAULT 0 NOT NULL, sum_benchmark DOUBLE PRECISION DEFAULT 0 NOT NULL, sum_benchmark_sq DOUBLE PRECISION DEFAULT 0 NOT NULL, sum_cross DOUBLE PRECISION DEFAULT 0 NOT NULL, sum_risk_free DOUBLE PRECISION DEFAULT 0 NOT NULL, forfeited TINYINT DEFAULT 0 NOT NULL, final_value NUMERIC(30, 2) DEFAULT NULL, final_return DOUBLE PRECISION DEFAULT NULL, final_benchmark_return DOUBLE PRECISION DEFAULT NULL, final_rank INT DEFAULT NULL, season_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_8811A9B4EC001D1 (season_id), INDEX IDX_8811A9BA76ED395 (user_id), UNIQUE INDEX uniq_season_entry_user (season_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seasons (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, start_time DOUBLE PRECISION NOT NULL, end_time DOUBLE PRECISION NOT NULL, status VARCHAR(10) NOT NULL, benchmark_ticker VARCHAR(20) NOT NULL, benchmark_reinvest_factor DOUBLE PRECISION DEFAULT 1 NOT NULL, benchmark_distribution_seen_at DATETIME DEFAULT NULL, opened_at DATETIME NOT NULL, closed_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_season_number (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE watchlist_items (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(20) NOT NULL, asset_type VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_4FCD72A1A76ED395 (user_id), INDEX idx_watchlist_ticker (ticker), UNIQUE INDEX uniq_watchlist_user_ticker (user_id, ticker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_alerts ADD CONSTRAINT FK_BAE3683BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE season_entries ADD CONSTRAINT FK_8811A9B4EC001D1 FOREIGN KEY (season_id) REFERENCES seasons (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE season_entries ADD CONSTRAINT FK_8811A9BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE watchlist_items ADD CONSTRAINT FK_4FCD72A1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT 0 NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0, CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT 0');
        $this->addSql('ALTER TABLE trade_orders ADD origin VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A76ED395');
        $this->addSql('ALTER TABLE price_alerts DROP FOREIGN KEY FK_BAE3683BA76ED395');
        $this->addSql('ALTER TABLE season_entries DROP FOREIGN KEY FK_8811A9B4EC001D1');
        $this->addSql('ALTER TABLE season_entries DROP FOREIGN KEY FK_8811A9BA76ED395');
        $this->addSql('ALTER TABLE watchlist_items DROP FOREIGN KEY FK_4FCD72A1A76ED395');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE price_alerts');
        $this->addSql('DROP TABLE season_entries');
        $this->addSql('DROP TABLE seasons');
        $this->addSql('DROP TABLE watchlist_items');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE etfs CHANGE arbitrage_band arbitrage_band DOUBLE PRECISION DEFAULT \'0\' NOT NULL, CHANGE nav_premium nav_premium DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE simulation_clock CHANGE total_time total_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE realized_variance_ema realized_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
        $this->addSql('ALTER TABLE trade_orders DROP origin');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
