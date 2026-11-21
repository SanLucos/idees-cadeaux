<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121092001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Team introductions (spec §11 décision 71)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE introduction (answer_a VARCHAR(255) DEFAULT NULL, answered_at_a TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, answer_b VARCHAR(255) DEFAULT NULL, answered_at_b TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_a_id UUID NOT NULL, user_b_id UUID NOT NULL, friendship_id UUID DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F5005424415F1F9153EAB07F ON introduction (user_a_id, user_b_id)');
        $this->addSql('CREATE INDEX IDX_F5005424415F1F91 ON introduction (user_a_id)');
        $this->addSql('CREATE INDEX IDX_F500542453EAB07F ON introduction (user_b_id)');
        $this->addSql('CREATE INDEX IDX_F5005424EA7E2197 ON introduction (friendship_id)');
        $this->addSql('CREATE INDEX IDX_F5005424B03A8386 ON introduction (created_by_id)');
        $this->addSql('ALTER TABLE introduction ADD CONSTRAINT FK_F5005424415F1F91 FOREIGN KEY (user_a_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE introduction ADD CONSTRAINT FK_F500542453EAB07F FOREIGN KEY (user_b_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE introduction ADD CONSTRAINT FK_F5005424EA7E2197 FOREIGN KEY (friendship_id) REFERENCES friendship (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE introduction ADD CONSTRAINT FK_F5005424B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE introduction DROP CONSTRAINT FK_F5005424415F1F91');
        $this->addSql('ALTER TABLE introduction DROP CONSTRAINT FK_F500542453EAB07F');
        $this->addSql('ALTER TABLE introduction DROP CONSTRAINT FK_F5005424EA7E2197');
        $this->addSql('ALTER TABLE introduction DROP CONSTRAINT FK_F5005424B03A8386');
        $this->addSql('DROP TABLE introduction');
    }
}
