<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration adding free_cash_flow column to corporate_report table.
 */
final class Version20260720202500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add free_cash_flow column to corporate_report table';
    }

    public function up(Schema $schema): void
    {
        // No-op: handled by auto-generated migration Version20260720184316
    }

    public function down(Schema $schema): void
    {
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
