<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925191533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 8: data exports (spec §5.13).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_export (status VARCHAR(10) NOT NULL, path VARCHAR(255) DEFAULT NULL, token_hash VARCHAR(64) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, subject_id UUID NOT NULL, requested_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2272278D23EDC87 ON data_export (subject_id)');
        $this->addSql('CREATE INDEX IDX_2272278D4DA1E751 ON data_export (requested_by_id)');
        $this->addSql('ALTER TABLE data_export ADD CONSTRAINT FK_2272278D23EDC87 FOREIGN KEY (subject_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE data_export ADD CONSTRAINT FK_2272278D4DA1E751 FOREIGN KEY (requested_by_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_export DROP CONSTRAINT FK_2272278D23EDC87');
        $this->addSql('ALTER TABLE data_export DROP CONSTRAINT FK_2272278D4DA1E751');
        $this->addSql('DROP TABLE data_export');
    }
}
