<?php

namespace App\Entity;

use App\Enum\SessionStatus;
use App\Repository\GameSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une partie jouée (instance d'un Game). Son état complet (decks, mains, marché…)
 * est stocké en JSON dans `state` pour permettre la sauvegarde/reprise.
 */
#[ORM\Entity(repositoryClass: GameSessionRepository::class)]
#[ORM\Index(name: 'idx_session_status', columns: ['status'])]
#[ORM\Index(name: 'idx_session_finished_at', columns: ['finished_at'])]
class GameSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code court de table pour rejoindre la partie (ex. "K7QX"). */
    #[ORM\Column(length: 12, unique: true)]
    private ?string $code = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Game $game = null;

    #[ORM\Column(enumType: SessionStatus::class)]
    private SessionStatus $status = SessionStatus::Waiting;

    #[ORM\Column]
    private int $maxPlayers = 5;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $winner = null;

    /** État complet de la partie (sérialisé) : decks, mains, marché, tour courant… */
    #[ORM\Column(type: 'json')]
    private array $state = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, GameSessionPlayer> */
    #[ORM\OneToMany(targetEntity: GameSessionPlayer::class, mappedBy: 'session', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['seat' => 'ASC'])]
    private Collection $players;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->players = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getGame(): ?Game
    {
        return $this->game;
    }

    public function setGame(?Game $game): static
    {
        $this->game = $game;

        return $this;
    }

    public function getStatus(): SessionStatus
    {
        return $this->status;
    }

    public function setStatus(SessionStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getMaxPlayers(): int
    {
        return $this->maxPlayers;
    }

    public function setMaxPlayers(int $maxPlayers): static
    {
        $this->maxPlayers = $maxPlayers;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getWinner(): ?User
    {
        return $this->winner;
    }

    public function setWinner(?User $winner): static
    {
        $this->winner = $winner;

        return $this;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function setState(array $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    /** @return Collection<int, GameSessionPlayer> */
    public function getPlayers(): Collection
    {
        return $this->players;
    }

    public function addPlayer(GameSessionPlayer $player): static
    {
        if (!$this->players->contains($player)) {
            $this->players->add($player);
            $player->setSession($this);
        }

        return $this;
    }

    public function removePlayer(GameSessionPlayer $player): static
    {
        if ($this->players->removeElement($player)) {
            if ($player->getSession() === $this) {
                $player->setSession(null);
            }
        }

        return $this;
    }
}
