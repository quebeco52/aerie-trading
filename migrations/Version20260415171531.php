<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415171531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stocks ADD corporate_treasury NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD debt_to_equity_ratio NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, ADD operating_margin NUMERIC(6, 4) DEFAULT \'0.1500\' NOT NULL, ADD public_float_percentage NUMERIC(6, 4) DEFAULT \'1.0000\' NOT NULL, ADD total_revenue NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD total_net_income NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD total_free_cash_flow NUMERIC(20, 4) DEFAULT NULL, ADD total_equity NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD retained_earnings NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, DROP earnings_per_share, DROP free_cash_flow_per_share, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.01\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stocks ADD earnings_per_share NUMERIC(20, 8) DEFAULT \'10.00000000\', ADD free_cash_flow_per_share NUMERIC(20, 8) DEFAULT NULL, DROP corporate_treasury, DROP debt_to_equity_ratio, DROP operating_margin, DROP public_float_percentage, DROP total_revenue, DROP total_net_income, DROP total_free_cash_flow, DROP total_equity, DROP retained_earnings, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.0100\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
    }
}
