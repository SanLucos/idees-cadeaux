<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260922181919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE friendship (status VARCHAR(255) NOT NULL, origin VARCHAR(255) NOT NULL, responded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, requester_id UUID NOT NULL, addressee_id UUID NOT NULL, removed_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7234A45FED442CF4 ON friendship (requester_id)');
        $this->addSql('CREATE INDEX IDX_7234A45F2261B4C3 ON friendship (addressee_id)');
        $this->addSql('CREATE INDEX IDX_7234A45F2BD701DA ON friendship (removed_by_id)');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45FED442CF4 FOREIGN KEY (requester_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45F2261B4C3 FOREIGN KEY (addressee_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45F2BD701DA FOREIGN KEY (removed_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE friendship DROP CONSTRAINT FK_7234A45FED442CF4');
        $this->addSql('ALTER TABLE friendship DROP CONSTRAINT FK_7234A45F2261B4C3');
        $this->addSql('ALTER TABLE friendship DROP CONSTRAINT FK_7234A45F2BD701DA');
        $this->addSql('DROP TABLE friendship');
    }
}
