<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260302202749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE etf_events (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(50) NOT NULL, description VARCHAR(255) NOT NULL, change_percent NUMERIC(10, 2) DEFAULT NULL, recorded_at DATETIME NOT NULL, etf_id INT NOT NULL, INDEX IDX_5857F31062E4CDB8 (etf_id), INDEX idx_etf_event_recorded (etf_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE etf_history (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(10, 2) NOT NULL, recorded_at DATETIME NOT NULL, etf_id INT NOT NULL, INDEX IDX_AC0F180562E4CDB8 (etf_id), INDEX idx_etf_recorded (etf_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE etfs (id INT AUTO_INCREMENT NOT NULL, ticker VARCHAR(10) NOT NULL, name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) DEFAULT \'100.00\' NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_84EFF4067EC30896 (ticker), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE portfolio_history (id INT AUTO_INCREMENT NOT NULL, total_value NUMERIC(15, 2) NOT NULL, recorded_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_710F5F85A76ED395 (user_id), INDEX idx_user_recorded (user_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stock_events (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(20) NOT NULL, description VARCHAR(255) NOT NULL, change_percent NUMERIC(10, 2) DEFAULT NULL, recorded_at DATETIME NOT NULL, stock_id INT NOT NULL, INDEX IDX_99A185DCDCD6110 (stock_id), INDEX idx_stock_event_recorded (stock_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stock_history (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(10, 2) NOT NULL, recorded_at DATETIME NOT NULL, stock_id INT NOT NULL, INDEX IDX_3E1C60E8DCD6110 (stock_id), INDEX idx_stock_recorded (stock_id, recorded_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE etf_events ADD CONSTRAINT FK_5857F31062E4CDB8 FOREIGN KEY (etf_id) REFERENCES etfs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE etf_history ADD CONSTRAINT FK_AC0F180562E4CDB8 FOREIGN KEY (etf_id) REFERENCES etfs (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE portfolio_history ADD CONSTRAINT FK_710F5F85A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_events ADD CONSTRAINT FK_99A185DCDCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stock_history ADD CONSTRAINT FK_3E1C60E8DCD6110 FOREIGN KEY (stock_id) REFERENCES stocks (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.02\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.01\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.10\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE etf_events DROP FOREIGN KEY FK_5857F31062E4CDB8');
        $this->addSql('ALTER TABLE etf_history DROP FOREIGN KEY FK_AC0F180562E4CDB8');
        $this->addSql('ALTER TABLE portfolio_history DROP FOREIGN KEY FK_710F5F85A76ED395');
        $this->addSql('ALTER TABLE stock_events DROP FOREIGN KEY FK_99A185DCDCD6110');
        $this->addSql('ALTER TABLE stock_history DROP FOREIGN KEY FK_3E1C60E8DCD6110');
        $this->addSql('DROP TABLE etf_events');
        $this->addSql('DROP TABLE etf_history');
        $this->addSql('DROP TABLE etfs');
        $this->addSql('DROP TABLE portfolio_history');
        $this->addSql('DROP TABLE stock_events');
        $this->addSql('DROP TABLE stock_history');
        $this->addSql('ALTER TABLE stocks CHANGE volatility volatility NUMERIC(5, 4) DEFAULT \'0.0200\' NOT NULL, CHANGE jump_mean jump_mean NUMERIC(5, 4) DEFAULT \'-0.0100\', CHANGE jump_vol jump_vol NUMERIC(5, 4) DEFAULT \'0.1000\'');
    }
}
