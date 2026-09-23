<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923151616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 4: reservation, contribution, contribution_pledge, comment, reaction (partial unique indexes for the one-active rules).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE comment (body TEXT NOT NULL, edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, idea_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_comment_idea ON comment (idea_id, created_at)');
        $this->addSql('CREATE INDEX IDX_9474526C5B6FEF7D ON comment (idea_id)');
        $this->addSql('CREATE INDEX IDX_9474526CF675F31B ON comment (author_id)');
        $this->addSql('CREATE TABLE contribution (target_amount NUMERIC(10, 2) DEFAULT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(255) NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, idea_id UUID NOT NULL, initiator_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_contribution_open_idea ON contribution (idea_id) WHERE (status = \'open\' AND deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_EA351E155B6FEF7D ON contribution (idea_id)');
        $this->addSql('CREATE INDEX IDX_EA351E157DB3B714 ON contribution (initiator_id)');
        $this->addSql('CREATE TABLE contribution_pledge (amount NUMERIC(10, 2) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, contribution_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_pledge_active_user ON contribution_pledge (contribution_id, user_id) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_976A9BFFFE5E5FBD ON contribution_pledge (contribution_id)');
        $this->addSql('CREATE INDEX IDX_976A9BFFA76ED395 ON contribution_pledge (user_id)');
        $this->addSql('CREATE TABLE reaction (type VARCHAR(255) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, idea_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_reaction_active ON reaction (idea_id, user_id, type) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_A4D707F75B6FEF7D ON reaction (idea_id)');
        $this->addSql('CREATE INDEX IDX_A4D707F7A76ED395 ON reaction (user_id)');
        $this->addSql('CREATE TABLE reservation (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, idea_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_active_idea ON reservation (idea_id) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_42C849555B6FEF7D ON reservation (idea_id)');
        $this->addSql('CREATE INDEX IDX_42C84955A76ED395 ON reservation (user_id)');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C5B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contribution ADD CONSTRAINT FK_EA351E155B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contribution ADD CONSTRAINT FK_EA351E157DB3B714 FOREIGN KEY (initiator_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contribution_pledge ADD CONSTRAINT FK_976A9BFFFE5E5FBD FOREIGN KEY (contribution_id) REFERENCES contribution (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contribution_pledge ADD CONSTRAINT FK_976A9BFFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F75B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reaction ADD CONSTRAINT FK_A4D707F7A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C849555B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE reservation ADD CONSTRAINT FK_42C84955A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526C5B6FEF7D');
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE contribution DROP CONSTRAINT FK_EA351E155B6FEF7D');
        $this->addSql('ALTER TABLE contribution DROP CONSTRAINT FK_EA351E157DB3B714');
        $this->addSql('ALTER TABLE contribution_pledge DROP CONSTRAINT FK_976A9BFFFE5E5FBD');
        $this->addSql('ALTER TABLE contribution_pledge DROP CONSTRAINT FK_976A9BFFA76ED395');
        $this->addSql('ALTER TABLE reaction DROP CONSTRAINT FK_A4D707F75B6FEF7D');
        $this->addSql('ALTER TABLE reaction DROP CONSTRAINT FK_A4D707F7A76ED395');
        $this->addSql('ALTER TABLE reservation DROP CONSTRAINT FK_42C849555B6FEF7D');
        $this->addSql('ALTER TABLE reservation DROP CONSTRAINT FK_42C84955A76ED395');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE contribution');
        $this->addSql('DROP TABLE contribution_pledge');
        $this->addSql('DROP TABLE reaction');
        $this->addSql('DROP TABLE reservation');
    }
}
