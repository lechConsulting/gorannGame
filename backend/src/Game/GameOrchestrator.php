<?php

namespace App\Game;

/**
 * Cadence les tours des joueurs automatiques. Contrairement à un enchaînement
 * instantané, les bots jouent **un tour à la fois**, espacés de BOT_DELAY_MS,
 * pour laisser le temps de lire le récapitulatif de chaque tour.
 *
 * Le rythme est piloté par une horloge dans l'état (`botClockAt`, en ms) et par
 * un endpoint `tick` que les clients appellent : `stepIfDue` n'avance un bot que
 * si le délai est écoulé (rythme autoritaire côté serveur, insensible au nombre
 * de clients qui sondent).
 */
class GameOrchestrator
{
    /** Délai par défaut entre deux tours de bots (ms) — réglable par l'hôte. */
    public const BOT_DELAY_MS = 10000;

    /** Délai courant (réglable par l'hôte via `state['botDelayMs']`). */
    public static function delayMs(array $state): int
    {
        return (int) ($state['botDelayMs'] ?? self::BOT_DELAY_MS);
    }

    /** Garde-fou anti-boucle pour le mode headless. */
    private const MAX_BOT_TURNS = 200;

    public function __construct(private readonly BotPlayer $bot)
    {
    }

    /**
     * Arme l'horloge si c'est au tour d'un bot (après une action humaine ou au
     * démarrage). Ne fait pas jouer le bot : c'est `stepIfDue` qui l'avancera.
     */
    /** Une Embuscade de Groupe non résolue gèle la progression des tours de bots. */
    private function frozen(array $state): bool
    {
        return !empty($state['groupAmbush']) && empty($state['groupAmbush']['done']);
    }

    public function arm(array &$state): void
    {
        if ($state['status'] !== 'finished' && !$this->frozen($state) && $this->activeIsBot($state)) {
            if (empty($state['botClockAt'])) {
                $state['botClockAt'] = $this->nowMs();
            }
        } else {
            unset($state['botClockAt']);
        }
    }

    /**
     * Avance d'UN tour de bot si le délai est écoulé. Renvoie true si un bot a
     * effectivement joué (l'appelant doit alors persister + notifier).
     */
    public function stepIfDue(array &$state): bool
    {
        if ($state['status'] === 'finished' || $this->frozen($state) || !$this->activeIsBot($state)) {
            if (!$this->frozen($state)) {
                unset($state['botClockAt']);
            }

            return false;
        }
        $due = ($state['botClockAt'] ?? 0) + self::delayMs($state);
        if ($this->nowMs() < $due) {
            return false; // pas encore l'heure
        }

        $this->bot->playTurn($state);
        $this->bot->resolveBotEffects($state); // effets réactifs reçus par des bots

        // Ré-arme pour le bot suivant, ou nettoie si un humain reprend / fin.
        if ($state['status'] !== 'finished' && $this->activeIsBot($state)) {
            $state['botClockAt'] = $this->nowMs();
        } else {
            unset($state['botClockAt']);
        }

        return true;
    }

    /** Enchaîne tous les tours de bots sans délai (headless / tests). */
    public function runAllBots(array &$state): void
    {
        $guard = 0;
        while ($state['status'] !== 'finished' && $guard++ < self::MAX_BOT_TURNS) {
            $this->bot->resolveBotEffects($state); // résout d'abord révélations/effets bots (débloque une Embuscade de Groupe)
            if ($this->frozen($state) || !$this->activeIsBot($state)) {
                break;
            }
            $this->bot->playTurn($state);
            $this->bot->resolveBotEffects($state);
        }
        unset($state['botClockAt']);
    }

    /** Résout les effets réactifs destinés à des bots (après une action humaine). */
    public function resolveBotEffects(array &$state): void
    {
        $this->bot->resolveBotEffects($state);
    }

    /** Millisecondes restantes avant le prochain tour de bot (null si pas de bot en attente). */
    public function msUntilNextBot(array $state): ?int
    {
        if ($state['status'] === 'finished' || !$this->activeIsBot($state)) {
            return null;
        }

        return max(0, (int) (($state['botClockAt'] ?? $this->nowMs()) + self::delayMs($state) - $this->nowMs()));
    }

    public function activeIsBot(array $state): bool
    {
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $state['activeSeat']) {
                return ($p['kind'] ?? 'human') === 'bot';
            }
        }

        return false;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
