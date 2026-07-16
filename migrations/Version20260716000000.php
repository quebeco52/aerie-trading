<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration adding structural_variable_margin column to stocks table.
 */
final class Version20260716000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structural_variable_margin column to stocks table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stocks ADD structural_variable_margin DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stocks DROP structural_variable_margin');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
