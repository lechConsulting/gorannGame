<?php

namespace App\Controller\Api;

use App\Entity\Card;
use App\Entity\Game;
use App\Entity\Hero;
use App\Repository\GameRepository;
use App\Repository\HeroRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/games')]
class GameController extends AbstractController
{
    /** Liste des jeux publiés (public). */
    #[Route('', name: 'api_games_list', methods: ['GET'])]
    public function list(GameRepository $games): JsonResponse
    {
        $data = array_map(
            fn (Game $g) => $this->serializeGame($g),
            $games->findPublished(),
        );

        return $this->json($data);
    }

    /** Détail d'un jeu avec toutes ses cartes et ses héros. */
    #[Route('/{slug}', name: 'api_games_detail', methods: ['GET'])]
    public function detail(string $slug, GameRepository $games, HeroRepository $heroes): JsonResponse
    {
        $game = $games->findOneBySlug($slug);
        if (!$game) {
            return $this->json(['error' => 'Jeu introuvable.'], 404);
        }

        $payload = $this->serializeGame($game);
        $payload['cards'] = array_map(
            fn (Card $c) => $this->serializeCard($c),
            $game->getCards()->toArray(),
        );
        $payload['heroes'] = array_map(
            fn (Hero $h) => [
                'name' => $h->getName(),
                'race' => $h->getRace(),
                'startingCardCode' => $h->getStartingCardCode(),
            ],
            $heroes->findByGame($game),
        );

        return $this->json($payload);
    }

    private function serializeGame(Game $g): array
    {
        return [
            'id' => $g->getId(),
            'name' => $g->getName(),
            'slug' => $g->getSlug(),
            'description' => $g->getDescription(),
            'minPlayers' => $g->getMinPlayers(),
            'maxPlayers' => $g->getMaxPlayers(),
            'published' => $g->isPublished(),
            'cardCount' => $g->getCards()->count(),
        ];
    }

    private function serializeCard(Card $c): array
    {
        return [
            'id' => $c->getId(),
            'code' => $c->getCode(),
            'name' => $c->getName(),
            'type' => $c->getType(),
            'category' => $c->getCategory()->value,
            'level' => $c->getLevel(),
            'hero' => $c->getHero(),
            'cost' => $c->getCost(),
            'victoryPoints' => $c->getVictoryPoints(),
            'text' => $c->getText(),
            'quantity' => $c->getQuantity(),
            'imagePath' => $c->getImagePath(),
            'attributes' => $c->getAttributes(),
        ];
    }
}
