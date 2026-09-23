<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923161118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 4 bis: parental consent on managed profiles, friendship on_behalf_of_manager, managed_profile_invitation.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE managed_profile_invitation (email VARCHAR(180) NOT NULL, code_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, attempts INT NOT NULL, id UUID NOT NULL, profile_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invitation_email ON managed_profile_invitation (email)');
        $this->addSql('CREATE INDEX IDX_10FE0771CCFA12B8 ON managed_profile_invitation (profile_id)');
        $this->addSql('ALTER TABLE managed_profile_invitation ADD CONSTRAINT FK_10FE0771CCFA12B8 FOREIGN KEY (profile_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD parental_consent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE friendship ADD on_behalf_of_manager_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE friendship ADD CONSTRAINT FK_7234A45FDCCF9F94 FOREIGN KEY (on_behalf_of_manager_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7234A45FDCCF9F94 ON friendship (on_behalf_of_manager_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE managed_profile_invitation DROP CONSTRAINT FK_10FE0771CCFA12B8');
        $this->addSql('DROP TABLE managed_profile_invitation');
        $this->addSql('ALTER TABLE app_user DROP parental_consent_at');
        $this->addSql('ALTER TABLE friendship DROP CONSTRAINT FK_7234A45FDCCF9F94');
        $this->addSql('DROP INDEX IDX_7234A45FDCCF9F94');
        $this->addSql('ALTER TABLE friendship DROP on_behalf_of_manager_id');
    }
}
