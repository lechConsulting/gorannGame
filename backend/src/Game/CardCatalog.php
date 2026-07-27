<?php

namespace App\Game;

use App\Entity\Game;
use App\Repository\CardRepository;
use App\Repository\HeroRepository;

/**
 * Charge en mémoire les définitions de cartes et de héros d'un jeu, indexées par code.
 * Sert de source de vérité "statique" au moteur (le state de partie ne stocke que des références).
 */
class CardCatalog
{
    /** @var array<string, array<string, mixed>> code => définition */
    private array $cards = [];

    /** @var array<string, array<string, mixed>> nom du héros => définition */
    private array $heroes = [];

    public function __construct(
        private readonly CardRepository $cardRepository,
        private readonly HeroRepository $heroRepository,
    ) {
    }

    public function load(Game $game): void
    {
        $this->cards = [];
        foreach ($this->cardRepository->findByGame($game) as $c) {
            $this->cards[$c->getCode()] = [
                'code' => $c->getCode(),
                'name' => $c->getName(),
                'type' => $c->getType(),
                'category' => $c->getCategory()->value,
                'cost' => $c->getCost(),
                'pv' => $c->getVictoryPoints(),
                'level' => $c->getLevel(),
                'hero' => $c->getHero(),
                'quantity' => $c->getQuantity(),
                'text' => $c->getText(),
                'attributes' => $c->getAttributes(),
            ];
        }

        $this->heroes = [];
        foreach ($this->heroRepository->findByGame($game) as $h) {
            $this->heroes[$h->getName()] = [
                'name' => $h->getName(),
                'race' => $h->getRace(),
                'startingCardCode' => $h->getStartingCardCode(),
            ];
        }
    }

    /** @return array<string, mixed> */
    public function card(string $code): array
    {
        return $this->cards[$code] ?? throw new \RuntimeException("Carte inconnue : $code");
    }

    public function has(string $code): bool
    {
        return isset($this->cards[$code]);
    }

    /** @return array<string, array<string, mixed>> */
    public function allCards(): array
    {
        return $this->cards;
    }

    /** @return array<string, array<string, mixed>> */
    public function allHeroes(): array
    {
        return $this->heroes;
    }

    /** @return array<int, array<string, mixed>> cartes d'une catégorie donnée */
    public function byCategory(string $category): array
    {
        return array_values(array_filter($this->cards, static fn ($c) => $c['category'] === $category));
    }
}
