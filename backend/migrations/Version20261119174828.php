<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Lot 15 (spec §11 décision 69): an idea's single owner becomes its
 * recipients. Each existing idea keeps its owner as sole recipient.
 */
final class Version20261119174828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Idea recipients (décision 69): idea_recipient replaces idea.owner_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE idea_recipient (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, idea_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_idea_recipient_user ON idea_recipient (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_idea_recipient_active ON idea_recipient (idea_id, user_id) WHERE (deleted_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_DAC3D9A45B6FEF7D ON idea_recipient (idea_id)');
        $this->addSql('ALTER TABLE idea_recipient ADD CONSTRAINT FK_DAC3D9A45B6FEF7D FOREIGN KEY (idea_id) REFERENCES idea (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE idea_recipient ADD CONSTRAINT FK_DAC3D9A4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        // One row per existing idea (deleted ones too: /sync tombstones still name their list).
        $rows = $this->connection->fetchAllAssociative('SELECT id, owner_id, created_at FROM idea ORDER BY created_at');
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ?, NULL)';
                array_push($params, Uuid::v7()->toRfc4122(), $row['id'], $row['owner_id'], $row['created_at'], $row['created_at']);
            }
            $this->addSql('INSERT INTO idea_recipient (id, idea_id, user_id, created_at, updated_at, deleted_at) VALUES '.implode(', ', $values), $params);
        }
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT fk_a8bca457e3c61f9');
        $this->addSql('DROP INDEX idx_a8bca457e3c61f9');
        $this->addSql('DROP INDEX idx_idea_owner_status');
        $this->addSql('ALTER TABLE idea DROP owner_id');
    }

    public function down(Schema $schema): void
    {
        // Back to one owner: the first active recipient (a shared idea loses the others).
        $this->addSql('ALTER TABLE idea ADD owner_id UUID DEFAULT NULL');
        $this->addSql('UPDATE idea SET owner_id = (SELECT r.user_id FROM idea_recipient r WHERE r.idea_id = idea.id ORDER BY r.deleted_at IS NOT NULL, r.id LIMIT 1)');
        $this->addSql('ALTER TABLE idea ALTER owner_id SET NOT NULL');
        $this->addSql('ALTER TABLE idea_recipient DROP CONSTRAINT FK_DAC3D9A45B6FEF7D');
        $this->addSql('ALTER TABLE idea_recipient DROP CONSTRAINT FK_DAC3D9A4A76ED395');
        $this->addSql('DROP TABLE idea_recipient');
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT fk_a8bca457e3c61f9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_a8bca457e3c61f9 ON idea (owner_id)');
        $this->addSql('CREATE INDEX idx_idea_owner_status ON idea (owner_id, status)');
    }
}
