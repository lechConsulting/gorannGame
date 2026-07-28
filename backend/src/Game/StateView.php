<?php

namespace App\Game;

/**
 * Construit une "vue" JSON de l'état destinée au front : les identifiants de
 * carte (iid) sont hydratés avec leur définition complète pour les zones
 * visibles (Chemin, main/plateau des joueurs), et l'on n'expose que des
 * compteurs pour les zones cachées (deck, pioche principale).
 *
 * Masquage multijoueur : `$viewerSeat` est le siège du joueur qui regarde. La
 * MAIN des adversaires est cachée (seul le compteur est exposé) ; le reste
 * (Lieux en jeu, cartes jouées, défausse) est public dans ce jeu. Les options
 * d'effets ne sont renvoyées qu'au joueur actif (elles listent sa main). Avec
 * `$viewerSeat = null` (démo/headless), rien n'est masqué.
 */
class StateView
{
    public function __construct(private readonly CardCatalog $catalog, private readonly GameEngine $engine)
    {
    }

    public function build(array $state, ?int $viewerSeat = null): array
    {
        $players = [];
        foreach ($state['players'] as $p) {
            $isMine = $viewerSeat === null || $p['seat'] === $viewerSeat;
            $players[] = [
                'seat' => $p['seat'],
                'pseudo' => $p['pseudo'],
                'kind' => $p['kind'] ?? 'human',
                'level' => ($p['kind'] ?? 'human') === 'bot' ? ($p['level'] ?? 'facile') : null,
                'isMe' => $viewerSeat !== null && $p['seat'] === $viewerSeat,
                'hero' => $p['hero'],
                'power' => $p['power'],
                'deckCount' => \count($p['deck']),
                'discardCount' => \count($p['discard']),
                'handCount' => \count($p['hand']),
                // Main visible uniquement pour le viewer (secret des adversaires).
                'hand' => $isMine ? $this->cards($state, $p['hand']) : [],
                'inPlay' => $this->cards($state, $p['inPlay']),
                'played' => $this->cards($state, $p['playedThisTurn']),
                'discard' => $this->cards($state, $p['discard']),
                'boughtThisTurn' => $p['boughtThisTurn'],
                'archenemyDiscount' => $p['archenemyDiscount'] ?? 0,
            ];
        }

        $archenemy = null;
        if (!empty($state['stacks']['archenemy'])) {
            $top = $state['stacks']['archenemy'][0];
            $def = $this->catalog->card($top['code']);
            $archenemy = [
                'faceUp' => $top['faceUp'],
                'remaining' => \count($state['stacks']['archenemy']),
                'card' => $top['faceUp'] ? $this->cardDef($def) : null,
            ];
        }

        return [
            'status' => $state['status'],
            'phase' => $state['phase'],
            'turn' => $state['turn'],
            'activeSeat' => $state['activeSeat'],
            'players' => $players,
            'path' => $this->pathCards($state),
            'mainDeckCount' => \count($state['mainDeck']),
            'stacks' => [
                'valor' => $state['stacks']['valor'],
                'valorCard' => $this->cardDef($this->catalog->card('valeur')),
                'corruption' => $state['stacks']['corruption'],
                'corruptionCard' => ['cost' => null] + $this->cardDef($this->catalog->card('corruption')),
                'archenemy' => $archenemy,
            ],
            // Effets destinés au JOUEUR QUI REGARDE (son siège) — y compris les
            // effets inter-joueurs reçus hors de son tour (ex. « Mon Capitaine »).
            'effects' => $this->buildEffects($state, $viewerSeat),
            'endReason' => $state['endReason'] ?? null,
            'scores' => $state['scores'] ?? null,
            'winnerSeat' => $state['winnerSeat'] ?? null,
            // Récapitulatif du dernier tour terminé (montré à tous les joueurs) ;
            // cartes achetées et événements hydratés → cliquables côté front.
            'recap' => $this->buildRecap($state),
            // Révélation publique en cours (cartes détruites…), vue par tous.
            'reveal' => $this->buildReveal($state),
            // Embuscade de Groupe en cours (alerte + bloc par joueur).
            'groupAmbush' => $this->buildGroupAmbush($state, $viewerSeat),
            // Notifications d'effets « silencieux » destinées au spectateur.
            'notices' => array_values(array_map(
                fn ($n) => $n['text'],
                array_filter($state['notices'] ?? [], fn ($n) => $viewerSeat === null || ($n['seat'] ?? null) === $viewerSeat),
            )),
            // Cadence des bots : le client sait qui joue et dans combien de temps.
            'activeIsBot' => $this->activeIsBot($state),
            'botMsRemaining' => $this->botMsRemaining($state),
            // Le viewer peut-il remettre sa dernière carte jouée en main ?
            'canUndo' => $this->canUndo($state, $viewerSeat),
            'log' => array_map(function ($l) {
                if (\is_array($l)) { // entrée cliquable {t, code} → hydrate la carte
                    $card = (!empty($l['code']) && $this->catalog->has($l['code'])) ? $this->cardDef($this->catalog->card($l['code'])) : null;

                    return ['t' => $l['t'] ?? '', 'card' => $card];
                }

                return $l; // ligne texte simple
            }, \array_slice($state['log'], -40)),
        ];
    }

