<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 6: idempotency_record for Idempotency-Key replays (not an ORM entity: written by IdempotencyListener).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE idempotency_record (id UUID NOT NULL, user_id UUID NOT NULL, key VARCHAR(100) NOT NULL, method VARCHAR(10) NOT NULL, path VARCHAR(255) NOT NULL, status SMALLINT NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_idempotency_user_key ON idempotency_record (user_id, key)');
        $this->addSql('CREATE INDEX idx_idempotency_created ON idempotency_record (created_at)');
        $this->addSql('ALTER TABLE idempotency_record ADD CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE idempotency_record');
    }
}
