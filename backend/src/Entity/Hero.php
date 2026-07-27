<?php

namespace App\Entity;

use App\Repository\HeroRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un Héros jouable. Chaque joueur en choisit un ; sa carte de départ (code) devient
 * la 10ᵉ carte de son deck initial (avec 6 Courage + 3 Désespoir).
 */
#[ORM\Entity(repositoryClass: HeroRepository::class)]
#[ORM\Index(name: 'idx_hero_game', columns: ['game_id'])]
class Hero
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Game $game = null;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private ?string $name = null;

    /** Race / classe affichée sur la carte (Hobbit, Elfe, Humain, Nain, Magicien). */
    #[ORM\Column(length: 40)]
    private ?string $race = null;

    /** Code de la carte de départ associée (référence Card.code, catégorie hero_starting). */
    #[ORM\Column(length: 100)]
    private ?string $startingCardCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getRace(): ?string
    {
        return $this->race;
    }

    public function setRace(string $race): static
    {
        $this->race = $race;

        return $this;
    }

    public function getStartingCardCode(): ?string
    {
        return $this->startingCardCode;
    }

    public function setStartingCardCode(string $startingCardCode): static
    {
        $this->startingCardCode = $startingCardCode;

        return $this;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): static
    {
        $this->imagePath = $imagePath;

        return $this;
    }
}