    /**
     * Vrai si le spectateur peut annuler son dernier coup : un point d'annulation
     * existe, lui appartient, c'est son tour et le tour n'a pas changé.
     */
    private function canUndo(array $state, ?int $viewerSeat): bool
    {
        $undo = $state['undo'] ?? null;

        return $undo !== null
            && $viewerSeat !== null
            && ($undo['seat'] ?? -1) === $viewerSeat
            && ($state['activeSeat'] ?? -1) === $viewerSeat
            && ($undo['turn'] ?? -1) === ($state['turn'] ?? -2);
    }

    /** Hydrate le récap : codes de cartes achetées → définitions, événements → carte liée. */
    private function buildRecap(array $state): ?array
    {
        $r = $state['lastRecap'] ?? null;
        if ($r === null) {
            return null;
        }

        $bought = [];
        foreach ($r['bought'] ?? [] as $b) {
            if (\is_string($b) && $this->catalog->has($b)) {
                $bought[] = $this->cardDef($this->catalog->card($b));
            } elseif (\is_string($b)) {
                $bought[] = ['code' => null, 'name' => $b]; // secours (ancien format)
            }
        }

        $events = [];
        foreach ($r['events'] ?? [] as $e) {
            if (\is_string($e)) { // ancien format
                $events[] = ['label' => $e, 'card' => null];
                continue;
            }
            $card = (!empty($e['code']) && $this->catalog->has($e['code']))
                ? $this->cardDef($this->catalog->card($e['code'])) : null;
            $events[] = ['label' => $e['label'] ?? '', 'card' => $card];
        }

        return [
            'seat' => $r['seat'],
            'pseudo' => $r['pseudo'],
            'kind' => $r['kind'] ?? 'human',
            'turn' => $r['turn'],
            'power' => $r['power'],
            'played' => $r['played'],
            'bought' => $bought,
            'events' => $events,
        ];
    }

    /** Vue de l'Embuscade de Groupe en cours : texte + bloc par joueur + action du viewer. */
    private function buildGroupAmbush(array $state, ?int $viewerSeat): ?array
    {
        $ga = $state['groupAmbush'] ?? null;
        if ($ga === null) {
            return null;
        }
        $done = !empty($ga['done']);
        $mode = $ga['mode'] ?? 'reveal'; // 'reveal' (main) | 'guessCost' (Ulaire Nelya)
        $players = [];
        foreach ($state['players'] as $p) {
            $seat = $p['seat'];
            $isMe = $viewerSeat !== null && $seat === $viewerSeat;
            $revealedIid = $ga['reveals'][$seat] ?? null;
            // En mode 'reveal' : révélation SIMULTANÉE (carte des autres cachée
            // jusqu'à la résolution). En mode 'guessCost' : chaque révélation du
            // deck principal est publique → on la montre dès qu'elle a lieu.
            $showCard = $revealedIid !== null && ($isMe || $done || $mode === 'guessCost');
            $players[] = [
                'seat' => $seat,
                'pseudo' => $p['pseudo'],
                'kind' => $p['kind'] ?? 'human',
                'isMe' => $isMe,
                'revealed' => \array_key_exists($seat, $ga['reveals']),
                'hasRevealed' => $revealedIid !== null,
                'card' => $showCard ? $this->cardDef($this->engine->def($state, $revealedIid)) : null,
                // Les issues du guessCost (gagné/raté) sont publiques immédiatement.
                'outcome' => ($done || $mode === 'guessCost') ? ($ga['outcomes'][$seat] ?? []) : [],
            ];
        }
        // Le viewer a-t-il une action en attente sur son siège ?
        $myEid = null;
        $myOp = null;
        $myOptions = [];
        $myCount = 0;
        if ($viewerSeat !== null) {
            foreach ($state['effects'] as $e) {
                $op = $e['op'] ?? '';
                if (\in_array($op, ['groupReveal', 'guessCost', 'nameCost', 'distributeCorruption'], true)
                    && ($e['seat'] ?? $state['activeSeat']) === $viewerSeat) {
                    $myEid = $e['eid'];
                    $myOp = $op;
                    if ($op === 'groupReveal') {
                        foreach ($state['players'] as $vp) {
                            if ($vp['seat'] === $viewerSeat) {
                                foreach ($vp['hand'] as $iid) {
                                    $myOptions[] = ['iid' => $iid] + $this->cardDef($this->engine->def($state, $iid));
                                }
                                break;
                            }
                        }
                    }
                    if ($op === 'distributeCorruption') {
                        $myCount = (int) ($e['count'] ?? 0);
                    }
                    break;
                }
            }
        }

        return ['name' => $ga['name'], 'text' => $ga['text'] ?? '', 'done' => $done, 'mode' => $mode,
            'auto' => !empty($ga['auto']), 'players' => $players, 'myEid' => $myEid, 'myOp' => $myOp,
            'myOptions' => $myOptions, 'myCount' => $myCount];
    }

