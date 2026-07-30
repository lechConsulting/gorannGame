<?php

namespace App\Controller\Api;

use App\Entity\GameSession;
use App\Entity\User;
use App\Enum\SessionStatus;
use App\Game\CardCatalog;
use App\Game\GameOrchestrator;
use App\Game\GamePublisher;
use App\Game\GameSetupService;
use App\Game\StateView;
use App\Repository\GameRepository;
use App\Repository\GameSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Salle d'attente (lobby) : créer une table, la rejoindre par code, choisir son
 * héros, ajouter des joueurs automatiques (bots), puis démarrer. Réservé aux
 * joueurs authentifiés (ROLE_JOUEUR via security.yaml).
 *
 * Tant que la partie est en attente, le roster vit dans `state['lobby']['seats']`
 * (l'index = le siège). Au démarrage, l'état de partie complet est construit par
 * GameSetupService et remplace le roster.
 */
#[Route('/api/lobby')]
class LobbyController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GameRepository $games,
        private readonly GameSessionRepository $sessions,
        private readonly CardCatalog $catalog,
        private readonly GameSetupService $setup,
        private readonly GameOrchestrator $orchestrator,
        private readonly StateView $view,
        private readonly GamePublisher $publisher,
    ) {
    }

    /** Tables ouvertes qu'on peut rejoindre. */
    #[Route('', name: 'api_lobby_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $out = [];
        foreach ($this->sessions->findJoinable() as $s) {
            $seats = $s->getState()['lobby']['seats'] ?? [];
            $out[] = [
                'id' => $s->getId(),
                'code' => $s->getCode(),
                'game' => $s->getGame()?->getName(),
                'players' => \count($seats),
                'maxPlayers' => $s->getMaxPlayers(),
                'host' => $s->getCreatedBy()?->getPseudo(),
            ];
        }

        return $this->json($out);
    }

    /**
     * Parties EN COURS où le joueur occupe un siège — pour reprendre après une
     * coupure réseau (l'état complet est déjà persisté à chaque action).
     */
    #[Route('/mine', name: 'api_lobby_mine', methods: ['GET'])]
    public function mine(): JsonResponse
    {
        $user = $this->currentUser();
        $out = [];
        foreach ($this->sessions->findInProgress() as $s) {
            $state = $s->getState();
            $players = $state['players'] ?? [];
            $mySeat = null;
            foreach ($players as $p) {
                if (($p['userId'] ?? null) === $user->getId()) {
                    $mySeat = $p['seat'];
                    break;
                }
            }
            if ($mySeat === null) {
                continue; // le joueur n'est pas à cette table
            }
            $activePseudo = null;
            foreach ($players as $p) {
                if ($p['seat'] === ($state['activeSeat'] ?? -1)) {
                    $activePseudo = $p['pseudo'];
                    break;
                }
            }
            $out[] = [
                'id' => $s->getId(),
                'code' => $s->getCode(),
                'game' => $s->getGame()?->getName(),
                'turn' => $state['turn'] ?? 0,
                'players' => array_map(
                    static fn ($p) => ['pseudo' => $p['pseudo'], 'kind' => $p['kind'] ?? 'human'],
                    $players,
                ),
                'activePseudo' => $activePseudo,
                'myTurn' => ($state['activeSeat'] ?? -1) === $mySeat,
                'isHost' => $s->getCreatedBy()?->getId() === $user->getId(),
            ];
        }

        return $this->json($out);
    }

    /**
     * Abandonne (supprime définitivement) une partie où le joueur a un siège.
     * Utile pour nettoyer les parties de test / abandonnées de la liste de reprise.
     */
    #[Route('/{id}/abandon', name: 'api_lobby_abandon', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function abandon(int $id): JsonResponse
    {
        $user = $this->currentUser();
        $session = $this->sessions->find($id);
        if (!$session) {
            return $this->json(['error' => 'Partie introuvable.'], 404);
        }

        // Seul un joueur assis à la table (ou un admin) peut l'abandonner.
        $seated = false;
        foreach ($session->getState()['players'] ?? [] as $p) {
            if (($p['userId'] ?? null) === $user->getId()) {
                $seated = true;
                break;
            }
        }
        if (!$seated && !$user->isAdmin()) {
            return $this->json(['error' => 'Tu n\'es pas à cette table.'], 403);
        }

        $this->em->remove($session);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    /** Crée une table. Body: { slug?, maxPlayers? } */
    #[Route('/create', name: 'api_lobby_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $slug = $data['slug'] ?? 'lotr-deck-builder';

        $game = $this->games->findOneBySlug($slug);
        if (!$game) {
            return $this->json(['error' => 'Jeu introuvable.'], 404);
        }
        $maxPlayers = max($game->getMinPlayers(), min((int) ($data['maxPlayers'] ?? $game->getMaxPlayers()), $game->getMaxPlayers()));

        $session = new GameSession();
        $session->setGame($game);
        $session->setCode($this->randomCode());
        $session->setMaxPlayers($maxPlayers);
        $session->setStatus(SessionStatus::Waiting);
        $session->setCreatedBy($user);
        // Mode Aléatoire par défaut : le créateur reçoit un héros au hasard.
        $this->catalog->load($game);
        $creatorSeat = $this->humanSeat($user, $data['pseudo'] ?? null);
        $creatorSeat['hero'] = $this->randomFreeHero([]);
        $session->setState(['lobby' => [
            'slug' => $slug,
            'heroMode' => 'random', // 'random' (défaut) | 'choice'
            'seats' => [$creatorSeat],
        ]]);
        $this->em->persist($session);
        $this->em->flush();

        return $this->json($this->lobbyView($session, $user), 201);
    }

    /** Rejoint une table par code. Body: { code } */
    #[Route('/join', name: 'api_lobby_join', methods: ['POST'])]
    public function join(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        $session = $this->sessions->findOneByCode($code);
        if (!$session) {
            return $this->json(['error' => 'Table introuvable.'], 404);
        }
        if ($session->getStatus() !== SessionStatus::Waiting) {
            return $this->json(['error' => 'Cette partie a déjà démarré.'], 409);
        }

        $state = $session->getState();
        $seats = &$state['lobby']['seats'];
        foreach ($seats as $s) {
            if (($s['userId'] ?? null) === $user->getId()) {
                return $this->json($this->lobbyView($session, $user)); // déjà à la table
            }
        }
        if (\count($seats) >= $session->getMaxPlayers()) {
            return $this->json(['error' => 'La table est complète.'], 409);
        }

        $seat = $this->humanSeat($user, $data['pseudo'] ?? null);
        if (($state['lobby']['heroMode'] ?? 'random') === 'random') {
            $this->catalog->load($session->getGame());
            $seat['hero'] = $this->randomFreeHero($seats); // héros au hasard à l'arrivée
        }
        $seats[] = $seat;
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());

        return $this->json($this->lobbyView($session, $user));
    }

    /** État courant de la table (pour la salle d'attente). */
    #[Route('/{id}', name: 'api_lobby_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (!$session) {
            return $this->json(['error' => 'Table introuvable.'], 404);
        }

        return $this->json($this->lobbyView($session, $this->currentUser()));
    }

    /** Change son propre pseudo à la table. Body: { pseudo } */
    #[Route('/{id}/pseudo', name: 'api_lobby_pseudo', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function pseudo(int $id, Request $request): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id);
        if ($err) {
            return $err;
        }
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $pseudo = mb_substr(trim((string) ($data['pseudo'] ?? '')), 0, 24);
        if ($pseudo === '') {
            return $this->json(['error' => 'Pseudo vide.'], 422);
        }

        $state = $session->getState();
        $seats = &$state['lobby']['seats'];
        foreach ($seats as $i => $s) {
            if (($s['userId'] ?? null) === $user->getId()) {
                $seats[$i]['pseudo'] = $pseudo;
                $session->setState($state);
                $this->em->flush();
                $this->publisher->pingLobby($session->getId());

                return $this->json($this->lobbyView($session, $user));
            }
        }

        return $this->json(['error' => 'Tu n\'es pas à cette table.'], 403);
    }

    /** Choisit un héros pour son siège (ou, pour l'hôte, un siège bot). Body: { hero, seat? } */
    #[Route('/{id}/hero', name: 'api_lobby_hero', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function hero(int $id, Request $request): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id);
        if ($err) {
            return $err;
        }
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $hero = (string) ($data['hero'] ?? '');

        $this->catalog->load($session->getGame());
        if (!isset($this->catalog->allHeroes()[$hero])) {
            return $this->json(['error' => 'Héros inconnu.'], 422);
        }

        $state = $session->getState();
        if (($state['lobby']['heroMode'] ?? 'random') === 'random') {
            return $this->json(['error' => 'Mode aléatoire : les héros sont tirés au sort.'], 409);
        }
        $seats = &$state['lobby']['seats'];
        $target = $this->resolveTargetSeat($seats, $user, $session, isset($data['seat']) ? (int) $data['seat'] : null);
        if ($target === null) {
            return $this->json(['error' => 'Siège non modifiable.'], 403);
        }
        // Héros unique à la table.
        foreach ($seats as $i => $s) {
            if ($i !== $target && ($s['hero'] ?? null) === $hero) {
                return $this->json(['error' => 'Ce héros est déjà pris.'], 409);
            }
        }
        $seats[$target]['hero'] = $hero;
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());

        return $this->json($this->lobbyView($session, $user));
    }

    /** L'hôte règle le mode de sélection des héros. Body: { mode } (random|choice).
     *  Passer/repasser en 'random' tire au sort de nouveaux héros pour tous. */
    #[Route('/{id}/hero-mode', name: 'api_lobby_hero_mode', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function heroMode(int $id, Request $request): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id, hostOnly: true);
        if ($err) {
            return $err;
        }
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = ($data['mode'] ?? 'random') === 'choice' ? 'choice' : 'random';

        $state = $session->getState();
        $state['lobby']['heroMode'] = $mode;
        if ($mode === 'random') {
            $this->catalog->load($session->getGame());
            $this->assignRandomHeroes($state['lobby']['seats']); // (re)tirage pour tous
        }
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());

        return $this->json($this->lobbyView($session, $this->currentUser()));
    }

    /** L'hôte ajoute un joueur automatique au prochain siège libre. */
    #[Route('/{id}/bot', name: 'api_lobby_bot', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addBot(int $id): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id, hostOnly: true);
        if ($err) {
            return $err;
        }
        $state = $session->getState();
        $seats = &$state['lobby']['seats'];
        if (\count($seats) >= $session->getMaxPlayers()) {
            return $this->json(['error' => 'La table est complète.'], 409);
        }

        $this->catalog->load($session->getGame());
        $hero = ($state['lobby']['heroMode'] ?? 'random') === 'random'
            ? $this->randomFreeHero($seats)
            : $this->firstFreeHero($seats);
        if ($hero === null) {
            return $this->json(['error' => 'Plus de héros disponible.'], 409);
        }
        $seats[] = ['kind' => 'bot', 'userId' => null, 'pseudo' => '🤖 '.$hero, 'hero' => $hero, 'level' => 'normal'];
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());

        return $this->json($this->lobbyView($session, $this->currentUser()));
    }

    /** L'hôte règle le niveau d'un bot. Body: { seat, level } (facile|normal|difficile) */
    #[Route('/{id}/bot-level', name: 'api_lobby_bot_level', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function botLevel(int $id, Request $request): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id, hostOnly: true);
        if ($err) {
            return $err;
        }
        $data = json_decode($request->getContent(), true) ?? [];
        $seat = (int) ($data['seat'] ?? -1);
        $level = (string) ($data['level'] ?? '');
        if (!\in_array($level, ['facile', 'normal', 'difficile'], true)) {
            return $this->json(['error' => 'Niveau invalide.'], 422);
        }

        $state = $session->getState();
        $seats = &$state['lobby']['seats'];
        if (!isset($seats[$seat]) || ($seats[$seat]['kind'] ?? 'human') !== 'bot') {
            return $this->json(['error' => 'Ce siège n\'est pas un bot.'], 422);
        }
        $seats[$seat]['level'] = $level;
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());

        return $this->json($this->lobbyView($session, $this->currentUser()));
    }

    /** L'hôte retire un siège (bot ou joueur ; pas le sien). Body: { seat } */
    #[Route('/{id}/remove', name: 'api_lobby_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(int $id, Request $request): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id, hostOnly: true);
        if ($err) {
            return $err;
        }
        $data = json_decode($request->getContent(), true) ?? [];
        $seat = (int) ($data['seat'] ?? -1);

        $state = $session->getState();
        $seats = &$state['lobby']['seats'];
        if ($seat <= 0 || !isset($seats[$seat])) {
            return $this->json(['error' => 'Siège invalide (l\'hôte ne peut pas être retiré).'], 422);
        }
        array_splice($seats, $seat, 1); // ré-indexe les sièges
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());

        return $this->json($this->lobbyView($session, $this->currentUser()));
    }

    /** L'hôte démarre la partie. */
    #[Route('/{id}/start', name: 'api_lobby_start', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function start(int $id): JsonResponse
    {
        [$session, $err] = $this->waitingSession($id, hostOnly: true);
        if ($err) {
            return $err;
        }
        $state = $session->getState();
        $seats = $state['lobby']['seats'];
        if (\count($seats) < $session->getGame()->getMinPlayers()) {
            return $this->json(['error' => 'Il faut au moins '.$session->getGame()->getMinPlayers().' joueurs.'], 422);
        }
        foreach ($seats as $s) {
            if (empty($s['hero'])) {
                return $this->json(['error' => 'Tous les joueurs doivent choisir un héros.'], 422);
            }
        }

        $this->catalog->load($session->getGame());
        try {
            $gameState = $this->setup->createState(array_map(static fn ($s) => [
                'userId' => $s['userId'] ?? null,
                'pseudo' => $s['pseudo'],
                'hero' => $s['hero'],
                'kind' => $s['kind'] ?? 'human',
                'level' => $s['level'] ?? 'facile',
            ], $seats));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        $this->orchestrator->arm($gameState); // arme l'horloge si le 1er joueur était un bot (ne devrait pas)
        $session->setState($gameState);
        $session->setStatus(SessionStatus::InProgress);
        $session->setStartedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->publisher->pingLobby($session->getId());
        $this->publisher->pingGame($session->getId(), $gameState);

        return $this->json(['id' => $session->getId(), 'started' => true]);
    }

    // ------------------------------------------------------------- Helpers

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** @return array{0: ?GameSession, 1: ?JsonResponse} */
    private function waitingSession(int $id, bool $hostOnly = false): array
    {
        $session = $this->sessions->find($id);
        if (!$session) {
            return [null, $this->json(['error' => 'Table introuvable.'], 404)];
        }
        if ($session->getStatus() !== SessionStatus::Waiting) {
            return [null, $this->json(['error' => 'Cette partie a déjà démarré.'], 409)];
        }
        if ($hostOnly && $session->getCreatedBy()?->getId() !== $this->currentUser()->getId()) {
            return [null, $this->json(['error' => 'Réservé à l\'hôte.'], 403)];
        }

        return [$session, null];
    }

    private function humanSeat(User $user, ?string $pseudo = null): array
    {
        $p = trim((string) $pseudo);
        if ($p === '') {
            $p = (string) ($user->getPseudo() ?: 'Joueur');
        }

        return ['kind' => 'human', 'userId' => $user->getId(), 'pseudo' => mb_substr($p, 0, 24), 'hero' => null];
    }

    /** Siège modifiable par l'utilisateur : le sien, ou un siège bot si hôte. */
    private function resolveTargetSeat(array $seats, User $user, GameSession $session, ?int $requestedSeat): ?int
    {
        $isHost = $session->getCreatedBy()?->getId() === $user->getId();
        if ($requestedSeat !== null) {
            if (!isset($seats[$requestedSeat])) {
                return null;
            }
            $s = $seats[$requestedSeat];
            if (($s['userId'] ?? null) === $user->getId()) {
                return $requestedSeat;
            }
            if ($isHost && ($s['kind'] ?? 'human') === 'bot') {
                return $requestedSeat;
            }

            return null;
        }
        foreach ($seats as $i => $s) {
            if (($s['userId'] ?? null) === $user->getId()) {
                return $i;
            }
        }

        return null;
    }

    private function firstFreeHero(array $seats): ?string
    {
        $used = array_filter(array_map(static fn ($s) => $s['hero'] ?? null, $seats));
        foreach (array_keys($this->catalog->allHeroes()) as $name) {
            if (!\in_array($name, $used, true)) {
                return $name;
            }
        }

        return null;
    }

    /** Un héros libre AU HASARD (distinct des héros déjà pris). */
    private function randomFreeHero(array $seats): ?string
    {
        $used = array_filter(array_map(static fn ($s) => $s['hero'] ?? null, $seats));
        $free = array_values(array_diff(array_keys($this->catalog->allHeroes()), $used));

        return empty($free) ? null : $free[array_rand($free)];
    }

    /** Réattribue à chaque siège un héros aléatoire DISTINCT (mode Aléatoire). */
    private function assignRandomHeroes(array &$seats): void
    {
        $all = array_keys($this->catalog->allHeroes());
        shuffle($all);
        foreach ($seats as $i => $s) {
            $seats[$i]['hero'] = array_shift($all);
        }
    }

    private function lobbyView(GameSession $session, User $user): array
    {
        // Partie déjà lancée : le front doit basculer vers le plateau.
        if ($session->getStatus() !== SessionStatus::Waiting) {
            return ['id' => $session->getId(), 'code' => $session->getCode(), 'status' => $session->getStatus()->value, 'started' => true];
        }

        $seats = $session->getState()['lobby']['seats'] ?? [];
        $isHost = $session->getCreatedBy()?->getId() === $user->getId();
        $mySeat = null;
        $rows = [];
        foreach ($seats as $i => $s) {
            $isMe = ($s['userId'] ?? null) === $user->getId();
            if ($isMe) {
                $mySeat = $i;
            }
            $rows[] = [
                'seat' => $i,
                'kind' => $s['kind'] ?? 'human',
                'pseudo' => $s['pseudo'],
                'hero' => $s['hero'] ?? null,
                'level' => ($s['kind'] ?? 'human') === 'bot' ? ($s['level'] ?? 'normal') : null,
                'isMe' => $isMe,
            ];
        }

        return [
            'id' => $session->getId(),
            'code' => $session->getCode(),
            'status' => $session->getStatus()->value,
            'started' => false,
            'isHost' => $isHost,
            'mySeat' => $mySeat,
            'maxPlayers' => $session->getMaxPlayers(),
            'heroMode' => $session->getState()['lobby']['heroMode'] ?? 'random',
            'seats' => $rows,
        ];
    }

    private function randomCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }
}
