<?php

namespace App\Entity;

use App\Enum\CardCategory;
use App\Repository\CardRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une carte appartenant à un Game. Les champs génériques couvrent la plupart
 * des deck-builders ; `attributes` (JSON) accueille les particularités par jeu.
 */
#[ORM\Entity(repositoryClass: CardRepository::class)]
#[ORM\Index(name: 'idx_card_game', columns: ['game_id'])]
class Card
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Game $game = null;

    /** Catégorie structurelle : deck principal, départ, valeur, archennemi, corruption… */
    #[ORM\Column(enumType: CardCategory::class)]
    private CardCategory $category = CardCategory::MainDeck;

    /** Niveau de l'Archennemi (1 à 4). Null pour les autres cartes. */
    #[ORM\Column(nullable: true)]
    private ?int $level = null;

    /** Pour une carte de départ de Héros : le héros associé (ex. "Frodon"). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $hero = null;

    /** Identifiant lisible/stable de la carte au sein du jeu (ex. "chef-orc"). */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    private ?string $name = null;

    /** Type de carte (ex. Ennemi, Allié, Lieu, Artefact, Chance, Manœuvre, Départ). */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private ?string $type = null;

    /** Coût d'achat (rond doré). Null si non achetable. */
    #[ORM\Column(nullable: true)]
    private ?int $cost = null;

    /** Points de victoire (rond gris). Null si aucun. */
    #[ORM\Column(nullable: true)]
    private ?int $victoryPoints = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $text = null;

    /** Nombre d'exemplaires de cette carte dans le deck. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $quantity = 1;

    /** Chemin/URL de l'image scannée, si fournie. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    /** Attributs spécifiques au jeu (effets structurés, mots-clés, etc.). */
    #[ORM\Column(type: 'json')]
    private array $attributes = [];

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

    public function getCategory(): CardCategory
    {
        return $this->category;
    }

    public function setCategory(CardCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getHero(): ?string
    {
        return $this->hero;
    }

    public function setHero(?string $hero): static
    {
        $this->hero = $hero;

        return $this;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getCost(): ?int
    {
        return $this->cost;
    }

    public function setCost(?int $cost): static
    {
        $this->cost = $cost;

        return $this;
    }

    public function getVictoryPoints(): ?int
    {
        return $this->victoryPoints;
    }

    public function setVictoryPoints(?int $victoryPoints): static
    {
        $this->victoryPoints = $victoryPoints;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

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

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

        return $this;
    }
}
