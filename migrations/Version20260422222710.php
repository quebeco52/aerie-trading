<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422222710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE corporate_report (id INT AUTO_INCREMENT NOT NULL, net_income NUMERIC(20, 4) DEFAULT NULL, equity NUMERIC(20, 4) DEFAULT NULL, total_debt NUMERIC(20, 4) DEFAULT NULL, treasury NUMERIC(20, 4) DEFAULT NULL, roic NUMERIC(6, 4) DEFAULT NULL, shares BIGINT DEFAULT NULL, recorded_at DATETIME NOT NULL, interest_expense NUMERIC(20, 4) NOT NULL, blended_rate NUMERIC(10, 6) NOT NULL, dynamic_spread NUMERIC(10, 6) NOT NULL, revenue NUMERIC(20, 4) NOT NULL, interest_income NUMERIC(20, 4) NOT NULL, capital_expenditures NUMERIC(20, 4) NOT NULL, stock_id INT NOT NULL, INDEX IDX_CE8FB48DCD6110 (stock_id), INDEX idx_report_recorded (stock_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE etf_events (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(50) NOT NULL, description VARCHAR(255) NOT NULL, change_percent NUMERIC(10, 2) DEFAULT NULL, recorded_at DATETIME NOT NULL, etf_id INT NOT NULL, INDEX IDX_5857F31062E4CDB8 (etf_id), INDEX idx_etf_event_recorded (etf_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE etf_history (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(10, 2) NOT NULL, recorded_at DATETIME NOT NULL, etf_id INT NOT NULL, INDEX IDX_AC0F180562E4CDB8 (etf_id), INDEX idx_etf_recorded (etf_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE etfs (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) DEFAULT \'100.00\' NOT NULL, updated_at DATETIME NOT NULL, description LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_84EFF4067EC30896 (ticker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_history (id INT AUTO_INCREMENT NOT NULL, total_value NUMERIC(15, 2) NOT NULL, recorded_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_710F5F85A76ED395 (user_id), INDEX idx_user_recorded (user_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stock_events (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(50) NOT NULL, description VARCHAR(255) NOT NULL, change_percent NUMERIC(10, 2) DEFAULT NULL, recorded_at DATETIME NOT NULL, stock_id INT NOT NULL, INDEX IDX_99A185DCDCD6110 (stock_id), INDEX idx_stock_event_recorded (stock_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stock_history (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(20, 8) NOT NULL, recorded_at DATETIME NOT NULL, stock_id INT NOT NULL, INDEX IDX_3E1C60E8DCD6110 (stock_id), INDEX idx_stock_recorded (stock_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stocks (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, sector VARCHAR(50) DEFAULT \'General\' NOT NULL, price NUMERIC(20, 8) DEFAULT \'100.00000000\' NOT NULL, shares_outstanding BIGINT UNSIGNED DEFAULT 1000000 NOT NULL, corporate_treasury NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, operating_margin NUMERIC(6, 4) DEFAULT \'0.1500\' NOT NULL, public_float_percentage NUMERIC(6, 4) DEFAULT \'1.0000\' NOT NULL, total_net_income NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, total_revenue NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, total_free_cash_flow NUMERIC(20, 4) DEFAULT NULL, total_equity NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, retained_earnings NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, total_debt NUMERIC(20, 4) DEFAULT NULL, credit_spread NUMERIC(5, 4) DEFAULT \'0.0100\' NOT NULL, floating_debt_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, historical_fixed_rate NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, goodwill NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, current_volatility NUMERIC(10, 4) DEFAULT NULL, beta NUMERIC(5, 2) DEFAULT \'1.00\', jump_intensity NUMERIC(5, 2) DEFAULT \'2.00\', jump_mean NUMERIC(5, 4) DEFAULT \'-0.01\', jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', description LONGTEXT DEFAULT NULL, systemic_importance VARCHAR(255) NOT NULL, target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, depreciation_rate NUMERIC(5, 4) DEFAULT \'0.0500\' NOT NULL, current_roic NUMERIC(6, 4) DEFAULT \'0.0000\' NOT NULL, buyback_authorization NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, fixed_cost_ratio DOUBLE PRECISION DEFAULT NULL, UNIQUE INDEX UNIQ_56F798057EC30896 (ticker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_stocks (id INT AUTO_INCREMENT NOT NULL, quantity INT DEFAULT 0 NOT NULL, version INT DEFAULT 1 NOT NULL, user_id INT NOT NULL, stock_id INT NOT NULL, INDEX IDX_33A58338A76ED395 (user_id), INDEX IDX_33A58338DCD6110 (stock_id), UNIQUE INDEX user_stock_unique (user_id, stock_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, cash_balance NUMERIC(15, 2) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE corporate_report ADD CONSTRAINT FK_CE8FB48DCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE etf_events ADD CONSTRAINT FK_5857F31062E4CDB8 FOREIGN KEY (etf_id) REFERENCES etfs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE etf_history ADD CONSTRAINT FK_AC0F180562E4CDB8 FOREIGN KEY (etf_id) REFERENCES etfs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_history ADD CONSTRAINT FK_710F5F85A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_events ADD CONSTRAINT FK_99A185DCDCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_history ADD CONSTRAINT FK_3E1C60E8DCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_stocks ADD CONSTRAINT FK_33A58338A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_stocks ADD CONSTRAINT FK_33A58338DCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corporate_report DROP FOREIGN KEY FK_CE8FB48DCD6110');
        $this->addSql('ALTER TABLE etf_events DROP FOREIGN KEY FK_5857F31062E4CDB8');
        $this->addSql('ALTER TABLE etf_history DROP FOREIGN KEY FK_AC0F180562E4CDB8');
        $this->addSql('ALTER TABLE portfolio_history DROP FOREIGN KEY FK_710F5F85A76ED395');
        $this->addSql('ALTER TABLE stock_events DROP FOREIGN KEY FK_99A185DCDCD6110');
        $this->addSql('ALTER TABLE stock_history DROP FOREIGN KEY FK_3E1C60E8DCD6110');
        $this->addSql('ALTER TABLE user_stocks DROP FOREIGN KEY FK_33A58338A76ED395');
        $this->addSql('ALTER TABLE user_stocks DROP FOREIGN KEY FK_33A58338DCD6110');
        $this->addSql('DROP TABLE corporate_report');
        $this->addSql('DROP TABLE etf_events');
        $this->addSql('DROP TABLE etf_history');
        $this->addSql('DROP TABLE etfs');
        $this->addSql('DROP TABLE portfolio_history');
        $this->addSql('DROP TABLE stock_events');
        $this->addSql('DROP TABLE stock_history');
        $this->addSql('DROP TABLE stocks');
        $this->addSql('DROP TABLE user_stocks');
        $this->addSql('DROP TABLE users');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
