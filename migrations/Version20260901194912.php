<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260901194912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE macro_report ADD job_vacancies_rate NUMERIC(10, 4) DEFAULT NULL, ADD job_vacancies_rate_ema NUMERIC(10, 4) DEFAULT NULL, ADD labor_tightness NUMERIC(10, 4) DEFAULT NULL, ADD labor_tightness_ema NUMERIC(10, 4) DEFAULT NULL, ADD wage_growth NUMERIC(10, 4) DEFAULT NULL, ADD wage_growth_ema NUMERIC(10, 4) DEFAULT NULL, ADD natural_rate NUMERIC(10, 4) DEFAULT NULL, ADD natural_rate_ema NUMERIC(10, 4) DEFAULT NULL, ADD term_premium10y NUMERIC(10, 4) DEFAULT NULL, ADD term_premium10y_ema NUMERIC(10, 4) DEFAULT NULL, ADD risk_neutral10y NUMERIC(10, 4) DEFAULT NULL, ADD risk_neutral10y_ema NUMERIC(10, 4) DEFAULT NULL, ADD balance_sheet_intensity NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
        $this->addSql('ALTER TABLE trade_orders CHANGE quantity quantity BIGINT NOT NULL, CHANGE filled_quantity filled_quantity BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user_etfs CHANGE quantity quantity BIGINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user_stocks CHANGE quantity quantity BIGINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE macro_report DROP job_vacancies_rate, DROP job_vacancies_rate_ema, DROP labor_tightness, DROP labor_tightness_ema, DROP wage_growth, DROP wage_growth_ema, DROP natural_rate, DROP natural_rate_ema, DROP term_premium10y, DROP term_premium10y_ema, DROP risk_neutral10y, DROP risk_neutral10y_ema, DROP balance_sheet_intensity');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
        $this->addSql('ALTER TABLE trade_orders CHANGE quantity quantity INT NOT NULL, CHANGE filled_quantity filled_quantity INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user_etfs CHANGE quantity quantity INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user_stocks CHANGE quantity quantity INT DEFAULT 0 NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
