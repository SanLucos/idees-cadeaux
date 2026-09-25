<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260925190801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 8: a contribution outlives its deleted initiator (spec §5.13).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contribution DROP CONSTRAINT fk_ea351e157db3b714');
        $this->addSql('ALTER TABLE contribution ALTER initiator_id DROP NOT NULL');
        $this->addSql('ALTER TABLE contribution ADD CONSTRAINT FK_EA351E157DB3B714 FOREIGN KEY (initiator_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contribution DROP CONSTRAINT FK_EA351E157DB3B714');
        $this->addSql('ALTER TABLE contribution ALTER initiator_id SET NOT NULL');
        $this->addSql('ALTER TABLE contribution ADD CONSTRAINT fk_ea351e157db3b714 FOREIGN KEY (initiator_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
