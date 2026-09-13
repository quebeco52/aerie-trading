<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910104619 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bond_history (id INT AUTO_INCREMENT NOT NULL, clean_price NUMERIC(18, 8) NOT NULL, yield_to_maturity NUMERIC(10, 6) NOT NULL, recorded_at DATETIME NOT NULL, bond_id INT NOT NULL, INDEX IDX_84E1FC3273A18A67 (bond_id), INDEX idx_bond_history_bond_recorded (bond_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE bonds (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(20) NOT NULL, name VARCHAR(255) NOT NULL, tenor_years NUMERIC(6, 2) NOT NULL, coupon_rate NUMERIC(10, 6) NOT NULL, face_value NUMERIC(15, 2) NOT NULL, issued_at_time DOUBLE PRECISION NOT NULL, matures_at_time DOUBLE PRECISION NOT NULL, last_coupon_time DOUBLE PRECISION NOT NULL, is_on_the_run TINYINT DEFAULT 0 NOT NULL, status VARCHAR(10) DEFAULT \'ACTIVE\' NOT NULL, price NUMERIC(18, 8) NOT NULL, clean_price NUMERIC(18, 8) NOT NULL, accrued_interest NUMERIC(18, 8) DEFAULT \'0.00000000\' NOT NULL, yield_to_maturity NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL, modified_duration NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL, convexity NUMERIC(12, 6) DEFAULT \'0.000000\' NOT NULL, outstanding_face NUMERIC(20, 2) DEFAULT \'0.00\' NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_CC415C6A7EC30896 (ticker), INDEX idx_bond_status_matures (status, matures_at_time), INDEX idx_bond_on_the_run (tenor_years, is_on_the_run), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE coupon_payment (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(20) NOT NULL, payment_type VARCHAR(12) DEFAULT \'COUPON\' NOT NULL, bonds_held BIGINT NOT NULL, amount_per_bond NUMERIC(15, 4) NOT NULL, amount NUMERIC(15, 2) NOT NULL, simulation_time DOUBLE PRECISION DEFAULT 0 NOT NULL, paid_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_8240C79BA76ED395 (user_id), INDEX idx_coupon_user_paid (user_id, paid_at), INDEX idx_coupon_user_ticker (user_id, ticker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_bonds (id INT AUTO_INCREMENT NOT NULL, quantity BIGINT DEFAULT 0 NOT NULL, version INT DEFAULT 1 NOT NULL, user_id INT NOT NULL, bond_id INT NOT NULL, INDEX IDX_2E83A732A76ED395 (user_id), INDEX IDX_2E83A73273A18A67 (bond_id), UNIQUE INDEX user_bond_unique (user_id, bond_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bond_history ADD CONSTRAINT FK_84E1FC3273A18A67 FOREIGN KEY (bond_id) REFERENCES bonds (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE coupon_payment ADD CONSTRAINT FK_8240C79BA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_bonds ADD CONSTRAINT FK_2E83A732A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_bonds ADD CONSTRAINT FK_2E83A73273A18A67 FOREIGN KEY (bond_id) REFERENCES bonds (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.30\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.00\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.20\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.10\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bond_history DROP FOREIGN KEY FK_84E1FC3273A18A67');
        $this->addSql('ALTER TABLE coupon_payment DROP FOREIGN KEY FK_8240C79BA76ED395');
        $this->addSql('ALTER TABLE user_bonds DROP FOREIGN KEY FK_2E83A732A76ED395');
        $this->addSql('ALTER TABLE user_bonds DROP FOREIGN KEY FK_2E83A73273A18A67');
        $this->addSql('DROP TABLE bond_history');
        $this->addSql('DROP TABLE bonds');
        $this->addSql('DROP TABLE coupon_payment');
        $this->addSql('DROP TABLE user_bonds');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\', CHANGE target_payout_ratio target_payout_ratio NUMERIC(5, 4) DEFAULT \'0.3000\' NOT NULL, CHANGE dividend_speed dividend_speed NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL, CHANGE last_dividend last_dividend NUMERIC(10, 4) DEFAULT \'0.0000\' NOT NULL, CHANGE baseline_roic baseline_roic NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE baseline_roe baseline_roe NUMERIC(5, 4) DEFAULT \'0.1000\' NOT NULL, CHANGE capex_ratio capex_ratio NUMERIC(5, 4) DEFAULT \'0.2000\' NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
