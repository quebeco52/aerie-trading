<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915140204 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bonds ADD seniority VARCHAR(20) DEFAULT NULL, ADD credit_spread NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL, ADD recovery_rate NUMERIC(6, 4) DEFAULT NULL, ADD issuer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bonds ADD CONSTRAINT FK_CC415C6ABB9D6FEE FOREIGN KEY (issuer_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_CC415C6ABB9D6FEE ON bonds (issuer_id)');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE stocks ADD dynamic_credit_spread NUMERIC(10, 6) DEFAULT \'0.010000\' NOT NULL, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT 0, CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bonds DROP FOREIGN KEY FK_CC415C6ABB9D6FEE');
        $this->addSql('DROP INDEX IDX_CC415C6ABB9D6FEE ON bonds');
        $this->addSql('ALTER TABLE bonds DROP seniority, DROP credit_spread, DROP recovery_rate, DROP issuer_id');
        $this->addSql('ALTER TABLE coupon_payment CHANGE simulation_time simulation_time DOUBLE PRECISION DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE stocks DROP dynamic_credit_spread, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE impact_variance_ema impact_variance_ema DOUBLE PRECISION DEFAULT \'0\', CHANGE corporate_flow_backlog corporate_flow_backlog DOUBLE PRECISION DEFAULT \'0\'');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
