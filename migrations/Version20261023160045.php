<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261023160045 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bonds DROP recovery_rate');
        $this->addSql('ALTER TABLE corporate_report DROP is_audited');
        $this->addSql('ALTER TABLE etf_events ADD facts JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE macro_report DROP qe_intensity, DROP qt_active, DROP qt_intensity');
        $this->addSql('ALTER TABLE stock_events ADD facts JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE stocks DROP buyback_authorization, DROP previous_revenue, DROP last_book_to_bill, DROP last_reported_cost_ratio, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bonds ADD recovery_rate NUMERIC(6, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE corporate_report ADD is_audited TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE etf_events DROP facts');
        $this->addSql('ALTER TABLE macro_report ADD qe_intensity NUMERIC(10, 4) DEFAULT NULL, ADD qt_active TINYINT DEFAULT NULL, ADD qt_intensity NUMERIC(10, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE stocks ADD buyback_authorization NUMERIC(30, 4) DEFAULT \'0.0000\' NOT NULL, ADD previous_revenue NUMERIC(30, 4) DEFAULT \'0.0000\' NOT NULL, ADD last_book_to_bill DOUBLE PRECISION DEFAULT NULL, ADD last_reported_cost_ratio NUMERIC(10, 6) DEFAULT NULL, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
        $this->addSql('ALTER TABLE stock_events DROP facts');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
