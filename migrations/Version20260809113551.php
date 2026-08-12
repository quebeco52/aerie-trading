<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260809113551 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE debt_tranches DROP FOREIGN KEY `FK_23A85F44DCD6110`');
        $this->addSql('DROP TABLE debt_tranches');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.01\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE debt_tranches (id INT AUTO_INCREMENT NOT NULL, principal NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, interest_rate NUMERIC(8, 6) DEFAULT \'0.000000\' NOT NULL, is_floating TINYINT DEFAULT 0 NOT NULL, maturity_quarter INT NOT NULL, issued_at DATETIME NOT NULL, stock_id INT NOT NULL, INDEX idx_stock_rate (stock_id, interest_rate), INDEX IDX_23A85F44DCD6110 (stock_id), INDEX idx_stock_maturity (stock_id, maturity_quarter), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_uca1400_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE debt_tranches ADD CONSTRAINT `FK_23A85F44DCD6110` FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.0100\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
