<?php

namespace App\Controller\Api;

use App\Entity\Card;
use App\Entity\Hero;
use App\Enum\CardCategory;
use App\Game\DeckExporter;
use App\Repository\CardRepository;
use App\Repository\GameRepository;
use App\Repository\HeroRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Back-office (réservé ROLE_ADMIN via security.yaml : ^/api/admin).
 * CRUD des cartes et des héros d'un jeu.
 */
#[Route('/api/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GameRepository $games,
        private readonly CardRepository $cards,
        private readonly HeroRepository $heroes,
        private readonly DeckExporter $exporter,
    ) {
    }

    // ---------------------------------------------------------------- Cartes

    #[Route('/cards', name: 'api_admin_cards', methods: ['GET'])]
    public function listCards(Request $request): JsonResponse
    {
        $game = $this->games->findOneBySlug($request->query->get('game', 'lotr-deck-builder'));
        if (!$game) {
            return $this->json(['error' => 'Jeu introuvable.'], 404);
        }

        return $this->json(array_map($this->serialize(...), $this->cards->findByGame($game)));
    }

    #[Route('/cards', name: 'api_admin_card_create', methods: ['POST'])]
    public function createCard(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $game = $this->games->findOneBySlug($data['game'] ?? 'lotr-deck-builder');
        if (!$game) {
            return $this->json(['error' => 'Jeu introuvable.'], 404);
        }
        $card = new Card();
        $card->setGame($game);
        $this->apply($card, $data);
        $this->em->persist($card);
        $this->em->flush();
        $this->exporter->export($game);

        return $this->json($this->serialize($card), 201);
    }

    #[Route('/cards/{id}', name: 'api_admin_card_update', methods: ['PUT', 'PATCH'])]
    public function updateCard(int $id, Request $request): JsonResponse
    {
        $card = $this->cards->find($id);
        if (!$card) {
            return $this->json(['error' => 'Carte introuvable.'], 404);
        }
        $this->apply($card, json_decode($request->getContent(), true) ?? []);
        $this->em->flush();
        $this->exporter->export($card->getGame());

        return $this->json($this->serialize($card));
    }

    #[Route('/cards/{id}', name: 'api_admin_card_delete', methods: ['DELETE'])]
    public function deleteCard(int $id): JsonResponse
    {
        $card = $this->cards->find($id);
        if (!$card) {
            return $this->json(['error' => 'Carte introuvable.'], 404);
        }
        $game = $card->getGame();
        $this->em->remove($card);
        $this->em->flush();
        if ($game) {
            $this->exporter->export($game);
        }

        return $this->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- Héros

    #[Route('/heroes', name: 'api_admin_heroes', methods: ['GET'])]
    public function listHeroes(Request $request): JsonResponse
    {
        $game = $this->games->findOneBySlug($request->query->get('game', 'lotr-deck-builder'));
        if (!$game) {
            return $this->json(['error' => 'Jeu introuvable.'], 404);
        }

        return $this->json(array_map(
            fn (Hero $h) => ['id' => $h->getId(), 'name' => $h->getName(), 'race' => $h->getRace(), 'startingCardCode' => $h->getStartingCardCode()],
            $this->heroes->findByGame($game),
        ));
    }

    #[Route('/heroes/{id}', name: 'api_admin_hero_update', methods: ['PUT', 'PATCH'])]
    public function updateHero(int $id, Request $request): JsonResponse
    {
        $hero = $this->heroes->find($id);
        if (!$hero) {
            return $this->json(['error' => 'Héros introuvable.'], 404);
        }
        $data = json_decode($request->getContent(), true) ?? [];
        if (isset($data['name'])) {
            $hero->setName($data['name']);
        }
        if (isset($data['race'])) {
            $hero->setRace($data['race']);
        }
        if (isset($data['startingCardCode'])) {
            $hero->setStartingCardCode($data['startingCardCode']);
        }
        $this->em->flush();
        if ($hero->getGame()) {
            $this->exporter->export($hero->getGame());
        }

        return $this->json(['id' => $hero->getId(), 'name' => $hero->getName(), 'race' => $hero->getRace(), 'startingCardCode' => $hero->getStartingCardCode()]);
    }

    // ---------------------------------------------------------------- Helpers

    private function apply(Card $card, array $d): void
    {
        if (isset($d['code'])) {
            $card->setCode($d['code']);
        }
        if (isset($d['name'])) {
            $card->setName($d['name']);
        }
        if (isset($d['type'])) {
            $card->setType($d['type']);
        }
        if (isset($d['category'])) {
            $card->setCategory(CardCategory::from($d['category']));
        }
        if (\array_key_exists('cost', $d)) {
            $card->setCost($d['cost'] === '' || $d['cost'] === null ? null : (int) $d['cost']);
        }
        if (\array_key_exists('pv', $d)) {
            $card->setVictoryPoints($d['pv'] === '' || $d['pv'] === null ? null : (int) $d['pv']);
        }
        if (\array_key_exists('level', $d)) {
            $card->setLevel($d['level'] === '' || $d['level'] === null ? null : (int) $d['level']);
        }
        if (\array_key_exists('hero', $d)) {
            $card->setHero($d['hero'] ?: null);
        }
        if (isset($d['quantity'])) {
            $card->setQuantity((int) $d['quantity']);
        }
        if (\array_key_exists('text', $d)) {
            $card->setText($d['text'] ?: null);
        }
        if (\array_key_exists('attributes', $d) && \is_array($d['attributes'])) {
            $card->setAttributes($d['attributes']);
        }
    }

    private function serialize(Card $c): array
    {
        return [
            'id' => $c->getId(),
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
}
