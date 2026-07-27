<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260726214605 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE card (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(150) NOT NULL, type VARCHAR(60) NOT NULL, cost INT DEFAULT NULL, victory_points INT DEFAULT NULL, text LONGTEXT DEFAULT NULL, quantity INT NOT NULL, image_path VARCHAR(255) DEFAULT NULL, attributes JSON NOT NULL, game_id INT NOT NULL, INDEX idx_card_game (game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL, description LONGTEXT DEFAULT NULL, min_players INT NOT NULL, max_players INT NOT NULL, published TINYINT NOT NULL, created_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_232B318C989D9B62 (slug), INDEX IDX_232B318CB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_session (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(12) NOT NULL, status VARCHAR(255) NOT NULL, max_players INT NOT NULL, state JSON NOT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, game_id INT NOT NULL, created_by_id INT DEFAULT NULL, winner_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4586AAFB77153098 (code), INDEX IDX_4586AAFBE48FD905 (game_id), INDEX IDX_4586AAFBB03A8386 (created_by_id), INDEX IDX_4586AAFB5DFCD4B8 (winner_id), INDEX idx_session_status (status), INDEX idx_session_finished_at (finished_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game_session_player (id INT AUTO_INCREMENT NOT NULL, seat INT NOT NULL, score INT DEFAULT NULL, rank INT DEFAULT NULL, winner TINYINT NOT NULL, joined_at DATETIME NOT NULL, session_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_2DB82C70613FECDF (session_id), INDEX idx_gsp_user (user_id), UNIQUE INDEX uniq_session_user (session_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, pseudo VARCHAR(50) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), UNIQUE INDEX UNIQ_8D93D64986CC499D (pseudo), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE card ADD CONSTRAINT FK_161498D3E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_session ADD CONSTRAINT FK_4586AAFBE48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_session ADD CONSTRAINT FK_4586AAFBB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_session ADD CONSTRAINT FK_4586AAFB5DFCD4B8 FOREIGN KEY (winner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE game_session_player ADD CONSTRAINT FK_2DB82C70613FECDF FOREIGN KEY (session_id) REFERENCES game_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE game_session_player ADD CONSTRAINT FK_2DB82C70A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE card DROP FOREIGN KEY FK_161498D3E48FD905');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CB03A8386');
        $this->addSql('ALTER TABLE game_session DROP FOREIGN KEY FK_4586AAFBE48FD905');
        $this->addSql('ALTER TABLE game_session DROP FOREIGN KEY FK_4586AAFBB03A8386');
        $this->addSql('ALTER TABLE game_session DROP FOREIGN KEY FK_4586AAFB5DFCD4B8');
        $this->addSql('ALTER TABLE game_session_player DROP FOREIGN KEY FK_2DB82C70613FECDF');
        $this->addSql('ALTER TABLE game_session_player DROP FOREIGN KEY FK_2DB82C70A76ED395');
        $this->addSql('DROP TABLE card');
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE game_session');
        $this->addSql('DROP TABLE game_session_player');
        $this->addSql('DROP TABLE `user`');
    }
}
