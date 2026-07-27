<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726221238 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE hero (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, race VARCHAR(40) NOT NULL, starting_card_code VARCHAR(100) NOT NULL, image_path VARCHAR(255) DEFAULT NULL, game_id INT NOT NULL, INDEX idx_hero_game (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE hero ADD CONSTRAINT FK_51CE6E86E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE card ADD category VARCHAR(255) NOT NULL, ADD level INT DEFAULT NULL, ADD hero VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE hero DROP FOREIGN KEY FK_51CE6E86E48FD905');
        $this->addSql('DROP TABLE hero');
        $this->addSql('ALTER TABLE card DROP category, DROP level, DROP hero');
    }
}
