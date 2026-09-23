<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923182712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 5: notification, notification_preference (type × channel + per-channel consent), device_token, birthday reminder delays.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE device_token (platform VARCHAR(10) NOT NULL, token VARCHAR(512) NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_token ON device_token (token)');
        $this->addSql('CREATE INDEX IDX_99B2415CA76ED395 ON device_token (user_id)');
        $this->addSql('CREATE TABLE notification (type VARCHAR(255) NOT NULL, payload JSON NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dedupe_key VARCHAR(190) DEFAULT NULL, in_app BOOLEAN DEFAULT true NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, subject_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_user_created ON notification (user_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_dedupe ON notification (user_id, dedupe_key)');
        $this->addSql('CREATE INDEX IDX_BF5476CAA76ED395 ON notification (user_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA23EDC87 ON notification (subject_id)');
        $this->addSql('CREATE TABLE notification_preference (channel VARCHAR(255) NOT NULL, type VARCHAR(255) DEFAULT NULL, enabled BOOLEAN NOT NULL, consented_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preference ON notification_preference (user_id, channel, type)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_consent ON notification_preference (user_id, channel) WHERE (type IS NULL)');
        $this->addSql('CREATE INDEX IDX_A61B1571A76ED395 ON notification_preference (user_id)');
        $this->addSql('ALTER TABLE device_token ADD CONSTRAINT FK_99B2415CA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA23EDC87 FOREIGN KEY (subject_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE app_user ADD birthday_reminder_days JSON DEFAULT \'[14,2]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE device_token DROP CONSTRAINT FK_99B2415CA76ED395');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAA76ED395');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA23EDC87');
        $this->addSql('ALTER TABLE notification_preference DROP CONSTRAINT FK_A61B1571A76ED395');
        $this->addSql('DROP TABLE device_token');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE notification_preference');
        $this->addSql('ALTER TABLE app_user DROP birthday_reminder_days');
    }
}
