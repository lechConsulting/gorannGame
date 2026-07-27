<?php

namespace App\Game;

use App\Entity\Game;
use App\Repository\CardRepository;
use App\Repository\HeroRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Exporte l'intégralité du deck d'un jeu (cartes + héros) depuis la base vers
 * `data/deck.json`. C'est la source de vérité éditée par le back-office ;
 * `app:seed` relit ce fichier en priorité (voir SeedCommand).
 */
class DeckExporter
{
    public function __construct(
        private readonly CardRepository $cards,
        private readonly HeroRepository $heroes,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function export(Game $game): void
    {
        $cards = [];
        foreach ($this->cards->findByGame($game) as $c) {
            $cards[] = [
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
        $heroes = [];
        foreach ($this->heroes->findByGame($game) as $h) {
            $heroes[] = [
                'name' => $h->getName(),
                'race' => $h->getRace(),
                'startingCardCode' => $h->getStartingCardCode(),
            ];
        }

        $payload = [
            '_comment' => 'Deck exporté depuis le back-office (source de vérité). Régénéré à chaque modification.',
            'game' => $game->getSlug(),
            'exportedAt' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'cards' => $cards,
            'heroes' => $heroes,
        ];

        file_put_contents(
            $this->projectDir.'/data/deck.json',
            json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
        );
    }
}
