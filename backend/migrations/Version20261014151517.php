<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014151517 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Size units, none for the existing sizes (spec §11 décision 57).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_size ADD unit VARCHAR(4) DEFAULT NULL');
        $this->addSql('ALTER TABLE profile_size_history ADD unit VARCHAR(4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE profile_size DROP unit');
        $this->addSql('ALTER TABLE profile_size_history DROP unit');
    }
}
