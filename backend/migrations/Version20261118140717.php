<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118140717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sign-in by emailed code and long sessions (spec §11 décision 68)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_sign_in_code (email VARCHAR(180) NOT NULL, code_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, attempts INT NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BDD5AF52E7927C74 ON email_sign_in_code (email)');
        $this->addSql('ALTER TABLE refresh_token ADD long_session BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_sign_in_code');
        $this->addSql('ALTER TABLE refresh_token DROP long_session');
    }
}
