<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260923134924 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 3: idea and occasion tables, seeded with the 14 predefined occasions (spec §5.4).';
    }

    /** Spec §5.4, in its order (sortOrder). Labels live client-side under occasions.<code>. */
    private const array OCCASIONS = [
        'birthday', 'christmas', 'new_year', 'birth', 'baptism', 'engagement', 'wedding',
        'housewarming', 'valentines_day', 'mothers_day', 'fathers_day', 'graduation', 'farewell', 'thanks',
    ];

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE idea (title VARCHAR(120) NOT NULL, url VARCHAR(2048) DEFAULT NULL, price_amount NUMERIC(10, 2) DEFAULT NULL, price_currency VARCHAR(3) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, note TEXT DEFAULT NULL, visibility VARCHAR(255) NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(255) NOT NULL, archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, archive_kind VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id UUID NOT NULL, author_id UUID NOT NULL, occasion_id UUID DEFAULT NULL, archived_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_idea_owner_status ON idea (owner_id, status)');
        $this->addSql('CREATE INDEX idx_idea_author_visibility ON idea (author_id, visibility)');
        $this->addSql('CREATE INDEX IDX_A8BCA457E3C61F9 ON idea (owner_id)');
        $this->addSql('CREATE INDEX IDX_A8BCA45F675F31B ON idea (author_id)');
        $this->addSql('CREATE INDEX IDX_A8BCA454034998F ON idea (occasion_id)');
        $this->addSql('CREATE INDEX IDX_A8BCA4577BE2925 ON idea (archived_by_id)');
        $this->addSql('CREATE TABLE occasion (code VARCHAR(40) NOT NULL, translation_key VARCHAR(80) NOT NULL, sort_order INT NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_occasion_code ON occasion (code)');
        foreach (self::OCCASIONS as $index => $code) {
            $this->addSql(
                'INSERT INTO occasion (id, code, translation_key, sort_order, created_at, updated_at) VALUES (:id, :code, :key, :sort, NOW(), NOW())',
                ['id' => Uuid::v7()->toRfc4122(), 'code' => $code, 'key' => 'occasions.'.$code, 'sort' => ($index + 1) * 10],
            );
        }
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT FK_A8BCA457E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT FK_A8BCA45F675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT FK_A8BCA454034998F FOREIGN KEY (occasion_id) REFERENCES occasion (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE idea ADD CONSTRAINT FK_A8BCA4577BE2925 FOREIGN KEY (archived_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT FK_A8BCA457E3C61F9');
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT FK_A8BCA45F675F31B');
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT FK_A8BCA454034998F');
        $this->addSql('ALTER TABLE idea DROP CONSTRAINT FK_A8BCA4577BE2925');
        $this->addSql('DROP TABLE idea');
        $this->addSql('DROP TABLE occasion');
    }
}
