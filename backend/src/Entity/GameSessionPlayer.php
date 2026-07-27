<?php

namespace App\Entity;

use App\Repository\GameSessionPlayerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Participation d'un joueur à une partie, et son résultat.
 * C'est la source de tous les classements (jour / global / nb de parties).
 */
#[ORM\Entity(repositoryClass: GameSessionPlayerRepository::class)]
#[ORM\Table(name: 'game_session_player')]
#[ORM\UniqueConstraint(name: 'uniq_session_user', columns: ['session_id', 'user_id'])]
#[ORM\Index(name: 'idx_gsp_user', columns: ['user_id'])]
class GameSessionPlayer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'players')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GameSession $session = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Position à la table (ordre du tour), 0-indexé. */
    #[ORM\Column]
    private int $seat = 0;

    /** Score final (points de victoire). Null tant que la partie n'est pas finie. */
    #[ORM\Column(nullable: true)]
    private ?int $score = null;

    /** Rang final dans la partie (1 = vainqueur). Null tant que non finie. */
    #[ORM\Column(nullable: true)]
    private ?int $rank = null;

    #[ORM\Column]
    private bool $winner = false;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?GameSession
    {
        return $this->session;
    }

    public function setSession(?GameSession $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSeat(): int
    {
        return $this->seat;
    }

    public function setSeat(int $seat): static
    {
        $this->seat = $seat;

        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): static
    {
        $this->rank = $rank;

        return $this;
    }

    public function isWinner(): bool
    {
        return $this->winner;
    }

    public function setWinner(bool $winner): static
    {
        $this->winner = $winner;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
