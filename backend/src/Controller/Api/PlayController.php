<?php

namespace App\Controller\Api;

use App\Entity\GameSession;
use App\Entity\GameSessionPlayer;
use App\Entity\User;
use App\Enum\SessionStatus;
use App\Game\CardCatalog;
use App\Game\GameEngine;
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
 * API de partie (multijoueur). Réservée aux joueurs authentifiés (ROLE_JOUEUR).
 * L'état renvoyé est masqué selon le siège du joueur qui regarde ; seul le
 * joueur du siège actif peut agir. Après chaque action, les joueurs automatiques
 * enchaînent leurs tours côté serveur, puis un ping Mercure notifie la table.
 */
#[Route('/api/play')]
class PlayController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GameRepository $games,
        private readonly GameSessionRepository $sessions,
        private readonly CardCatalog $catalog,
        private readonly GameSetupService $setup,
        private readonly GameEngine $engine,
        private readonly GameOrchestrator $orchestrator,
        private readonly StateView $view,
        private readonly GamePublisher $publisher,
    ) {
    }

    /**
     * Crée une partie directe (test rapide/solo hotseat). Le flux normal passe
     * par le lobby. Body: { slug?, players:[{pseudo, hero, kind?}] }
     */
    #[Route('/new', name: 'api_play_new', methods: ['POST'])]
    public function new(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true) ?? [];
        $slug = $data['slug'] ?? 'lotr-deck-builder';
        $players = $data['players'] ?? [];

        $game = $this->games->findOneBySlug($slug);
        if (!$game) {
            return $this->json(['error' => 'Jeu introuvable.'], 404);
        }
        if (\count($players) < 1 || \count($players) > $game->getMaxPlayers()) {
            return $this->json(['error' => 'Nombre de joueurs invalide.'], 422);
        }

        $this->catalog->load($game);

        try {
            $state = $this->setup->createState(array_map(
                // Le siège 0 est piloté par le créateur ; les autres sont des bots
                // par défaut (sauf mention contraire).
                static fn ($p, $i) => [
                    'userId' => 0 === $i ? $user->getId() : ($p['userId'] ?? null),
                    'pseudo' => $p['pseudo'] ?? 'Joueur',
                    'hero' => $p['hero'],
                    'kind' => 0 === $i ? 'human' : ($p['kind'] ?? 'bot'),
                ],
                $players,
                array_keys($players),
            ));
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        $session = new GameSession();
        $session->setGame($game);
        $session->setCode($this->randomCode());
        $session->setMaxPlayers(\count($players));
        $session->setStatus(SessionStatus::InProgress);
        $session->setCreatedBy($user);
        $session->setStartedAt(new \DateTimeImmutable());
        $this->orchestrator->arm($state);
        $session->setState($state);
        $this->em->persist($session);
        $this->em->flush();

        return $this->json($this->payload($session, $user), 201);
    }

    /** État courant d'une partie (masqué selon le joueur). */
    #[Route('/{id}', name: 'api_play_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (!$session) {
            return $this->json(['error' => 'Partie introuvable.'], 404);
        }
        $user = $this->currentUser();
        if ($this->seatOf($session, $user) === null && !$user->isAdmin()) {
            return $this->json(['error' => 'Vous ne participez pas à cette partie.'], 403);
        }
        $this->catalog->load($session->getGame());

        return $this->json($this->payload($session, $user));
    }

    /** Applique une action. Body: { type, iid?, eid?, payload? } */
    #[Route('/{id}/action', name: 'api_play_action', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function action(int $id, Request $request): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (!$session) {
            return $this->json(['error' => 'Partie introuvable.'], 404);
        }
        $user = $this->currentUser();
        $this->catalog->load($session->getGame());

        $state = $session->getState();
        $data = json_decode($request->getContent(), true) ?? [];
        $type = $data['type'] ?? '';
        $iid = isset($data['iid']) ? (int) $data['iid'] : null;

        // Pendant une Embuscade de Groupe : personne ne joue tant qu'elle n'est
        // pas résolue — seule la résolution d'un effet (révéler sa carte) est permise.
        if (!empty($state['groupAmbush']) && empty($state['groupAmbush']['done'])
            && !\in_array($type, ['apply-effect', 'skip-effect'], true)) {
            return $this->json(['error' => 'Embuscade de Groupe en cours : résous-la d\'abord.'], 409);
        }

        // Contrôle d'accès : résoudre un effet est réservé à SON propriétaire (un
        // joueur peut y être appelé hors de son tour, ex. « Mon Capitaine ») ;
        // toutes les autres actions sont réservées au joueur du siège actif.
        $seat = $this->seatOf($session, $user);
        if (\in_array($type, ['apply-effect', 'skip-effect'], true)) {
            $ownerSeat = null;
            foreach ($state['effects'] ?? [] as $e) {
                if (($e['eid'] ?? null) === (int) ($data['eid'] ?? 0)) {
                    $ownerSeat = $e['seat'] ?? ($state['activeSeat'] ?? -1);
                    break;
                }
            }
            if ($seat === null || $ownerSeat === null || $seat !== $ownerSeat) {
                return $this->json(['error' => 'Cet effet ne t\'appartient pas.'], 403);
            }
        } elseif ($seat === null || $seat !== ($state['activeSeat'] ?? -1)) {
            return $this->json(['error' => 'Ce n\'est pas votre tour.'], 403);
        }

        try {
            switch ($type) {
                // Instantané AVANT chaque coup réversible → annulation de la DERNIÈRE
                // action (jouer, tout jouer, acheter, vaincre) tant qu'on n'a rien fait après.
                case 'play':       $this->engine->snapshotBeforePlay($state); $this->engine->playCard($state, $iid); break;
                case 'undo-play':  $this->engine->undoLastPlay($state); break;
                case 'play-all':   $this->engine->snapshotBeforePlay($state); $this->engine->playAll($state); break;
                case 'buy-path':   $this->engine->snapshotBeforePlay($state); $this->engine->buyFromPath($state, $iid); break;
                case 'buy-valor':  $this->engine->snapshotBeforePlay($state); $this->engine->buyValor($state); break;
                case 'defeat':     $this->engine->snapshotBeforePlay($state); $this->engine->defeatArchenemy($state); break;
                case 'end-turn':   $this->engine->endTurn($state); break;
                case 'apply-effect': $this->engine->clearUndo($state); $this->engine->applyEffect($state, (int) ($data['eid'] ?? 0), $data['payload'] ?? []); break;
                case 'skip-effect':  $this->engine->clearUndo($state); $this->engine->skipEffect($state, (int) ($data['eid'] ?? 0)); break;
                default:
                    return $this->json(['error' => 'Action inconnue : '.$type], 422);
            }
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }

        // Résout immédiatement les effets réactifs destinés à des bots (ex. la
        // destruction de « Mon Capitaine » ciblant un bot), puis arme l'horloge
        // si le prochain joueur est un bot.
        $this->orchestrator->resolveBotEffects($state);
        $this->orchestrator->arm($state);

        $session->setState($state);
        $this->finalizeIfFinished($session, $state);
        $this->em->flush();
        $this->publisher->pingGame($session->getId(), $state);

        return $this->json($this->payload($session, $user));
    }

    /**
     * Fait avancer un tour de bot si le délai de cadence est écoulé. Appelé en
     * boucle par les clients (sondage/ping) ; le rythme est garanti côté serveur.
     */
    #[Route('/{id}/tick', name: 'api_play_tick', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function tick(int $id): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (!$session) {
            return $this->json(['error' => 'Partie introuvable.'], 404);
        }
        $user = $this->currentUser();
        if ($this->seatOf($session, $user) === null && !$user->isAdmin()) {
            return $this->json(['error' => 'Vous ne participez pas à cette partie.'], 403);
        }
        $this->catalog->load($session->getGame());

        $state = $session->getState();
        if ($this->orchestrator->stepIfDue($state)) {
            $session->setState($state);
            $this->finalizeIfFinished($session, $state);
            $this->em->flush();
            $this->publisher->pingGame($session->getId(), $state);
        }

        return $this->json($this->payload($session, $user));
    }

    /** L'hôte règle la pause entre les tours de bots. Body: { ms } */
    #[Route('/{id}/bot-delay', name: 'api_play_bot_delay', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function botDelay(int $id, Request $request): JsonResponse
    {
        $session = $this->sessions->find($id);
        if (!$session) {
            return $this->json(['error' => 'Partie introuvable.'], 404);
        }
        $user = $this->currentUser();
        if ($session->getCreatedBy()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Réservé à l\'hôte.'], 403);
        }
        $this->catalog->load($session->getGame());

        $data = json_decode($request->getContent(), true) ?? [];
        $ms = max(0, min(60000, (int) ($data['ms'] ?? GameOrchestrator::BOT_DELAY_MS)));

        $state = $session->getState();
        $state['botDelayMs'] = $ms;
        $session->setState($state);
        $this->em->flush();
        $this->publisher->pingGame($session->getId(), $state);

        return $this->json($this->payload($session, $user));
    }

    // ------------------------------------------------------------- Helpers

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    /** Marque la session terminée et écrit les classements, une seule fois. */
    private function finalizeIfFinished(GameSession $session, array $state): void
    {
        if ($state['status'] === 'finished' && $session->getStatus() !== SessionStatus::Finished) {
            $session->setStatus(SessionStatus::Finished);
            $session->setFinishedAt(new \DateTimeImmutable());
            $this->recordResults($session, $state);
        }
    }

    /** Siège contrôlé par cet utilisateur dans la partie, ou null. */
    private function seatOf(GameSession $session, User $user): ?int
    {
        foreach ($session->getState()['players'] ?? [] as $p) {
            if (($p['userId'] ?? null) === $user->getId()) {
                return $p['seat'];
            }
        }

        return null;
    }

    private function payload(GameSession $session, User $user): array
    {
        $viewerSeat = $this->seatOf($session, $user); // null pour un admin spectateur

        return [
            'id' => $session->getId(),
            'code' => $session->getCode(),
            'status' => $session->getStatus()->value,
            'mySeat' => $viewerSeat,
            'isHost' => $session->getCreatedBy()?->getId() === $user->getId(),
            'botDelayMs' => \App\Game\GameOrchestrator::delayMs($session->getState()),
            'state' => $this->view->build($session->getState(), $viewerSeat),
        ];
    }

    /** Écrit les résultats des joueurs HUMAINS pour alimenter les classements. */
    private function recordResults(GameSession $session, array $state): void
    {
        $scores = $state['scores'] ?? [];
        if (empty($scores)) {
            return;
        }
        // Rang : PV décroissant, départage par nombre d'Archennemis.
        usort($scores, static function (array $a, array $b): int {
            return [$b['vp'], $b['archenemies']] <=> [$a['vp'], $a['archenemies']];
        });
        $rankBySeat = [];
        foreach ($scores as $i => $sc) {
            $rankBySeat[$sc['seat']] = $i + 1;
        }
        $winnerSeat = $state['winnerSeat'] ?? null;
        $users = $this->em->getRepository(User::class);

        foreach ($state['players'] as $p) {
            if (($p['kind'] ?? 'human') !== 'human' || ($p['userId'] ?? null) === null) {
                continue; // les bots ne sont pas classés
            }
            $user = $users->find($p['userId']);
            if (!$user) {
                continue;
            }
            $seat = $p['seat'];
            $score = null;
            foreach ($scores as $sc) {
                if ($sc['seat'] === $seat) {
                    $score = (int) $sc['vp'];
                    break;
                }
            }

            $gsp = $this->em->getRepository(GameSessionPlayer::class)
                ->findOneBy(['session' => $session, 'user' => $user]) ?? new GameSessionPlayer();
            $gsp->setSession($session);
            $gsp->setUser($user);
            $gsp->setSeat($seat);
            $gsp->setScore($score);
            $gsp->setRank($rankBySeat[$seat] ?? null);
            $isWinner = $winnerSeat !== null && $seat === $winnerSeat;
            $gsp->setWinner($isWinner);
            $this->em->persist($gsp);

            if ($isWinner) {
                $session->setWinner($user);
            }
        }
    }

    private function randomCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }
}