    /** Hydrate la révélation publique en cours (codes → cartes). */
    private function buildReveal(array $state): ?array
    {
        $r = $state['lastReveal'] ?? null;
        if ($r === null) {
            return null;
        }
        $cards = [];
        foreach ($r['codes'] ?? [] as $c) {
            if ($this->catalog->has($c)) {
                $cards[] = $this->cardDef($this->catalog->card($c));
            }
        }

        return ['pseudo' => $r['pseudo'], 'label' => $r['label'], 'turn' => $r['turn'], 'cards' => $cards];
    }

    private function activeIsBot(array $state): bool
    {
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $state['activeSeat']) {
                return ($p['kind'] ?? 'human') === 'bot';
            }
        }

        return false;
    }

    private function botMsRemaining(array $state): ?int
    {
        if (($state['status'] ?? null) === 'finished' || !$this->activeIsBot($state)) {
            return null;
        }
        $due = ($state['botClockAt'] ?? 0) + GameOrchestrator::delayMs($state);

        return max(0, (int) ($due - (int) (microtime(true) * 1000)));
    }

    /**
     * Effets destinés au siège spectateur (avec applicabilité + candidats). Le
     * viewer ne voit et ne peut appliquer que SES effets — y compris ceux reçus
     * hors de son tour (inter-joueurs). `$viewerSeat = null` → siège actif.
     */
    private function buildEffects(array $state, ?int $viewerSeat): array
    {
        $targetSeat = $viewerSeat ?? $state['activeSeat'];
        $player = null;
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $targetSeat) {
                $player = $p;
                break;
            }
        }
        if ($player === null) {
            return [];
        }

        $out = [];
        foreach ($state['effects'] ?? [] as $e) {
            if (($e['seat'] ?? $state['activeSeat']) !== $targetSeat) {
                continue; // effet d'un autre joueur : non exposé à ce spectateur
            }
            if (($e['op'] ?? '') === 'groupReveal') {
                continue; // géré par l'alerte dédiée « Embuscade de Groupe »
            }
            $kind = $e['kind'] ?? 'pos';
            $entry = [
                'eid' => $e['eid'],
                'op' => $e['op'],
                'kind' => $kind,
                'optional' => $kind === 'pos',
                'permanent' => !empty($e['permanent']), // activable à tout moment (pas d'ouverture auto)
                'label' => $e['label'] ?? $e['op'],
                'sourceName' => $e['sourceName'] ?? '',
                'applicable' => $this->engine->stepApplicable($state, $player, $e),
            ];
            if (\in_array($e['op'], ['destroy', 'takeFromDiscard', 'gainFromPath', 'playFromPath', 'groupReveal', 'discard', 'putOnDeck'], true)) {
                $entry['options'] = array_map(
                    fn ($o) => ['iid' => $o['iid'], 'zone' => $o['zone']] + $this->cardDef($o['card']),
                    $this->engine->effectOptions($state, $player, $e),
                );
            }
            if ($e['op'] === 'nameType' || $e['op'] === 'nameTypeMain') {
                $entry['typeOptions'] = ['Ennemi', 'Allié', 'Lieu', 'Artefact', 'Manœuvre', 'Chance'];
            }
            if ($e['op'] === 'choosePlayer' || $e['op'] === 'choosePlayerDraw' || $e['op'] === 'ulaireGive') {
                $entry['playerOptions'] = [];
                foreach ($state['players'] as $p) {
                    if ($p['seat'] !== $targetSeat) {
                        $entry['playerOptions'][] = ['seat' => $p['seat'], 'pseudo' => $p['pseudo'], 'kind' => $p['kind'] ?? 'human'];
                    }
                }
            }
            if ($e['op'] === 'chooseOthersDraw') {
                $entry['playerOptions'] = []; // tous les joueurs (le choisi est EXCLU de la pioche)
                foreach ($state['players'] as $p) {
                    $entry['playerOptions'][] = ['seat' => $p['seat'], 'pseudo' => $p['pseudo'],
                        'kind' => $p['kind'] ?? 'human', 'isMe' => $p['seat'] === $targetSeat];
                }
            }
            if ($e['op'] === 'destroyDespair') {
                $entry['playerOptions'] = [];
                foreach ($state['players'] as $p) {
                    $has = false;
                    foreach ($p['discard'] as $iid) {
                        if ($this->engine->def($state, $iid)['code'] === 'desespoir') {
                            $has = true;
                            break;
                        }
                    }
                    if ($has) {
                        $entry['playerOptions'][] = ['seat' => $p['seat'], 'pseudo' => $p['pseudo'], 'kind' => $p['kind'] ?? 'human'];
                    }
                }
            }
            // Cartes Défense en main permettant d'éviter cet effet (Embuscade/Attaque).
            if (!empty($e['defendable'])) {
                $entry['defenseOptions'] = [];
                foreach ($player['hand'] as $iid) {
                    $d = $this->engine->def($state, $iid);
                    if (!empty($d['attributes']['defense'])) {
                        // « C'était Proche ! » dévie l'effet → il faut choisir une cible.
                        $entry['defenseOptions'][] = ['iid' => $iid, 'needsTarget' => $d['code'] === 'cetait-proche'] + $this->cardDef($d);
                    }
                }
            }
            if (\in_array($e['op'], ['reveal', 'destroyTopDeck', 'discardTopDeck', 'putGainedOnDeck'], true) && !empty($e['card'])) {
                $entry['card'] = $this->cardDef($e['card']);
            }
            if ($e['op'] === 'revealCards' && !empty($e['cards'])) {
                $entry['cards'] = $e['cards']; // [{...def, gained}]
            }
            // Embuscade/Attaque auto : joindre la clause de règles de la carte
            // source (ce que l'effet fait), sinon le joueur « subit » à l'aveugle.
            if (\in_array($e['op'], ['ambushAuto', 'attack'], true)) {
                $code = $e['source'] ?? $e['code'] ?? null;
                if ($code !== null && $this->catalog->has($code)) {
                    $desc = $this->clauseText($this->catalog->card($code)['text'] ?? '', $e['op'] === 'attack' ? 'Attaque' : 'Embuscade');
                    if ($desc !== '') {
                        $entry['desc'] = $desc;
                    }
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Extrait la clause « Attaque : … » ou « Embuscade : … » du texte d'une carte
     * (ce que fait l'effet), pour l'afficher au joueur qui va le subir.
     */
    private function clauseText(string $text, string $keyword): string
    {
        $pos = mb_stripos($text, $keyword);
        if ($pos === false) {
            return '';
        }
        $after = mb_substr($text, $pos + mb_strlen($keyword));
        $colon = mb_strpos($after, ':');
        if ($colon !== false) {
            $after = mb_substr($after, $colon + 1);
        }
        // S'arrête à la clause suivante éventuelle (une carte peut cumuler Attaque + Embuscade).
        foreach (['Attaque', 'Embuscade'] as $stop) {
            $sp = mb_stripos($after, $stop);
            if ($sp !== false && $sp > 0) {
                $after = mb_substr($after, 0, $sp);
            }
        }

        return trim($after);
    }

    /**
     * Cartes du Chemin, avec le COÛT EFFECTIF (`buyCost`) pour le joueur actif —
     * tient compte des réductions de Lieux (ex. Hobbit Bourg : −1 sur Artefact /
     * Manœuvre). C'est ce coût que le front doit afficher et utiliser.
     */
    private function pathCards(array $state): array
    {
        $active = null;
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $state['activeSeat']) {
                $active = $p;
                break;
            }
        }

        $out = [];
        foreach ($state['path'] as $iid) {
            $def = $this->engine->def($state, $iid);
            $entry = ['iid' => $iid] + $this->cardDef($def);
            $entry['buyCost'] = ($def['cost'] === null || $active === null)
                ? $def['cost']
                : $this->engine->effectiveCost($state, $active, $def);
            $out[] = $entry;
        }

        return $out;
    }

    /** @param int[] $iids */
    private function cards(array $state, array $iids): array
    {
        $out = [];
        foreach ($iids as $iid) {
            $def = $this->engine->def($state, $iid);
            $out[] = ['iid' => $iid] + $this->cardDef($def);
        }

        return $out;
    }

    private function cardDef(array $def): array
    {
        return [
            'code' => $def['code'],
            'name' => $def['name'],
            'type' => $def['type'],
            'category' => $def['category'],
            'cost' => $def['cost'],
            'pv' => $def['pv'],
            'level' => $def['level'],
            'text' => $def['text'],
            'attributes' => $def['attributes'],
        ];
    }
}
