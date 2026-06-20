<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618195600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ceo_archetype to stocks table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stocks ADD ceo_archetype VARCHAR(50) DEFAULT \'opportunist\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stocks DROP ceo_archetype');
    }
}
