<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add consumer_sentiment_index to macro_report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report ADD consumer_sentiment_index DOUBLE PRECISION DEFAULT \'100.0\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE macro_report DROP consumer_sentiment_index');
    }
}
