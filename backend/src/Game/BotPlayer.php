<?php

namespace App\Game;

/**
 * Joueur automatique (heuristique simple). Il pilote le siège actif via la même
 * API que l'IHM (GameEngine) — aucune règle n'est réimplémentée ici, ce qui
 * garantit la cohérence avec le jeu humain.
 *
 * Stratégie d'un tour :
 *   1. résoudre les effets en attente (embuscades subies au début du tour) ;
 *   2. jouer toute la main (Pouvoir + effets), puis re-résoudre les effets ;
 *   3. dépenser : vaincre l'Archennemi si abordable, puis acheter en boucle la
 *      meilleure carte abordable du Chemin (à défaut, une Valeur) ;
 *   4. terminer le tour.
 */
class BotPlayer
{
    /** Garde-fous anti-boucle (états dégénérés). */
    private const MAX_EFFECT_STEPS = 200;
    private const MAX_BUYS = 30;

    public function __construct(
        private readonly GameEngine $engine,
        private readonly CardCatalog $catalog,
    ) {
    }

    public function playTurn(array &$state): void
    {
        if ($state['status'] === 'finished') {
            return;
        }

        $this->resolveEffects($state);       // embuscades en début de tour
        if ($state['status'] === 'finished') {
            return;
        }

        $this->engine->playAll($state);
        $this->resolveEffects($state);

        $this->spend($state);

        if ($state['status'] !== 'finished') {
            $this->resolveEffects($state);   // sécurité avant de finir
            $this->engine->endTurn($state);
        }
    }

    /** Résout les effets du bot actif (pendant son tour). */
    private function resolveEffects(array &$state): void
    {
        $this->resolveBotEffects($state, $state['activeSeat']);
    }

    /**
     * Résout les effets appartenant à des BOTS avec des choix par défaut. Sans
     * `$onlySeat`, traite TOUS les sièges bots — y compris hors de leur tour
     * (ex. destruction réactive de « Mon Capitaine, Mon Roi »). Appelé aussi par
     * l'orchestrateur après une action humaine.
     */
    public function resolveBotEffects(array &$state, ?int $onlySeat = null): void
    {
        $guard = 0;
        while ($guard++ < self::MAX_EFFECT_STEPS) {
            $found = null;
            foreach ($state['effects'] ?? [] as $e) {
                $seat = $e['seat'] ?? $state['activeSeat'];
                if ($onlySeat !== null && $seat !== $onlySeat) {
                    continue;
                }
                $p = $this->engine->playerBySeat($state, $seat);
                if ($p !== null && ($p['kind'] ?? 'human') === 'bot') {
                    $found = $e;
                    break;
                }
            }
            if ($found === null) {
                return;
            }
            $seat = $found['seat'] ?? $state['activeSeat'];
            $player = $this->engine->playerBySeat($state, $seat);
            $optional = ($found['kind'] ?? 'pos') !== 'neg';

            if ($optional && !$this->wantsToApply($state, $player, $found)) {
                $this->engine->skipEffect($state, $found['eid']);
            } else {
                $this->engine->applyEffect($state, $found['eid'], $this->choosePayload($state, $player, $found));
            }
            if ($state['status'] === 'finished') {
                return;
            }
        }
    }

    /** Un effet optionnel vaut-il la peine d'être appliqué ? */
    private function wantsToApply(array $state, array $player, array $step): bool
    {
        if (!$this->engine->stepApplicable($state, $player, $step)) {
            return false;
        }

        return match ($step['op']) {
            // Gains francs → toujours bénéfiques.
            'draw', 'takeFromDiscard', 'gainFromPath', 'reveal', 'nameType', 'nameTypeMain', 'playFromPath', 'putGainedOnDeck' => true,
            // Choix inter-joueurs (Mon Capitaine, Ulaire Ostea, Désespoir…) : oui si applicable.
            'choosePlayer', 'destroyDespair', 'ulaireGive' => true,
            // Prendre une Corruption optionnelle : le bot refuse (garde son deck propre).
            'corruption' => false,
            // Détruire une carte : uniquement si une carte « déchet » est retirable.
            'destroy' => $this->hasJunkToDestroy($state, $step, $player),
            // Mettre une carte sur le deck (Pierres de Vision) : utile si la main a du contenu.
            'putOnDeck' => !empty($this->engine->effectOptions($state, $player, $step)),
            default => true,
        };
    }

