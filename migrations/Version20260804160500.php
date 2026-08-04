<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804160500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add granular earnings flow fields to corporate_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE corporate_report ADD operating_costs NUMERIC(20, 4) DEFAULT '0.0000' NOT NULL, ADD ebit NUMERIC(20, 4) DEFAULT '0.0000' NOT NULL, ADD pre_tax_income NUMERIC(20, 4) DEFAULT '0.0000' NOT NULL, ADD tax_paid NUMERIC(20, 4) DEFAULT '0.0000' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE corporate_report DROP operating_costs, DROP ebit, DROP pre_tax_income, DROP tax_paid');
    }
}
