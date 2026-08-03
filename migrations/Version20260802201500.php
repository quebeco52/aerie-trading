<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260802201500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE analyst_forecasts (id BINARY(16) NOT NULL, expected_revenue NUMERIC(20, 4) NOT NULL, expected_eps NUMERIC(15, 4) NOT NULL, rating VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, analyst_id BINARY(16) NOT NULL, stock_id INT NOT NULL, INDEX IDX_9D4F8D5BF65B3645 (analyst_id), INDEX idx_forecast_stock (stock_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE virtual_analysts (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, bias NUMERIC(5, 4) NOT NULL, conviction NUMERIC(5, 4) NOT NULL, accuracy_rating NUMERIC(5, 4) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE analyst_coverage (virtual_analyst_id BINARY(16) NOT NULL, stock_id INT NOT NULL, INDEX IDX_ED5A0FFF8DC954D (virtual_analyst_id), INDEX IDX_ED5A0FFFDCD6110 (stock_id), PRIMARY KEY (virtual_analyst_id, stock_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE analyst_forecasts ADD CONSTRAINT FK_9D4F8D5BF65B3645 FOREIGN KEY (analyst_id) REFERENCES virtual_analysts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE analyst_forecasts ADD CONSTRAINT FK_9D4F8D5BDCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE analyst_coverage ADD CONSTRAINT FK_ED5A0FFF8DC954D FOREIGN KEY (virtual_analyst_id) REFERENCES virtual_analysts (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE analyst_coverage ADD CONSTRAINT FK_ED5A0FFFDCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.01\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analyst_forecasts DROP FOREIGN KEY FK_9D4F8D5BF65B3645');
        $this->addSql('ALTER TABLE analyst_forecasts DROP FOREIGN KEY FK_9D4F8D5BDCD6110');
        $this->addSql('ALTER TABLE analyst_coverage DROP FOREIGN KEY FK_ED5A0FFF8DC954D');
        $this->addSql('ALTER TABLE analyst_coverage DROP FOREIGN KEY FK_ED5A0FFFDCD6110');
        $this->addSql('DROP TABLE analyst_forecasts');
        $this->addSql('DROP TABLE virtual_analysts');
        $this->addSql('DROP TABLE analyst_coverage');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.0100\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