    /** Construit la sélection (payload) attendue par applyEffect selon l'op. */
    private function choosePayload(array $state, array $player, array $step): array
    {
        switch ($step['op']) {
            case 'nameType':
            case 'nameTypeMain':
                return ['value' => $this->mostCommonPathType($state)];

            case 'choosePlayer':       // vise un autre joueur (le premier venu)
            case 'choosePlayerDraw':
            case 'chooseOthersDraw':   // exclut un adversaire de la pioche (le bot pioche quand même)
            case 'ulaireGive':         // donne sa pire carte à un adversaire
                $seat = $step['seat'] ?? $state['activeSeat'];
                foreach ($state['players'] as $p) {
                    if ($p['seat'] !== $seat) {
                        return ['seat' => $p['seat']];
                    }
                }

                return [];

            case 'destroyDespair': // détruit un Désespoir : de préférence le sien (allège son deck)
                $seat = $step['seat'] ?? $state['activeSeat'];
                foreach (array_merge([$seat], array_map(fn ($p) => $p['seat'], $state['players'])) as $s) {
                    foreach (($this->engine->playerBySeat($state, $s)['discard'] ?? []) as $iid) {
                        if ($this->engine->def($state, $iid)['code'] === 'desespoir') {
                            return ['seat' => $s];
                        }
                    }
                }

                return [];

            case 'groupReveal':  // Embuscade de Groupe : révèle une carte de coût médian (évite les extrêmes)
                $cards = [];
                foreach ($player['hand'] as $iid) {
                    $cards[] = ['iid' => $iid, 'cost' => (int) ($this->engine->def($state, $iid)['cost'] ?? 0)];
                }
                if (empty($cards)) {
                    return [];
                }
                usort($cards, fn ($a, $b) => $a['cost'] <=> $b['cost']);

                return ['iid' => $cards[intdiv(\count($cards), 2)]['iid']];

            case 'destroy':      // retire la pire carte
            case 'discard':      // défausse la pire carte (embuscade)
                $iid = $this->worstOption($state, $step, $player);

                return $iid !== null ? ['iid' => $iid] : [];

            case 'takeFromDiscard': // reprend la meilleure carte
            case 'gainFromPath':    // gagne la meilleure carte
            case 'playFromPath':    // joue la meilleure carte du Chemin (Tambours)
            case 'putOnDeck':       // place la meilleure carte sur le deck
                $iid = $this->bestOption($state, $step, $player);

                return $iid !== null ? ['iid' => $iid] : [];

            default: // draw, corruption, reveal, destroyTopDeck, discardTopDeck…
                return [];
        }
    }

    // ------------------------------------------------------------- Dépenses

    private function spend(array &$state): void
    {
        for ($i = 0; $i < self::MAX_BUYS; ++$i) {
            if ($state['status'] === 'finished') {
                return;
            }
            $player = $this->activePlayer($state);
            $power = (int) $player['power'];

            // 1) Vaincre l'Archennemi si on peut (PV + départage).
            if ($this->canDefeatArchenemy($state, $player, $power)) {
                $this->engine->defeatArchenemy($state);
                $this->resolveEffects($state);
                continue;
            }

            // 2) Meilleure carte abordable du Chemin.
            $target = $this->bestAffordablePathCard($state, $power);
            if ($target !== null) {
                $this->engine->buyFromPath($state, $target);
                $this->resolveEffects($state);
                continue;
            }

            // 3) À défaut, une Valeur si le Pouvoir suffit et n'est pas gaspillé.
            $valorCost = (int) $this->catalog->card('valeur')['cost'];
            if (($state['stacks']['valor'] ?? 0) > 0 && $power >= $valorCost) {
                $this->engine->buyValor($state);
                continue;
            }

            break; // plus rien d'abordable
        }
    }

