<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014153032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations to manage a child profile with its manager (spec §11 décision 60).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE manager_invitation (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, child_id UUID NOT NULL, invitee_id UUID NOT NULL, invited_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AE52C265DD62C21B ON manager_invitation (child_id)');
        $this->addSql('CREATE INDEX IDX_AE52C2657A512022 ON manager_invitation (invitee_id)');
        $this->addSql('CREATE INDEX IDX_AE52C265A7B4A7E3 ON manager_invitation (invited_by_id)');
        $this->addSql('ALTER TABLE manager_invitation ADD CONSTRAINT FK_AE52C265DD62C21B FOREIGN KEY (child_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE manager_invitation ADD CONSTRAINT FK_AE52C2657A512022 FOREIGN KEY (invitee_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE manager_invitation ADD CONSTRAINT FK_AE52C265A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manager_invitation DROP CONSTRAINT FK_AE52C265DD62C21B');
        $this->addSql('ALTER TABLE manager_invitation DROP CONSTRAINT FK_AE52C2657A512022');
        $this->addSql('ALTER TABLE manager_invitation DROP CONSTRAINT FK_AE52C265A7B4A7E3');
        $this->addSql('DROP TABLE manager_invitation');
    }
}
