<?php

namespace App\Game;

/**
 * Traduit une carte jouée en effets concrets :
 *  - le Pouvoir (et ses bonus conditionnels) est appliqué immédiatement ;
 *  - les autres effets (pioche, Corruption, destruction, choix, gain depuis le
 *    sentier, reprise de défausse…) sont ajoutés à la LISTE d'effets du joueur
 *    (state.effects) qu'il applique lui-même via l'IHM.
 *
 * Types d'effet : positifs = optionnels (pos) ; négatifs = obligatoires (neg).
 * Les effets ciblant "les autres joueurs" (Attaque) sont ignorés en solo.
 */
class EffectRegistry
{
    public function onPlay(array &$state, array &$player, int $iid, array $def, GameEngine $engine): void
    {
        $code = $def['code'];

        // 1) Pouvoir (immédiat).
        $this->applyPower($state, $player, $def, $engine);

        // 2) Effets à appliquer / choix (mis en liste) + effets automatiques.
        switch ($code) {
            // --- Pioche simple (optionnel) ---
            case 'pippin':
            case 'vous-ne-passerez-pas':
                $this->queueDraw($engine, $state, $def, 1);
                break;
            case 'conseil-elrond':
            case 'decouverte-de-anneau':
                $this->queueDraw($engine, $state, $def, 2);
                break;
            case 'ils-ne-servent-que-des-pintes': // +2 Pouvoir ; choisis un joueur, tous les AUTRES piochent 1
                $engine->queueEffect($state, ['op' => 'chooseOthersDraw', 'kind' => 'neg',
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Choisis un joueur : tous les AUTRES piochent une carte']);
                break;
            case 'ceci-est-pour-toi': // pioche 2 ; choisis un autre joueur, il pioche 1
                $this->queueDraw($engine, $state, $def, 2);
                $engine->queueEffect($state, ['op' => 'choosePlayerDraw', 'kind' => 'neg',
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Choisis un autre joueur : il pioche une carte']);
                break;
            case 'pierres-de-vision': // pioche 2 puis remet une carte de la main sur le deck
                $this->queueDraw($engine, $state, $def, 2);
                $engine->queueEffect($state, ['op' => 'putOnDeck', 'kind' => 'pos',
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Mets une carte de ta main sur ton deck']);
                break;

            // --- Pouvoir + pioche ---
            case 'chef-orc':
            case 'gandalf-le-gris':
            case 'herbe-a-pipe':
            case 'guetteur-de-leau':
                $this->queueDraw($engine, $state, $def, 1);
                break;
            case 'arc-des-galadhrim':
                if (\count($player['discard']) >= 10) {
                    $this->queueDraw($engine, $state, $def, 1);
                }
                break;
            case 'on-entre-pas-en-mordor':
                $loc = \count($player['inPlay']);
                if ($loc > 0) {
                    $this->queueDraw($engine, $state, $def, $loc);
                }
                break;
            case 'hache-de-gimli': // défausser une carte → −2 pour vaincre l'Archennemi (optionnel)
                $engine->queueEffect($state, ['op' => 'discard', 'kind' => 'pos', 'count' => 1, 'thenDiscount' => 2,
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Défausse une carte → −2 pour vaincre l\'Archennemi']);
                break;
            case 'bilbon-saquet': // défausser une carte de coût 0 → piocher 1 (optionnel)
                $engine->queueEffect($state, ['op' => 'discard', 'kind' => 'pos', 'count' => 1,
                    'filter' => ['costEq' => 0], 'thenDraw' => 1,
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Défausse une carte de coût 0 → pioche 1']);
                break;

            // --- Destruction au choix (optionnel) ---
            case 'toujours-tranchante':
                $this->queueDraw($engine, $state, $def, 1);
                $this->queueDestroy($engine, $state, $def);
                break;
            case 'fuyez-pauvres-fous':
            case 'eclats-de-narsil':
                $this->queueDestroy($engine, $state, $def);
                break;
            case 'mon-capitaine-mon-roi': // choisis un autre joueur ; vous deux pouvez détruire une carte
                $engine->queueEffect($state, ['op' => 'choosePlayer', 'kind' => 'pos',
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Choisis un autre joueur (vous pourrez tous deux détruire une carte)']);
                break;
            case 'isengard': // Lieu activable : dès ce tour, tu peux détruire une carte de ta main
                $engine->queueActivatedPermanents($state, $player);
                break;
            case 'saroumane': // Archennemi capturé, joué depuis ta main : nomme un type, révèle 7 du deck principal
                $engine->queueEffect($state, ['op' => 'nameTypeMain', 'kind' => 'pos', 'count' => 7,
                    'source' => 'saroumane', 'sourceName' => 'Saroumane',
                    'label' => 'Saroumane : nomme un type, révèle 7 cartes du deck principal (prends celles du type)']);
                break;
            case 'tambours-profondeurs': // joue une carte du sentier comme si de ta main ; détruite en fin de tour
                if (!empty($state['path'])) {
                    $engine->queueEffect($state, ['op' => 'playFromPath', 'kind' => 'pos',
                        'source' => $code, 'sourceName' => $def['name'],
                        'label' => 'Tambours : joue une carte du Chemin (détruite en fin de tour)']);
                }
                break;
            case 'miroir-de-galadriel': // regarde le dessus de ton deck, tu peux le détruire
                $this->queueTopDeckDecision($engine, $state, $def, 'destroyTopDeck', 'Détruire la carte du dessus de ton deck ?');
                break;
            case 'merry': // regarde le dessus de ton deck, tu peux le défausser
            case 'en-ce-moment-decisif': // +2 Pouvoir, puis tu peux défausser le dessus de ton deck
                $this->queueTopDeckDecision($engine, $state, $def, 'discardTopDeck', 'Défausser la carte du dessus de ton deck ?');
                break;
            case 'seduit-par-anneau': // tu peux prendre une Corruption pour piocher 2 (optionnel)
                $engine->queueEffect($state, ['op' => 'corruption', 'kind' => 'pos', 'n' => 1, 'thenDraw' => 2,
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Prends une Corruption → pioche 2 cartes']);
                break;
            // --- Attaques inter-joueurs (Ennemis joués) ---
            case 'grognement-uruk-hai': // chaque adversaire révèle son dessus de deck, défausse si coût ≥1
            case 'cavaliers-noirs':     // chaque adversaire défausse une carte au hasard
            case 'spectres-de-anneau':  // chaque adversaire prend une Corruption
                $this->queueAttack($engine, $state, $player, $def, 'attack');
                break;
            case 'uruk-hai': // chaque adversaire CHOISIT et défausse une carte de sa main
                $this->queueAttack($engine, $state, $player, $def, 'discard');
                break;
            case 'ulaire-ostea': // Attaque : choisis un joueur, mets une carte de TA défausse dans SA défausse
                if (!empty($player['discard'])) {
                    $engine->queueEffect($state, ['op' => 'ulaireGive', 'kind' => 'pos',
                        'source' => $code, 'sourceName' => $def['name'],
                        'label' => 'Attaque (Ulaire Ostea) : donne une carte de ta défausse à un adversaire']);
                }
                break;

            case 'roi-sorcier': // détruit les 2 du dessus du deck principal, +X = coût total
                $sum = 0;
                $codes = [];
                for ($i = 0; $i < 2 && !empty($state['mainDeck']); ++$i) {
                    $riid = array_shift($state['mainDeck']);
                    $rdef = $engine->def($state, $riid);
                    $sum += (int) ($rdef['cost'] ?? 0);
                    $codes[] = $rdef['code'];
                    $state['removed'][] = $riid;
                }
                $player['power'] += $sum;
                if ($codes) {
                    // Révélation PUBLIQUE : tous les joueurs voient les cartes détruites.
                    $engine->publicReveal($state, $player['pseudo'],
                        sprintf('%s détruit 2 cartes du deck principal (Le Roi Sorcier, +%d Pouvoir)', $player['pseudo'], $sum),
                        $codes);
                }
                break;
            case 'livre-de-mazarbul': // pioche 1 ; révèle le dessus du deck principal, si coût ≥5 sur ton deck
                $this->queueDraw($engine, $state, $def, 1);
                if (!empty($state['mainDeck'])) {
                    $topDef = $engine->def($state, $state['mainDeck'][0]);
                    $tcost = (int) ($topDef['cost'] ?? 0);
                    $taken = false;
                    if ($tcost >= 5) {
                        array_unshift($player['deck'], array_shift($state['mainDeck']));
                        $taken = true;
                    }
                    // Révélation visible : le joueur voit la carte du deck principal.
                    $engine->queueEffect($state, ['op' => 'reveal', 'kind' => 'pos', 'source' => $code, 'sourceName' => $def['name'],
                        'card' => $topDef,
                        'label' => $taken
                            ? sprintf('Coût %d ≥ 5 → prise sur le dessus de ton deck', $tcost)
                            : sprintf('Coût %d < 5 → laissée sur le deck principal', $tcost)]);
                }
                break;
            case 'jamais-plus-de-desespoir': // détruire un Désespoir dans la défausse d'un joueur au choix → pioche
                $engine->queueEffect($state, ['op' => 'destroyDespair', 'kind' => 'pos',
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Détruis un Désespoir dans la défausse d\'un joueur (puis pioche 1)']);
                break;
            case 'samsagace-gamegie': // détruire une Corruption
                $this->queueDestroy($engine, $state, $def, ['type' => 'Corruption'], 'Détruis une Corruption (main/défausse)');
                break;
            case 'frodon-porteur-anneau': // destruction si un AUTRE Allié est joué ce tour (avant OU après)
                $player['frodonPlayed'] = true;
                break;
            case 'jetez-le-dans-le-feu': // jusqu'à 2 destructions
                $this->queueDestroy($engine, $state, $def);
                $this->queueDestroy($engine, $state, $def);
                break;

            // --- Reprise depuis la défausse (optionnel) ---
            case 'evasion-ailee':
                $this->queueTakeFromDiscard($engine, $state, $def);
                break;
            case 'ombres-spectres-anneau': // 2 cartes de coût ≤ 3
                $this->queueTakeFromDiscard($engine, $state, $def, ['costMax' => 3]);
                $this->queueTakeFromDiscard($engine, $state, $def, ['costMax' => 3]);
                break;

            // --- Nommer un type puis révéler ---
            case 'lumiere-earendil':
            case 'pendentif-etoile-du-soir':
            case 'torche-enflammee':
            case 'je-ne-crains-ni-douleur-ni-mort':
                $engine->queueEffect($state, ['op' => 'nameType', 'kind' => 'pos',
                    'source' => $code, 'sourceName' => $def['name'],
                    'label' => 'Nomme un type puis révèle le dessus de ton deck']);
                break;

            // --- Gagner une carte du sentier ---
            case 'feux-artifice-gandalf':
                $this->queuePath($engine, $state, $def, ['dest' => 'deckTop', 'costMax' => 5], 'Gagne une carte du sentier (coût ≤5) → sur ton deck');
                break;
            case 'baton-de-gandalf':
                $this->queuePath($engine, $state, $def, ['action' => 'destroy', 'onlyFirst' => true, 'replace' => true], 'Détruis la 1re carte du sentier (remplacée)');
                break;
            case 'fourmiliere-de-la-moria':
                $this->queuePath($engine, $state, $def, ['dest' => 'hand'], 'Gagne une carte du sentier → en main');
                break;
            case 'nazgul':
                $this->queuePath($engine, $state, $def, ['dest' => 'discard', 'costMin' => 5], 'Gagne une carte du sentier (coût ≥5)');
                break;

            // --- Effets automatiques ---
            case 'recuperez-forces': // si 1re carte : défausse main + pioche 4
                if ($this->isFirstCardOfTurn($player)) {
                    foreach ($player['hand'] as $h) {
                        $player['discard'][] = $h;
                    }
                    $player['hand'] = [];
                    $this->queueDraw($engine, $state, $def, 4);
                }
                break;
            case 'jai-fait-ce-que-jai-juge-bien': // si 1re carte : défausse main + pioche 5
                if ($this->isFirstCardOfTurn($player)) {
                    foreach ($player['hand'] as $h) {
                        $player['discard'][] = $h;
                    }
                    $player['hand'] = [];
                    $this->queueDraw($engine, $state, $def, 5);
                }
                break;
            case 'seigneur-elrond': // révèle 4, gagne les Alliés en main, le reste dessous
                $this->elrondReveal($state, $player, $engine);
                break;
            case 'galadriel-dame-de-lumiere': // révèle le dessus ; si Allié → main, recommence
                $this->galadrielReveal($state, $player, $engine);
                break;
            case 'ulaire-nelya': // gagne la carte la plus chère du sentier
                $this->gainMostExpensivePath($state, $player, $engine);
                break;
            case 'un-cadeau': // révèle le dessus du deck principal → en main
                if (!empty($state['mainDeck'])) {
                    $player['hand'][] = array_shift($state['mainDeck']);
                }
                break;
            case 'veste-de-mithril': // prend le dessus du deck principal ; tu peux le mettre sur ton deck
                if (!empty($state['mainDeck'])) {
                    $giid = array_shift($state['mainDeck']);
                    $player['discard'][] = $giid; // par défaut : dans ta défausse
                    $engine->queueEffect($state, ['op' => 'putGainedOnDeck', 'kind' => 'pos', 'giid' => $giid,
                        'card' => $engine->def($state, $giid), 'source' => $code, 'sourceName' => $def['name'],
                        'label' => 'Veste de Mithril : mettre la carte prise sur le dessus de ton deck ?']);
                }
                break;
        }

        // Frodon Saquet : dès qu'un 2e Allié est joué ce tour (quel que soit l'ordre),
        // il peut détruire une carte de sa main/défausse (une seule fois par tour).
        if ($def['type'] === 'Allié'
            && !empty($player['frodonPlayed'])
            && empty($player['frodonGranted'])
            && $this->countPlayedType($state, $player, 'Allié', $engine) >= 2) {
            $player['frodonGranted'] = true;
            $engine->queueEffect($state, ['op' => 'destroy', 'kind' => 'pos',
                'source' => 'frodon-porteur-anneau', 'sourceName' => 'Frodon Saquet Porteur de l\'Anneau',
                'label' => 'Frodon Saquet : détruis une carte de ta main ou de ta défausse']);
        }
    }

    // ------------------------------------------------------------- Pouvoir

    private function applyPower(array &$state, array &$player, array $def, GameEngine $engine): void
    {
        $code = $def['code'];
        $base = (int) ($def['attributes']['power'] ?? 0);

        switch ($code) {
            case 'legolas-vertefeuille': // +2 par Allié joué (celui-ci inclus)
                $player['power'] += 2 * $this->countPlayedType($state, $player, 'Allié', $engine);

                return;
            case 'orcs-de-la-moria': // +4 si un autre Orcs de la Moria, sinon +1
                $player['power'] += $this->countPlayedCode($state, $player, 'orcs-de-la-moria', $engine) >= 2 ? 4 : 1;

                return;
            case 'cor-gondor': // +1 par type différent joué (hors Départ)
                $player['power'] += $this->countDistinctTypes($state, $player, $engine);

                return;
            case 'epee-daragorn': // +1 par coût différent joué
                $player['power'] += $this->countDistinctCosts($state, $player, $engine);

                return;
            case 'epee-de-boromir': // +niveau de l'Archennemi actuel
                $player['power'] += $engine->currentArchenemyLevel($state);

                return;
            case 'bravoure-de-sam': // +1, +1 par tranche de 5 en défausse
                $player['power'] += 1 + intdiv(\count($player['discard']), 5);

                return;
            case 'second-dejeuner': // +3 si déjà acheté ce tour, sinon +1
                $player['power'] += $player['boughtThisTurn'] > 0 ? 3 : 1;

                return;
            case 'eclats-de-narsil': // +1, +3 par autre Artefact joué
                $others = $this->countPlayedType($state, $player, 'Artefact', $engine) - 1;
                $player['power'] += 1 + 3 * max(0, $others);

                return;
            case 'gimli-fils-de-gloin': // Allié : +2 et −2 pour vaincre l'Archennemi (inconditionnel)
                $player['power'] += 2;
                $player['archenemyDiscount'] = ($player['archenemyDiscount'] ?? 0) + 2;

                return;
            case 'hache-de-gimli': // Héros : +2 ; la réduction nécessite de défausser (voir effet)
                $player['power'] += 2;

                return;
        }

        if ($base !== 0) {
            $player['power'] += $base;
        }
    }

    // ------------------------------------------------------------- Fabriques d'effets

    private function queueDraw(GameEngine $engine, array &$state, array $def, int $n): void
    {
        $engine->queueEffect($state, ['op' => 'draw', 'kind' => 'pos', 'n' => $n,
            'source' => $def['code'], 'sourceName' => $def['name'],
            'label' => "Piochez $n carte(s)"]);
    }

    private function queueDestroy(GameEngine $engine, array &$state, array $def, array $filter = [], ?string $label = null): void
    {
        $engine->queueEffect($state, ['op' => 'destroy', 'kind' => 'pos', 'filter' => $filter,
            'source' => $def['code'], 'sourceName' => $def['name'],
            'label' => $label ?? 'Détruis une carte (main/défausse)']);
    }

    private function queueTakeFromDiscard(GameEngine $engine, array &$state, array $def, array $filter = []): void
    {
        $engine->queueEffect($state, ['op' => 'takeFromDiscard', 'kind' => 'pos', 'filter' => $filter,
            'source' => $def['code'], 'sourceName' => $def['name'],
            'label' => 'Reprends une carte de ta défausse'.(isset($filter['costMax']) ? ' (coût ≤ '.$filter['costMax'].')' : '')]);
    }

    private function queueTopDeckDecision(GameEngine $engine, array &$state, array $def, string $op, string $label): void
    {
        $player = &$this->activePlayerRef($state);
        $top = $engine->peekTop($state, $player);
        if ($top === null) {
            return;
        }
        $engine->queueEffect($state, ['op' => $op, 'kind' => 'pos', 'topIid' => $top, 'card' => $engine->def($state, $top),
            'source' => $def['code'], 'sourceName' => $def['name'], 'label' => $label]);
    }

    /** Référence sur le joueur actif (peekTop a besoin d'un &$player). */
    private function &activePlayerRef(array &$state): array
    {
        foreach ($state['players'] as $i => $p) {
            if ($p['seat'] === $state['activeSeat']) {
                return $state['players'][$i];
            }
        }
        throw new \RuntimeException('Joueur actif introuvable.');
    }

    /**
     * Queue une ATTAQUE sur chaque adversaire du joueur actif. `$mode` = 'attack'
     * (effet auto par code) ou 'discard' (l'adversaire choisit une carte). Toutes
     * les attaques sont DÉFENDABLES et annoncées publiquement.
     */
    private function queueAttack(GameEngine $engine, array &$state, array $player, array $def, string $mode): void
    {
        $targets = [];
        foreach ($state['players'] as $p) {
            if ($p['seat'] !== $state['activeSeat']) {
                $targets[] = $p['seat'];
            }
        }
        if (empty($targets)) {
            return; // solo : personne à attaquer
        }

        $engine->publicReveal($state, $player['pseudo'],
            sprintf('🗡️ %s lance « %s » sur ses adversaires', $player['pseudo'], $def['name']), []);

        foreach ($targets as $seat) {
            if ($mode === 'discard') {
                $engine->queueEffect($state, ['op' => 'discard', 'kind' => 'neg', 'count' => 1, 'defendable' => true, 'seat' => $seat,
                    'source' => $def['code'], 'sourceName' => $def['name'], 'label' => sprintf('Attaque (%s) : défausse 1 carte au choix', $def['name'])]);
            } else {
                $engine->queueEffect($state, ['op' => 'attack', 'kind' => 'neg', 'code' => $def['code'], 'defendable' => true, 'seat' => $seat,
                    'source' => $def['code'], 'sourceName' => $def['name'], 'label' => sprintf('Attaque : %s', $def['name'])]);
            }
        }
    }

    private function queuePath(GameEngine $engine, array &$state, array $def, array $ctx, string $label): void
    {
        $engine->queueEffect($state, ['op' => 'gainFromPath', 'kind' => 'pos', 'context' => $ctx,
            'source' => $def['code'], 'sourceName' => $def['name'], 'label' => $label]);
    }

    // ------------------------------------------------------------- Effets automatiques

    private function elrondReveal(array &$state, array &$player, GameEngine $engine): void
    {
        $reveal = array_splice($state['mainDeck'], 0, 4);
        $revealedCodes = [];
        $gained = 0;
        $rest = [];
        foreach ($reveal as $iid) {
            $revealedCodes[] = $engine->def($state, $iid)['code'];
            if ($engine->def($state, $iid)['type'] === 'Allié') {
                $player['hand'][] = $iid;
                ++$gained;
            } else {
                $rest[] = $iid;
            }
        }
        foreach ($rest as $iid) {
            $state['mainDeck'][] = $iid; // le reste repart sous le paquet
        }
        if (!empty($reveal)) {
            // Modale claire : les 4 cartes révélées, en marquant les Alliés gagnés.
            $cards = [];
            foreach ($reveal as $iid) {
                $d = $engine->def($state, $iid);
                $cards[] = ['gained' => $d['type'] === 'Allié'] + $this->cardShort($d);
            }
            $engine->queueEffect($state, ['op' => 'revealCards', 'kind' => 'pos', 'source' => 'seigneur-elrond',
                'sourceName' => 'Seigneur Elrond', 'cards' => $cards,
                'label' => sprintf('Seigneur Elrond : 4 cartes révélées — %d Allié(s) gagné(s) en main', $gained)]);
            // Aussi visible par tous (bandeau public).
            $engine->publicReveal($state, $player['pseudo'],
                sprintf('%s (Seigneur Elrond) révèle 4 cartes — gagne %d Allié(s)', $player['pseudo'], $gained),
                $revealedCodes);
        }
    }

    /** Représentation minimale d'une carte pour l'affichage front. */
    private function cardShort(array $d): array
    {
        return [
            'code' => $d['code'], 'name' => $d['name'], 'type' => $d['type'], 'category' => $d['category'],
            'cost' => $d['cost'], 'pv' => $d['pv'], 'level' => $d['level'], 'text' => $d['text'], 'attributes' => $d['attributes'],
        ];
    }

    private function galadrielReveal(array &$state, array &$player, GameEngine $engine): void
    {
        $guard = 0;
        while ($guard++ < 20) {
            $top = $engine->peekTop($state, $player);
            if ($top === null || $engine->def($state, $top)['type'] !== 'Allié') {
                break;
            }
            array_shift($player['deck']);
            $player['hand'][] = $top;
        }
    }

    private function gainMostExpensivePath(array &$state, array &$player, GameEngine $engine): void
    {
        $best = null;
        $bestCost = -1;
        foreach ($state['path'] as $iid) {
            $c = $engine->def($state, $iid)['cost'];
            if ($c !== null && $c > $bestCost) {
                $bestCost = $c;
                $best = $iid;
            }
        }
        if ($best !== null) {
            $pos = array_search($best, $state['path'], true);
            array_splice($state['path'], $pos, 1);
            $player['discard'][] = $best;
        }
    }

    // ------------------------------------------------------------- Comptages

    private function countPlayedType(array $state, array $player, string $type, GameEngine $engine): int
    {
        $n = 0;
        foreach ($player['playedThisTurn'] as $iid) {
            if ($engine->def($state, $iid)['type'] === $type) {
                ++$n;
            }
        }

        return $n;
    }

    private function countPlayedCode(array $state, array $player, string $code, GameEngine $engine): int
    {
        $n = 0;
        foreach ($player['playedThisTurn'] as $iid) {
            if ($engine->def($state, $iid)['code'] === $code) {
                ++$n;
            }
        }

        return $n;
    }

    private function countDistinctTypes(array $state, array $player, GameEngine $engine): int
    {
        // Ne compter que les cartes jouées CE tour (y compris les Lieux posés ce
        // tour), pas les Lieux permanents des tours précédents.
        $types = [];
        foreach (array_merge($player['playedThisTurn'], $player['playedLieuxThisTurn'] ?? []) as $iid) {
            $t = $engine->def($state, $iid)['type'];
            if ($t !== 'Départ') {
                $types[$t] = true;
            }
        }

        return \count($types);
    }

    private function countDistinctCosts(array $state, array $player, GameEngine $engine): int
    {
        $costs = [];
        foreach ($player['playedThisTurn'] as $iid) {
            $c = $engine->def($state, $iid)['cost'];
            if ($c !== null) {
                $costs[$c] = true;
            }
        }

        return max(1, \count($costs));
    }

    private function isFirstCardOfTurn(array $player): bool
    {
        // Compteur global (inclut les Lieux) : jouer un Lieu AVANT compte comme
        // une carte jouée → cette carte ne serait alors plus « la première ».
        return (int) ($player['playedCountThisTurn'] ?? 0) === 1;
    }
}
