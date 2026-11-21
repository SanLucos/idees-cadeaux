<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121080403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Web Push subscriptions of the installed web app (spec §11 décision 70)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_token ADD web_push_p256dh VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE device_token ADD web_push_auth VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE device_token DROP web_push_p256dh');
        $this->addSql('ALTER TABLE device_token DROP web_push_auth');
    }
}