    private function canDefeatArchenemy(array $state, array $player, int $power): bool
    {
        $stack = $state['stacks']['archenemy'] ?? [];
        if (empty($stack) || empty($stack[0]['faceUp'])) {
            return false;
        }
        $cost = (int) $this->catalog->card($stack[0]['code'])['cost'];
        $cost = max(0, $cost - (int) ($player['archenemyDiscount'] ?? 0));

        return $power >= $cost;
    }

    /** iid de la meilleure carte du Chemin abordable (PV desc, coût desc). */
    private function bestAffordablePathCard(array $state, int $power): ?int
    {
        $best = null;
        $bestKey = null;
        foreach ($state['path'] as $iid) {
            $def = $this->engine->def($state, $iid);
            if ($def['cost'] === null || (int) $def['cost'] > $power) {
                continue; // ✱ (non achetable) ou trop cher
            }
            $key = [(int) ($def['pv'] ?? 0), (int) $def['cost']];
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $iid;
            }
        }

        return $best;
    }

    // ------------------------------------------------------------- Choix de cartes

    /** Y a-t-il une carte « déchet » (Corruption/Désespoir) à détruire ? */
    private function hasJunkToDestroy(array $state, array $step, array $player): bool
    {
        foreach ($this->engine->effectOptions($state, $player, $step) as $o) {
            if ($this->weakness($o['card']) < 0) {
                return true;
            }
        }

        return false;
    }

    /** iid de la carte la plus « faible » parmi les options (à défausser/détruire). */
    private function worstOption(array $state, array $step, array $player): ?int
    {
        $options = $this->engine->effectOptions($state, $player, $step);
        $worst = null;
        $worstRank = null;
        foreach ($options as $o) {
            $rank = $this->weakness($o['card']);
            if ($worstRank === null || $rank < $worstRank) {
                $worstRank = $rank;
                $worst = $o['iid'];
            }
        }

        return $worst;
    }

    /** iid de la meilleure carte parmi les options (à gagner/reprendre). */
    private function bestOption(array $state, array $step, array $player): ?int
    {
        $options = $this->engine->effectOptions($state, $player, $step);
        $best = null;
        $bestRank = null;
        foreach ($options as $o) {
            $rank = -$this->weakness($o['card']); // fort = utile
            if ($bestRank === null || $rank > $bestRank) {
                $bestRank = $rank;
                $best = $o['iid'];
            }
        }

        return $best;
    }

    /**
     * Score de « faiblesse » d'une carte : plus c'est bas, plus on veut s'en
     * débarrasser (Corruption < Désespoir < faible PV/coût).
     */
    private function weakness(array $def): float
    {
        if (($def['category'] ?? null) === 'corruption') {
            return -100.0;
        }
        if (($def['code'] ?? null) === 'desespoir') {
            return -50.0;
        }
        $cost = $def['cost'] === null ? 0 : (int) $def['cost'];

        return (float) ((int) ($def['pv'] ?? 0)) + $cost / 100.0;
    }

    private function mostCommonPathType(array $state): string
    {
        $counts = [];
        foreach ($state['path'] as $iid) {
            $t = $this->engine->def($state, $iid)['type'] ?? null;
            if ($t !== null) {
                $counts[$t] = ($counts[$t] ?? 0) + 1;
            }
        }
        if (empty($counts)) {
            return 'Allié';
        }
        arsort($counts);

        return (string) array_key_first($counts);
    }

    // ------------------------------------------------------------- Utilitaires

    private function activePlayer(array $state): array
    {
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $state['activeSeat']) {
                return $p;
            }
        }
        throw new \RuntimeException('Joueur actif introuvable.');
    }
}
