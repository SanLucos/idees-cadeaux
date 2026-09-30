<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930074729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Size history, readable by the owner or the manager of a child profile only (spec §11 décision 51).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE profile_size_history (value VARCHAR(60) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, size_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_757FE34E498DA827 ON profile_size_history (size_id)');
        $this->addSql('ALTER TABLE profile_size_history ADD CONSTRAINT FK_757FE34E498DA827 FOREIGN KEY (size_id) REFERENCES profile_size (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Sizes that already exist start their history with their current value.
        $this->addSql('INSERT INTO profile_size_history (id, size_id, value, created_at, updated_at) SELECT gen_random_uuid(), id, value, created_at, created_at FROM profile_size');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE profile_size_history DROP CONSTRAINT FK_757FE34E498DA827');
        $this->addSql('DROP TABLE profile_size_history');
    }
}
