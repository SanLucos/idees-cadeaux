<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925152355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 7 bis: share links (spec §5.16).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE share_link (token VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, join_count INT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_share_link_token_hash ON share_link (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_share_link_active_owner ON share_link (owner_id) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_8B6B94687E3C61F9 ON share_link (owner_id)');
        $this->addSql('CREATE INDEX IDX_8B6B9468B03A8386 ON share_link (created_by_id)');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B94687E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE share_link ADD CONSTRAINT FK_8B6B9468B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE share_link DROP CONSTRAINT FK_8B6B94687E3C61F9');
        $this->addSql('ALTER TABLE share_link DROP CONSTRAINT FK_8B6B9468B03A8386');
        $this->addSql('DROP TABLE share_link');
    }
}
