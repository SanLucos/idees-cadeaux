<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261014152447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Second manager of a child profile and « Partager avec mes amis » (spec §11 décisions 59 and 60).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD share_with_manager_friends BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD co_managed_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E91359847D FOREIGN KEY (co_managed_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_88BDF3E91359847D ON app_user (co_managed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E91359847D');
        $this->addSql('DROP INDEX IDX_88BDF3E91359847D');
        $this->addSql('ALTER TABLE app_user DROP share_with_manager_friends');
        $this->addSql('ALTER TABLE app_user DROP co_managed_by_id');
    }
}
