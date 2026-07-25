<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration adding last_analyst_revenue column to stocks table.
 */
final class Version20260725120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_analyst_revenue column to stocks table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stocks ADD last_analyst_revenue NUMERIC(20, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stocks DROP last_analyst_revenue');
    }
}
