<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop money_supply and money_velocity from macro_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report DROP money_supply, DROP money_velocity');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report ADD money_supply NUMERIC(10, 4) NOT NULL, ADD money_velocity NUMERIC(10, 4) NOT NULL');
    }
}
