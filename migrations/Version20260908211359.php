<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260908211359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corporate_report ADD depreciation NUMERIC(20, 4) DEFAULT NULL, ADD ebitda NUMERIC(20, 4) DEFAULT NULL, ADD gross_ppe NUMERIC(20, 4) DEFAULT NULL, ADD net_ppe NUMERIC(20, 4) DEFAULT NULL, ADD receivables NUMERIC(20, 4) DEFAULT NULL, ADD inventory NUMERIC(20, 4) DEFAULT NULL, ADD payables NUMERIC(20, 4) DEFAULT NULL, ADD inventory_write_down NUMERIC(20, 4) DEFAULT NULL, ADD receivables_provision NUMERIC(20, 4) DEFAULT NULL, ADD deferred_tax_expense NUMERIC(20, 4) DEFAULT NULL, ADD deferred_tax_liability NUMERIC(20, 4) DEFAULT NULL, ADD cash_tax_paid NUMERIC(20, 4) DEFAULT NULL, ADD cip NUMERIC(20, 4) DEFAULT NULL, ADD goodwill NUMERIC(20, 4) DEFAULT NULL, ADD lease_liability NUMERIC(20, 4) DEFAULT NULL, ADD total_assets NUMERIC(20, 4) DEFAULT NULL, ADD total_liabilities NUMERIC(20, 4) DEFAULT NULL, ADD operating_cash_flow NUMERIC(20, 4) DEFAULT NULL, ADD investing_cash_flow NUMERIC(20, 4) DEFAULT NULL, ADD financing_cash_flow NUMERIC(20, 4) DEFAULT NULL, ADD stock_compensation NUMERIC(20, 4) DEFAULT NULL, ADD goodwill_impairment NUMERIC(20, 4) DEFAULT NULL, ADD lifecycle_stage VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE stocks ADD inventory NUMERIC(20, 4) DEFAULT NULL, ADD payables NUMERIC(20, 4) DEFAULT NULL, ADD receivables_allowance NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD gross_ppe NUMERIC(20, 4) DEFAULT NULL, ADD accumulated_depreciation NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD ppe_tax_basis NUMERIC(20, 4) DEFAULT NULL, ADD deferred_tax_liability NUMERIC(20, 4) DEFAULT \'0.0000\' NOT NULL, ADD ppe_vintage_deflator NUMERIC(10, 6) DEFAULT NULL, ADD payment_default TINYINT DEFAULT 0 NOT NULL, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE net_working_capital receivables NUMERIC(20, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corporate_report DROP depreciation, DROP ebitda, DROP gross_ppe, DROP net_ppe, DROP receivables, DROP inventory, DROP payables, DROP inventory_write_down, DROP receivables_provision, DROP deferred_tax_expense, DROP deferred_tax_liability, DROP cash_tax_paid, DROP cip, DROP goodwill, DROP lease_liability, DROP total_assets, DROP total_liabilities, DROP operating_cash_flow, DROP investing_cash_flow, DROP financing_cash_flow, DROP stock_compensation, DROP goodwill_impairment, DROP lifecycle_stage');
        $this->addSql('ALTER TABLE stocks ADD net_working_capital NUMERIC(20, 4) DEFAULT NULL, DROP receivables, DROP inventory, DROP payables, DROP receivables_allowance, DROP gross_ppe, DROP accumulated_depreciation, DROP ppe_tax_basis, DROP deferred_tax_liability, DROP ppe_vintage_deflator, DROP payment_default, CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
