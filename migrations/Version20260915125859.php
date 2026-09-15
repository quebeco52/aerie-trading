<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915125859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE option_contracts (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(32) NOT NULL, option_type VARCHAR(4) NOT NULL, strike NUMERIC(20, 8) NOT NULL, expiry_serial INT NOT NULL, expires_at_time DOUBLE PRECISION NOT NULL, listed_at_time DOUBLE PRECISION NOT NULL, status VARCHAR(10) DEFAULT \'ACTIVE\' NOT NULL, price NUMERIC(20, 8) DEFAULT \'0.00000000\' NOT NULL, implied_volatility NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL, delta NUMERIC(12, 8) DEFAULT \'0.00000000\' NOT NULL, gamma NUMERIC(16, 12) DEFAULT \'0.000000000000\' NOT NULL, vega NUMERIC(20, 8) DEFAULT \'0.00000000\' NOT NULL, theta NUMERIC(20, 8) DEFAULT \'0.00000000\' NOT NULL, open_interest BIGINT DEFAULT 0 NOT NULL, structural_open_interest BIGINT DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, stock_id INT NOT NULL, UNIQUE INDEX UNIQ_D8CBB9F37EC30896 (ticker), INDEX IDX_D8CBB9F3DCD6110 (stock_id), INDEX option_underlying_status (stock_id, status), INDEX option_expiry (expires_at_time), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_options (id INT AUTO_INCREMENT NOT NULL, quantity BIGINT DEFAULT 0 NOT NULL, average_premium NUMERIC(20, 8) DEFAULT \'0.00000000\' NOT NULL, version INT DEFAULT 1 NOT NULL, user_id INT NOT NULL, option_contract_id INT NOT NULL, INDEX IDX_8838E48DA76ED395 (user_id), INDEX IDX_8838E48D342B949 (option_contract_id), UNIQUE INDEX user_option_unique (user_id, option_contract_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE option_contracts ADD CONSTRAINT FK_D8CBB9F3DCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_options ADD CONSTRAINT FK_8838E48DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_options ADD CONSTRAINT FK_8838E48D342B949 FOREIGN KEY (option_contract_id) REFERENCES option_contracts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE option_contracts DROP FOREIGN KEY FK_D8CBB9F3DCD6110');
        $this->addSql('ALTER TABLE user_options DROP FOREIGN KEY FK_8838E48DA76ED395');
        $this->addSql('ALTER TABLE user_options DROP FOREIGN KEY FK_8838E48D342B949');
        $this->addSql('DROP TABLE option_contracts');
        $this->addSql('DROP TABLE user_options');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
