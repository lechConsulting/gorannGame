<?php

namespace App\Game;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publie des « pings » temps réel sur Mercure. Choix d'architecture : Mercure
 * sert de BUS DE NOTIFICATION, pas de canal de données. Le ping ne contient
 * aucune information secrète (mains cachées) ; chaque client, à réception,
 * re-`GET` l'état via l'API authentifiée et masquée par siège.
 *
 * Topics : `game/{id}` (partie en cours) et `lobby/{id}` (salle d'attente).
 * Les données étant re-fetchées côté serveur, les abonnements peuvent être
 * anonymes (le hub tourne en mode anonyme en dev).
 */
class GamePublisher
{
    public function __construct(private readonly HubInterface $hub)
    {
    }

    /** Notifie tous les joueurs de la partie qu'un nouvel état est disponible. */
    public function pingGame(int $sessionId, array $state): void
    {
        $this->publish("game/{$sessionId}", [
            'type' => 'update',
            'turn' => $state['turn'] ?? null,
            'activeSeat' => $state['activeSeat'] ?? null,
            'status' => $state['status'] ?? null,
        ]);
    }

    /** Notifie la salle d'attente qu'un changement de roster a eu lieu. */
    public function pingLobby(int $sessionId): void
    {
        $this->publish("lobby/{$sessionId}", ['type' => 'lobby']);
    }

    private function publish(string $topic, array $data): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR)));
        } catch (\Throwable) {
            // Le temps réel est un confort : si le hub est indisponible, le jeu
            // reste jouable (le client peut re-fetcher manuellement). On n'échoue
            // jamais une action de jeu à cause d'un ping raté.
        }
    }
}
