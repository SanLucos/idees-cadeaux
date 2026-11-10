<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110154459 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Friend suggestions, and the suggestion behind a friend request (spec §11 décision 65).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE friend_suggestion (status VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, suggested_by_id UUID NOT NULL, recipient_id UUID NOT NULL, suggested_id UUID NOT NULL, friendship_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_73C7071FE92F8F785E3374B ON friend_suggestion (recipient_id, suggested_id)');
        $this->addSql('CREATE INDEX IDX_73C7071F66290AB1 ON friend_suggestion (suggested_by_id)');
        $this->addSql('CREATE INDEX IDX_73C7071FE92F8F78 ON friend_suggestion (recipient_id)');
        $this->addSql('CREATE INDEX IDX_73C7071F5E3374B ON friend_suggestion (suggested_id)');
        $this->addSql('CREATE INDEX IDX_73C7071FEA7E2197 ON friend_suggestion (friendship_id)');
        $this->addSql('ALTER TABLE friend_suggestion ADD CONSTRAINT FK_73C7071F66290AB1 FOREIGN KEY (suggested_by_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friend_suggestion ADD CONSTRAINT FK_73C7071FE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friend_suggestion ADD CONSTRAINT FK_73C7071F5E3374B FOREIGN KEY (suggested_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friend_suggestion ADD CONSTRAINT FK_73C7071FEA7E2197 FOREIGN KEY (friendship_id) REFERENCES friendship (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE friendship ADD suggested_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45F66290AB1 FOREIGN KEY (suggested_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7234A45F66290AB1 ON friendship (suggested_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE friend_suggestion DROP CONSTRAINT FK_73C7071F66290AB1');
        $this->addSql('ALTER TABLE friend_suggestion DROP CONSTRAINT FK_73C7071FE92F8F78');
        $this->addSql('ALTER TABLE friend_suggestion DROP CONSTRAINT FK_73C7071F5E3374B');
        $this->addSql('ALTER TABLE friend_suggestion DROP CONSTRAINT FK_73C7071FEA7E2197');
        $this->addSql('DROP TABLE friend_suggestion');
        $this->addSql('ALTER TABLE friendship DROP CONSTRAINT FK_7234A45F66290AB1');
        $this->addSql('DROP INDEX IDX_7234A45F66290AB1');
        $this->addSql('ALTER TABLE friendship DROP suggested_by_id');
    }
}
