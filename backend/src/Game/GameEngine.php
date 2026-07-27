<?php

namespace App\Game;

/**
 * Moteur de partie (Phase 1 : boucle économique complète, solo/hotseat).
 * Opère sur le tableau d'état (state) et le mute en place. La persistance
 * (GameSession.state) est gérée par l'appelant.
 *
 * Modélisé : mise en place, jouer une carte (Pouvoir + effets courants via
 * EffectRegistry), acheter dans le Chemin / la Valeur, vaincre un Archennemi,
 * fin de tour (défausse, pioche 5, regarnissage du Chemin, retournement
 * Archennemi), fin de partie et calcul des scores (dont Quête et Corruption).
 *
 * Non encore modélisé (Phase 2, nécessite le multijoueur temps réel) :
 * Attaques/Défenses/Embuscades entre joueurs, Embuscades de Groupe des
 * Archennemis. En solo ces effets ciblant "les autres joueurs" sont ignorés.
 */
class GameEngine
{
    private const HAND_SIZE = 5;
    private const PATH_SIZE = 5;

    public function __construct(
        private readonly CardCatalog $catalog,
        private readonly EffectRegistry $effects,
    ) {
    }

    /** Joueur actif (référence). */
    private function &active(array &$state): array
    {
        foreach ($state['players'] as $i => $p) {
            if ($p['seat'] === $state['activeSeat']) {
                return $state['players'][$i];
            }
        }
        throw new \RuntimeException('Joueur actif introuvable.');
    }

    public function def(array $state, int $iid): array
    {
        return $this->catalog->card($state['instances'][$iid]);
    }

    // ---------------------------------------------------------------- Jouer

    /** Joue une carte de la main du joueur actif. */
    public function playCard(array &$state, int $iid): void
    {
        $this->assertActive($state);
        $player = &$this->active($state);

        $pos = array_search($iid, $player['hand'], true);
        if ($pos === false) {
            throw new \RuntimeException('Cette carte n\'est pas dans votre main.');
        }
        array_splice($player['hand'], $pos, 1);

        $def = $this->def($state, $iid);

        // Un Lieu reste en jeu devant soi ; les autres cartes vont dans la zone "jouées".
        if ($def['type'] === 'Lieu') {
            $player['inPlay'][] = $iid;
            $player['playedLieuxThisTurn'][] = $iid; // pour compter les Lieux joués CE tour
        } else {
            $player['playedThisTurn'][] = $iid;
        }

        $player['playedCountThisTurn'] = ($player['playedCountThisTurn'] ?? 0) + 1;
        $this->log($state, sprintf('%s joue %s.', $player['pseudo'], $def['name']));

        // Résolution de l'effet (Pouvoir + effets structurés).
        $this->effects->onPlay($state, $player, $iid, $def, $this);

        // Déclencheurs des Lieux permanents en jeu (réagissent à la carte jouée).
        $this->triggerPermanents($state, $player, $def);
    }

    /**
     * Effets PERMANENTS déclenchés par le jeu d'une carte (Lieux posés devant soi).
     * Chaque déclencheur ne se produit qu'une fois par tour (`permTriggered`).
     */
    private function triggerPermanents(array &$state, array &$player, array $playedDef): void
    {
        // Les Mines de la Moria : la 1re fois que tu joues un Ennemi ce tour → pioche 1.
        if ($playedDef['type'] === 'Ennemi'
            && $this->hasInPlay($state, $player, 'mines-moria')
            && !\in_array('mines-moria', $player['permTriggered'] ?? [], true)) {
            $before = $player['hand'];
            $this->draw($state, $player, 1);
            $player['permTriggered'][] = 'mines-moria';
            $drawn = array_values(array_diff($player['hand'], $before));
            $name = !empty($drawn) ? $this->def($state, $drawn[0])['name'] : null;
            $this->log($state, sprintf('%s : Les Mines de la Moria → pioche 1 (1er Ennemi du tour).', $player['pseudo']));
            $this->notify($state, $player['seat'], $name !== null
                ? sprintf('🪨 Les Mines de la Moria : tu pioches %s.', $name)
                : '🪨 Les Mines de la Moria : rien à piocher.');
        }
    }

    /** Message d'information destiné à un joueur (feedback d'un effet « silencieux »). */
    private function notify(array &$state, int $seat, string $text): void
    {
        $state['notices'][] = ['seat' => $seat, 'text' => $text];
    }

    /**
     * Révélation PUBLIQUE de cartes (ex. cartes détruites par Le Roi Sorcier) :
     * exposée à TOUS les joueurs par StateView, effacée en fin de tour.
     *
     * @param string[] $codes
     */
    public function publicReveal(array &$state, string $pseudo, string $label, array $codes): void
    {
        $state['lastReveal'] = ['pseudo' => $pseudo, 'label' => $label, 'codes' => $codes, 'turn' => $state['turn']];
    }

    /** Ajoute une carte à la révélation publique en cours (ex. défaussée par une attaque). */
    private function pushReveal(array &$state, string $code): void
    {
        if (isset($state['lastReveal'])) {
            $state['lastReveal']['codes'][] = $code;
        }
    }

    /**
     * Met en file les capacités ACTIVABLES des Lieux permanents (« une fois par
     * tour, vous POUVEZ… ») au début du tour de leur propriétaire. Optionnelles.
     */
    public function queueActivatedPermanents(array &$state, array &$player): void
    {
        // Isengard : une fois par tour, tu peux détruire une carte de ta main.
        if ($this->hasInPlay($state, $player, 'isengard')) {
            $this->queueEffect($state, [
                'op' => 'destroy', 'kind' => 'pos', 'seat' => $player['seat'], 'handOnly' => true,
                'permanent' => true, // activable à tout moment du tour (pas d'ouverture auto)
                'source' => 'isengard', 'sourceName' => 'Isengard',
                'label' => 'Isengard : tu peux détruire une carte de ta main',
            ]);
        }
    }

    /** iid de la « pire » carte de la défausse (Corruption > Désespoir > coût/PV bas), ou null. */
    private function worstDiscardCard(array $state, array $player): ?int
    {
        $worst = null;
        $worstScore = null;
        foreach ($player['discard'] as $iid) {
            $d = $this->def($state, $iid);
            $score = ($d['category'] === 'corruption') ? -100 : (($d['code'] === 'desespoir') ? -50 : (int) ($d['pv'] ?? 0) + (int) ($d['cost'] ?? 0));
            if ($worstScore === null || $score < $worstScore) {
                $worstScore = $score;
                $worst = $iid;
            }
        }

        return $worst;
    }

    /** Le joueur a-t-il un Lieu de ce code posé devant lui ? */
    private function hasInPlay(array $state, array $player, string $code): bool
    {
        foreach ($player['inPlay'] as $iid) {
            if (($state['instances'][$iid] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /** Joue automatiquement toutes les cartes restantes de la main (raccourci d'IHM). */
    public function playAll(array &$state): void
    {
        $this->assertActive($state);
        $player = &$this->active($state);
        // Copie car playCard modifie la main. Certaines cartes défaussent la main
        // (ex. Récupérez vos Forces) : on ignore les cartes qui n'y sont plus.
        foreach (array_values($player['hand']) as $iid) {
            if (!empty($state['pending'])) {
                break; // un choix est requis : on s'arrête, le joueur reprendra après
            }
            if (\in_array($iid, $this->active($state)['hand'], true)) {
                $this->playCard($state, $iid);
            }
        }
    }

    // --------------------------------------------------------------- Acheter

    /** Achète une carte du Chemin. */
    public function buyFromPath(array &$state, int $iid): void
    {
        $this->assertActive($state);
        $player = &$this->active($state);

        $pos = array_search($iid, $state['path'], true);
        if ($pos === false) {
            throw new \RuntimeException('Cette carte n\'est pas dans le Chemin.');
        }
        $def = $this->def($state, $iid);
        $cost = $this->effectiveCost($state, $player, $def);
        if ($def['cost'] === null) {
            throw new \RuntimeException(sprintf('%s ne peut pas être achetée normalement.', $def['name']));
        }
        if ($player['power'] < $cost) {
            throw new \RuntimeException('Pouvoir insuffisant.');
        }

        $player['power'] -= $cost;
        array_splice($state['path'], $pos, 1);

        // Une Fortune achetée est jouée immédiatement puis détruite.
        if ($def['type'] === 'Chance') {
            $this->log($state, sprintf('%s achète %s (Fortune) : jouée puis détruite.', $player['pseudo'], $def['name']));
            $this->effects->onPlay($state, $player, $iid, $def, $this);
            $state['removed'][] = $iid;
        } else {
            $player['discard'][] = $iid;
            $this->log($state, sprintf('%s achète %s (coût %d).', $player['pseudo'], $def['name'], $cost));
        }

        ++$player['boughtThisTurn'];
        $player['spentThisTurn'] = ($player['spentThisTurn'] ?? 0) + $cost;
        $player['boughtCodesThisTurn'][] = $def['code'];
    }

    /** Achète une carte Valeur (toujours disponible tant qu'il en reste). */
    public function buyValor(array &$state): void
    {
        $this->assertActive($state);
        $player = &$this->active($state);

        if ($state['stacks']['valor'] <= 0) {
            throw new \RuntimeException('Plus de cartes Valeur.');
        }
        $def = $this->catalog->card('valeur');
        if ($player['power'] < $def['cost']) {
            throw new \RuntimeException('Pouvoir insuffisant.');
        }
        $player['power'] -= $def['cost'];
        --$state['stacks']['valor'];
        $player['discard'][] = $this->newInstance($state, 'valeur');
        ++$player['boughtThisTurn'];
        $player['spentThisTurn'] = ($player['spentThisTurn'] ?? 0) + $def['cost'];
        $player['boughtCodesThisTurn'][] = 'valeur';
        $this->log($state, sprintf('%s achète une Valeur (coût %d).', $player['pseudo'], $def['cost']));
    }

    /** Vainc l'Archennemi au sommet de la pile (1 max par tour). */
    public function defeatArchenemy(array &$state): void
    {
        $this->assertActive($state);
        $player = &$this->active($state);

        $stack = &$state['stacks']['archenemy'];
        if (empty($stack) || !$stack[0]['faceUp']) {
            throw new \RuntimeException('Aucun Archennemi disponible à vaincre.');
        }
        $def = $this->catalog->card($stack[0]['code']);
        $cost = max(0, $def['cost'] - ($player['archenemyDiscount'] ?? 0));
        if ($player['power'] < $cost) {
            throw new \RuntimeException('Pouvoir insuffisant pour vaincre l\'Archennemi.');
        }

        $player['power'] -= $cost;
        $code = array_shift($stack)['code'];
        $player['discard'][] = $this->newInstance($state, $code);
        $player['spentThisTurn'] = ($player['spentThisTurn'] ?? 0) + $cost;
        $player['eventsThisTurn'][] = ['label' => sprintf('⚔️ Vainc %s', $def['name']), 'code' => $code];
        $this->log($state, sprintf('%s vainc %s (coût %d) !', $player['pseudo'], $def['name'], $cost));

        if ($code === 'lurtz') {
            $this->endGame($state, 'Lurtz a été vaincu.');
        }
        // Le prochain Archennemi reste FACE CACHÉE : il n'est révélé (et son
        // Embuscade de Groupe déclenchée) qu'à la FIN du tour du tueur (endTurn).
    }

    /** Révèle l'Archennemi au sommet (face visible) et déclenche son Embuscade de Groupe. */
    private function revealArchenemy(array &$state): void
    {
        $stack = &$state['stacks']['archenemy'];
        if (empty($stack) || $stack[0]['faceUp']) {
            return;
        }
        $stack[0]['faceUp'] = true;
        $code = $stack[0]['code'];
        $def = $this->catalog->card($code);
        $this->log($state, sprintf('Un nouvel Archennemi apparaît : %s.', $def['name']));
        if (!empty($def['attributes']['groupAmbush'])) {
            $this->applyGroupAmbush($state, $code);
        }
    }

    // --------------------------------------------------------------- Fin de tour

    public function endTurn(array &$state): void
    {
        $this->assertActive($state);
        if ($state['status'] === 'finished') {
            return;
        }
        unset($state['undo']); // plus d'annulation possible une fois le tour terminé
        // Un effet négatif obligatoire non résolu empêche de finir son tour.
        if ($this->hasMandatoryPending($state)) {
            throw new \RuntimeException('Tu dois d\'abord subir les effets obligatoires en attente.');
        }
        // Les effets optionnels non appliqués du joueur actif sont perdus ; on
        // conserve ceux destinés à d'autres sièges (réactions inter-joueurs).
        $state['effects'] = array_values(array_filter(
            $state['effects'],
            fn ($e) => ($e['seat'] ?? $state['activeSeat']) !== $state['activeSeat'],
        ));
        unset($state['lastReveal'], $state['notices']); // révélation/notices éphémères, effacées au tour
        if (!empty($state['groupAmbush']['done'])) {
            unset($state['groupAmbush']); // l'embuscade résolue est effacée au tour suivant
        }

        $player = &$this->active($state);

        // Récapitulatif du tour (montré aux autres joueurs) : pouvoir généré,
        // cartes achetées, événements déclenchés. Le pouvoir généré = ce qui a
        // été dépensé + ce qui reste avant remise à zéro.
        $state['lastRecap'] = [
            'seat' => $player['seat'],
            'pseudo' => $player['pseudo'],
            'kind' => $player['kind'] ?? 'human',
            'turn' => $state['turn'],
            'power' => ($player['spentThisTurn'] ?? 0) + $player['power'],
            'played' => $player['playedCountThisTurn'] ?? 0,
            'bought' => $player['boughtCodesThisTurn'] ?? [], // codes, hydratés par StateView
            'events' => $player['eventsThisTurn'] ?? [],       // [{label, code?}]
        ];

        // Cartes à détruire en fin de tour (jouées via Tambours) → retirées du jeu.
        foreach ($player['toDestroy'] ?? [] as $diid) {
            foreach (['playedThisTurn', 'inPlay'] as $zone) {
                $dp = array_search($diid, $player[$zone], true);
                if ($dp !== false) {
                    array_splice($player[$zone], $dp, 1);
                }
            }
            $state['removed'][] = $diid;
        }
        $player['toDestroy'] = [];

        // Défausse des cartes jouées + main restante.
        foreach ($player['playedThisTurn'] as $iid) {
            $player['discard'][] = $iid;
        }
        foreach ($player['hand'] as $iid) {
            $player['discard'][] = $iid;
        }
        $player['playedThisTurn'] = [];
        $player['hand'] = [];
        $player['power'] = 0;
        $player['boughtThisTurn'] = 0;
        unset($player['archenemyDiscount']);

        // Pioche d'une nouvelle main (+ cartes supplémentaires des Lieux permanents).
        $extra = 0;
        foreach ($player['inPlay'] as $liid) {
            if (($state['instances'][$liid] ?? null) === 'lothlorien') {
                ++$extra; // Lothlórien : « piochez une carte supplémentaire » à chaque fin de tour
            }
        }
        $this->draw($state, $player, self::HAND_SIZE + $extra);
        if ($extra > 0) {
            $this->log($state, sprintf('%s pioche %d carte(s) supplémentaire(s) (Lothlórien).', $player['pseudo'], $extra));
        }

        // Regarnissage du Chemin ; les Embuscades entrées entre deux tours se
        // déclenchent contre le PROCHAIN joueur au début de son tour.
        $added = $this->refillPath($state);
        $ambushes = [];
        foreach ($added as $iid) {
            $def = $this->def($state, $iid);
            if (!empty($def['attributes']['ambush'])) {
                $ambushes[] = $def['code'];
            }
        }

        // Fin du tour du tueur (après sa pioche) : on RÉVÈLE l'Archennemi suivant
        // s'il est face cachée, ce qui déclenche son Embuscade de Groupe sur TOUS.
        $this->revealArchenemy($state);

        if ($state['status'] === 'finished') {
            return;
        }

        // Joueur suivant (sens horaire).
        $count = \count($state['players']);
        $state['activeSeat'] = ($state['activeSeat'] + 1) % $count;
        if ($state['activeSeat'] === 0) {
            ++$state['turn'];
        }
        $state['phase'] = 'play';
        $next = &$this->active($state);
        $this->resetTurnTrackers($next); // remet à zéro les compteurs du récap
        $this->log($state, sprintf('Au tour de %s.', $next['pseudo']));

        // Si une Embuscade de Groupe vient d'être déclenchée, on DIFFÈRE le début
        // du tour suivant (Lieux activables + embuscades normales) : ils ne se
        // résolvent qu'APRÈS la résolution de l'Embuscade de Groupe.
        if (!empty($state['groupAmbush']) && empty($state['groupAmbush']['done'])) {
            $state['pendingAmbushes'] = $ambushes;
            $state['deferStart'] = true;
        } else {
            $this->queueActivatedPermanents($state, $next); // Lieux activables (Isengard…)
            $state['ambushQueue'] = $ambushes;
            $this->processAmbushQueue($state);
        }
    }

    /** Fin de l'Embuscade de Groupe : on résout alors le début du tour différé. */
    private function startDeferredTurn(array &$state): void
    {
        if (empty($state['deferStart'])) {
            return;
        }
        unset($state['deferStart']);
        $next = &$this->active($state);
        $this->queueActivatedPermanents($state, $next);
        unset($next);
        $state['ambushQueue'] = $state['pendingAmbushes'] ?? [];
        unset($state['pendingAmbushes']);
        $this->processAmbushQueue($state);
    }

    // --------------------------------------------------------------- Embuscades

    /** Applique toutes les Embuscades en file contre le joueur qui commence son tour. */
    public function processAmbushQueue(array &$state): void
    {
        while (!empty($state['ambushQueue'])) {
            $code = array_shift($state['ambushQueue']);
            $this->applyAmbush($state, $code);
        }
    }

    /** Applique l'effet d'Embuscade : effets à choix → liste d'effets ; effets auto → immédiats. */
    private function applyAmbush(array &$state, string $code): void
    {
        $player = &$this->active($state);
        $name = $this->catalog->card($code)['name'];
        $seat = $player['seat'];
        $player['eventsThisTurn'][] = ['label' => sprintf('⚠️ Embuscade : %s', $name), 'code' => $code];
        $this->log($state, sprintf('⚠️ Embuscade de %s contre %s !', $name, $player['pseudo']));
        unset($player);
        $this->queueAmbushEffect($state, $code, $name, $seat);
    }

    /**
     * Met en file l'effet d'Embuscade d'un Ennemi pour un siège donné (défendable).
     * Extrait d'applyAmbush pour pouvoir cibler d'AUTRES joueurs (Troupe d'Uruk-Haï).
     */
    private function queueAmbushEffect(array &$state, string $code, string $name, int $seat): void
    {
        switch ($code) {
            case 'uruk-hai': // gagne une Corruption
                $this->queueEffect($state, ['op' => 'corruption', 'kind' => 'neg', 'n' => 1, 'defendable' => true, 'seat' => $seat,
                    'source' => $code, 'sourceName' => $name, 'label' => 'Embuscade : prends une Corruption']);
                break;

            case 'eclaireurs-uruk-hai': // choisis et défausse 2 cartes
                $this->queueEffect($state, ['op' => 'discard', 'kind' => 'neg', 'count' => 2, 'defendable' => true, 'seat' => $seat,
                    'source' => $code, 'sourceName' => $name, 'label' => 'Embuscade : défausse 2 cartes (au choix)']);
                break;

            case 'grognement-uruk-hai': // choisis et défausse 1 carte
                $this->queueEffect($state, ['op' => 'discard', 'kind' => 'neg', 'count' => 1, 'defendable' => true, 'seat' => $seat,
                    'source' => $code, 'sourceName' => $name, 'label' => 'Embuscade : défausse 1 carte (au choix)']);
                break;

            case 'chef-orc':            // met un Lieu contrôlé en défausse
            case 'spectres-de-anneau':  // défausse 2 cartes au hasard
            case 'ombres-spectres-anneau': // défausse chaque carte de coût 3
            case 'cavaliers-noirs':     // défausse les Ennemis Principaux en main
                $this->queueEffect($state, ['op' => 'ambushAuto', 'kind' => 'neg', 'code' => $code, 'defendable' => true, 'seat' => $seat,
                    'source' => $code, 'sourceName' => $name, 'label' => 'Embuscade : '.$name]);
                break;
        }
    }

    /**
     * Effet automatique d'une ATTAQUE (quand un joueur joue un Ennemi qui attaque
     * ses adversaires). Appliqué sur chaque adversaire ciblé (siège de l'effet),
     * sauf s'il l'évite avec une Défense. Distinct de l'Embuscade du même Ennemi.
     */
    private function applyAttackAuto(array &$state, array &$player, string $code): void
    {
        switch ($code) {
            case 'grognement-uruk-hai': // révèle le dessus du deck ; si coût ≥1, défausse
                $top = $this->peekTop($state, $player);
                if ($top !== null) {
                    $tdef = $this->def($state, $top);
                    if ($tdef['cost'] !== null && (int) $tdef['cost'] >= 1) {
                        array_shift($player['deck']);
                        $player['discard'][] = $top;
                        $this->log($state, sprintf('%s défausse %s (dessus du deck, coût %d).', $player['pseudo'], $tdef['name'], (int) $tdef['cost']));
                        $this->pushReveal($state, $tdef['code']);
                    } else {
                        $this->log($state, sprintf('%s révèle %s (coût 0) : il la garde.', $player['pseudo'], $tdef['name']));
                    }
                }
                break;
            case 'cavaliers-noirs': // défausse une carte au hasard de la main
                $this->discardRandomFromHand($state, $player, 1);
                break;
            case 'spectres-de-anneau': // prend une Corruption
                $this->gainCorruption($state, $player);
                $this->log($state, sprintf('%s prend une Corruption.', $player['pseudo']));
                break;
        }
    }

    /**
     * EMBUSCADE DE GROUPE d'un Archennemi (à sa révélation) : frappe TOUS les
     * joueurs, inévitable. Résolue automatiquement (chaque joueur révèle sa carte
     * de coût le plus élevé). Publie une révélation visible par tous.
     */
    private function applyGroupAmbush(array &$state, string $code): void
    {
        $name = $this->catalog->card($code)['name'];
        $this->log($state, sprintf('⚔️ Embuscade de Groupe : %s ! (inévitable, tous les joueurs)', $name));

        // INTERACTIF (Saroumane / par défaut) : chaque joueur RÉVÈLE une carte de sa
        // main ; la comparaison des coûts est résolue quand tous ont révélé.
        $full = $this->catalog->card($code)['text'] ?? '';
        $text = 'Chaque joueur révèle une carte de sa main. Le coût le plus bas prend 3 Corruptions ; le coût le plus haut détruit sa carte.';
        if (($pos = mb_stripos($full, 'Embuscade de Groupe')) !== false) {
            $text = trim(mb_substr($full, $pos + mb_strlen('Embuscade de Groupe :')));
        }
        $state['groupAmbush'] = ['code' => $code, 'name' => $name, 'text' => $text, 'reveals' => [], 'outcomes' => [], 'done' => false];

        // Ulaire Ostea : AUTO (chaque joueur révèle sa main ; ≥1 Allié → 2 Corruptions).
        if ($code === 'ulaire-ostea') {
            $state['groupAmbush']['auto'] = true;
            $outcomes = [];
            foreach ($state['players'] as $p) {
                $state['groupAmbush']['reveals'][$p['seat']] = null; // main révélée en entier
                $hasAlly = false;
                foreach ($p['hand'] as $iid) {
                    if ($this->def($state, $iid)['type'] === 'Allié') {
                        $hasAlly = true;
                        break;
                    }
                }
                if ($hasAlly) {
                    $pl = &$this->playerRefBySeat($state, $p['seat']);
                    $this->gainCorruption($state, $pl);
                    $this->gainCorruption($state, $pl);
                    unset($pl);
                    $outcomes[$p['seat']][] = ['type' => 'corruption', 'n' => 2];
                    $this->log($state, sprintf('%s a révélé un Allié → prend 2 Corruptions (%s).', $p['pseudo'], $name));
                }
            }
            $state['groupAmbush']['outcomes'] = $outcomes;
            $state['groupAmbush']['done'] = true;
            $this->publicReveal($state, $name, sprintf('⚔️ Embuscade de Groupe de %s — Alliés révélés punis', $name), []);
            $this->startDeferredTurn($state);

            return;
        }

        // Ulaire Nelya : chaque joueur (en commençant par l'actif) devine le coût
        // (1-7) de la carte du dessus du deck principal, puis la révèle. Juste →
        // il la gagne en main ; faux → elle passe sous le paquet et il prend 1 Corruption.
        if ($code === 'ulaire-nelya') {
            $state['groupAmbush']['mode'] = 'guessCost';
            $n = \count($state['players']);
            $queued = 0;
            for ($k = 0; $k < $n; ++$k) {
                $seat = ($state['activeSeat'] + $k) % $n;
                $this->queueEffect($state, ['op' => 'guessCost', 'kind' => 'neg', 'seat' => $seat,
                    'source' => $code, 'sourceName' => $name,
                    'label' => sprintf('⚔️ %s : devine le coût (1-7) de la carte du dessus du deck', $name)]);
                ++$queued;
            }
            if ($queued === 0) {
                $this->finalizeGroupAmbush($state);
            }

            return;
        }

        // Fourmilière de la Moria : chaque joueur révèle sa main et défausse tous ses Artefacts.
        if ($code === 'fourmiliere-de-la-moria') {
            $state['groupAmbush']['auto'] = true;
            $outcomes = [];
            foreach ($state['players'] as $p) {
                $seat = $p['seat'];
                $state['groupAmbush']['reveals'][$seat] = null; // main révélée en entier
                $player = &$this->playerRefBySeat($state, $seat);
                $arts = array_values(array_filter($player['hand'], fn ($iid) => $this->def($state, $iid)['type'] === 'Artefact'));
                foreach ($arts as $iid) {
                    $pos = array_search($iid, $player['hand'], true);
                    if ($pos !== false) {
                        array_splice($player['hand'], $pos, 1);
                        $player['discard'][] = $iid;
                    }
                }
                if ($arts) {
                    $labels = implode(', ', array_map(fn ($iid) => $this->def($state, $iid)['name'], $arts));
                    $outcomes[$seat][] = ['type' => 'discard', 'card' => $labels, 'n' => \count($arts)];
                    $this->log($state, sprintf('%s défausse %d Artefact(s) : %s (%s).', $player['pseudo'], \count($arts), $labels, $name));
                }
                unset($player);
            }
            $state['groupAmbush']['outcomes'] = $outcomes;
            $this->finalizeGroupAmbush($state);

            return;
        }

        // Le Guetteur de l'Eau : chacun défausse 1 carte au hasard ; celui ayant
        // défaussé la carte au coût le plus élevé défausse 2 cartes de plus.
        if ($code === 'guetteur-de-leau') {
            $state['groupAmbush']['auto'] = true;
            $outcomes = [];
            $costBySeat = [];
            foreach ($state['players'] as $p) {
                $seat = $p['seat'];
                $state['groupAmbush']['reveals'][$seat] = null;
                $player = &$this->playerRefBySeat($state, $seat);
                if (!empty($player['hand'])) {
                    $ri = array_rand($player['hand']);
                    $iid = $player['hand'][$ri];
                    array_splice($player['hand'], $ri, 1);
                    $player['discard'][] = $iid;
                    $costBySeat[$seat] = (int) ($this->def($state, $iid)['cost'] ?? 0);
                    $outcomes[$seat][] = ['type' => 'discard', 'card' => $this->def($state, $iid)['name'], 'n' => 1];
                }
                unset($player);
            }
            if (!empty($costBySeat)) {
                $max = max($costBySeat);
                foreach ($costBySeat as $seat => $c) {
                    if ($c === $max) {
                        $player = &$this->playerRefBySeat($state, $seat);
                        $extra = 0;
                        for ($k = 0; $k < 2 && !empty($player['hand']); ++$k) {
                            $ri = array_rand($player['hand']);
                            $iid = $player['hand'][$ri];
                            array_splice($player['hand'], $ri, 1);
                            $player['discard'][] = $iid;
                            ++$extra;
                        }
                        if ($extra > 0) {
                            $outcomes[$seat][] = ['type' => 'discardExtra', 'n' => $extra];
                            $this->log($state, sprintf('%s (coût défaussé le + élevé : %d) défausse %d carte(s) de plus.', $player['pseudo'], $max, $extra));
                        }
                        unset($player);
                    }
                }
            }
            $state['groupAmbush']['outcomes'] = $outcomes;
            $this->finalizeGroupAmbush($state);

            return;
        }

        // Troupe d'Uruk-Haï : détruit tout le Chemin et le remplace ; chaque carte
        // entrante ayant une Embuscade s'applique à TOUS les joueurs.
        if ($code === 'troupe-uruk-hai') {
            $state['groupAmbush']['auto'] = true;
            foreach ($state['path'] as $iid) {
                $state['removed'][] = $iid; // Chemin détruit (retiré du jeu)
            }
            $state['path'] = [];
            $this->refillPath($state);
            $this->log($state, sprintf('%s : le Chemin est détruit et remplacé.', $name));
            $outcomes = [];
            foreach ($state['players'] as $p) {
                $state['groupAmbush']['reveals'][$p['seat']] = null;
            }
            // Chaque carte entrante avec Embuscade frappe TOUS les joueurs.
            foreach ($state['path'] as $iid) {
                $adef = $this->def($state, $iid);
                if (!empty($adef['attributes']['ambush'])) {
                    foreach ($state['players'] as $p) {
                        $this->queueAmbushEffect($state, $adef['code'], $adef['name'], $p['seat']);
                    }
                    $this->log($state, sprintf('Embuscade de %s appliquée à tous les joueurs.', $adef['name']));
                }
            }
            $state['groupAmbush']['outcomes'] = $outcomes;
            // Les embuscades ainsi générées seront résolues par chaque joueur ; l'Embuscade
            // de Groupe elle-même est considérée résolue (l'effet immédiat est fait).
            $this->finalizeGroupAmbush($state);

            return;
        }

        // Le Roi Sorcier : chaque joueur révèle sa main ; celui ayant le plus de
        // Courage les détruit, pioche autant de Corruptions et les RÉPARTIT.
        if ($code === 'roi-sorcier') {
            $state['groupAmbush']['mode'] = 'distribute';
            $outcomes = [];
            $courageBySeat = [];
            foreach ($state['players'] as $p) {
                $seat = $p['seat'];
                $state['groupAmbush']['reveals'][$seat] = null;
                $n = 0;
                foreach ($p['hand'] as $iid) {
                    if ($this->def($state, $iid)['code'] === 'courage') {
                        ++$n;
                    }
                }
                $courageBySeat[$seat] = $n;
            }
            $maxCourage = empty($courageBySeat) ? 0 : max($courageBySeat);
            if ($maxCourage > 0) {
                // Vainqueur = 1er siège (ordre) ayant le max de Courage.
                $winner = null;
                foreach ($courageBySeat as $seat => $n) {
                    if ($n === $maxCourage) {
                        $winner = $seat;
                        break;
                    }
                }
                $wp = &$this->playerRefBySeat($state, $winner);
                $destroyed = 0;
                foreach (array_values($wp['hand']) as $iid) {
                    if ($this->def($state, $iid)['code'] === 'courage') {
                        $pos = array_search($iid, $wp['hand'], true);
                        if ($pos !== false) {
                            array_splice($wp['hand'], $pos, 1);
                            $state['removed'][] = $iid;
                            ++$destroyed;
                        }
                    }
                }
                $wpseudo = $wp['pseudo'];
                unset($wp);
                $outcomes[$winner][] = ['type' => 'destroy', 'card' => $destroyed.' Courage', 'n' => $destroyed];
                $this->log($state, sprintf('%s a le plus de Courage (%d) : détruits, puis répartit %d Corruption(s).', $wpseudo, $destroyed, $destroyed));
                $state['groupAmbush']['outcomes'] = $outcomes;
                // Étape interactive : le vainqueur place $destroyed Corruptions.
                $this->queueEffect($state, ['op' => 'distributeCorruption', 'kind' => 'neg', 'seat' => $winner,
                    'count' => $destroyed, 'source' => $code, 'sourceName' => $name,
                    'label' => sprintf('%s : répartis %d Corruption(s) entre les joueurs', $name, $destroyed)]);
            } else {
                $state['groupAmbush']['outcomes'] = $outcomes;
                $this->finalizeGroupAmbush($state);
            }

            return;
        }

        // Lurtz : chaque joueur nomme un coût (1-7) puis révèle une carte au hasard
        // de sa main ; si le coût révélé ne correspond pas → défausse toute sa main.
        if ($code === 'lurtz') {
            $state['groupAmbush']['mode'] = 'nameCost';
            $n = \count($state['players']);
            $queued = 0;
            for ($k = 0; $k < $n; ++$k) {
                $seat = ($state['activeSeat'] + $k) % $n;
                $this->queueEffect($state, ['op' => 'nameCost', 'kind' => 'neg', 'seat' => $seat,
                    'source' => $code, 'sourceName' => $name,
                    'label' => sprintf('%s : nomme un coût (1-7)', $name)]);
                ++$queued;
            }
            if ($queued === 0) {
                $this->finalizeGroupAmbush($state);
            }

            return;
        }

        $queued = 0;
        foreach ($state['players'] as $p) {
            if (empty($p['hand'])) {
                $state['groupAmbush']['reveals'][$p['seat']] = null; // rien à révéler
                continue;
            }
            $this->queueEffect($state, ['op' => 'groupReveal', 'kind' => 'neg', 'seat' => $p['seat'],
                'source' => $code, 'sourceName' => $name,
                'label' => sprintf('⚔️ Embuscade de Groupe (%s) : révèle une carte de ta main', $name)]);
            ++$queued;
        }
        if ($queued === 0) {
            $this->resolveGroupAmbush($state);
        }
    }

    /** Clôture commune d'une Embuscade de Groupe (marque résolue + reprend le tour différé). */
    private function finalizeGroupAmbush(array &$state, array $revealedCodes = []): void
    {
        $state['groupAmbush']['done'] = true;
        $this->publicReveal($state, $state['groupAmbush']['name'], sprintf('⚔️ Embuscade de Groupe de %s — issues', $state['groupAmbush']['name']), $revealedCodes);
        $this->startDeferredTurn($state);
    }

    /** Résout l'Embuscade de Groupe une fois que tous les joueurs ont révélé leur carte. */
    private function resolveGroupAmbush(array &$state): void
    {
        $ga = $state['groupAmbush'] ?? null;
        if ($ga === null) {
            return;
        }
        $costs = [];
        $revealedCodes = [];
        foreach ($ga['reveals'] as $seat => $iid) {
            if ($iid === null) {
                continue;
            }
            $costs[$seat] = (int) ($this->def($state, $iid)['cost'] ?? 0);
            $revealedCodes[] = $this->def($state, $iid)['code'];
        }
        $outcomes = [];
        if (!empty($costs)) {
            if (($ga['code'] ?? '') === 'troll-des-cavernes') {
                // Troll des Cavernes : détruit chaque carte révélée dont le coût
                // est PARTAGÉ par au moins une autre carte révélée.
                $counts = array_count_values($costs);
                foreach ($costs as $seat => $c) {
                    if (($counts[$c] ?? 0) >= 2) {
                        $this->destroyRevealedCard($state, $ga, $seat, $outcomes);
                    }
                }
            } else {
                // Saroumane (et repli par défaut) : coût le + bas → 3 Corruptions ;
                // coût le + haut → détruit sa carte révélée.
                $min = min($costs);
                $max = max($costs);
                foreach ($costs as $seat => $c) {
                    if ($c === $min) {
                        $player = &$this->playerRefBySeat($state, $seat);
                        for ($k = 0; $k < 3; ++$k) {
                            $this->gainCorruption($state, $player);
                        }
                        $outcomes[$seat][] = ['type' => 'corruption', 'n' => 3];
                        $this->log($state, sprintf('%s (coût révélé le + bas : %d) prend 3 Corruptions.', $player['pseudo'], $min));
                        unset($player);
                    }
                    if ($c === $max) {
                        $this->destroyRevealedCard($state, $ga, $seat, $outcomes);
                    }
                }
            }
        }
        // On CONSERVE l'embuscade (marquée résolue) pour l'affichage des issues à tous.
        $state['groupAmbush']['outcomes'] = $outcomes;
        $state['groupAmbush']['done'] = true;
        $this->publicReveal($state, $ga['name'], sprintf('⚔️ Embuscade de Groupe de %s — issues', $ga['name']), $revealedCodes);

        // Ce n'est qu'APRÈS l'Embuscade de Groupe que débute réellement le tour
        // suivant (Lieux activables + embuscades normales différées).
        $this->startDeferredTurn($state);
    }

    /** Retire de la main du siège sa carte révélée et la détruit (pile destroyed). */
    private function destroyRevealedCard(array &$state, array $ga, int $seat, array &$outcomes): void
    {
        $riid = $ga['reveals'][$seat] ?? null;
        if ($riid === null) {
            return;
        }
        $player = &$this->playerRefBySeat($state, $seat);
        $rname = $this->def($state, $riid)['name'];
        $hp = array_search($riid, $player['hand'], true);
        if ($hp !== false) {
            array_splice($player['hand'], $hp, 1);
            $player['destroyed'][] = $riid;
        }
        $outcomes[$seat][] = ['type' => 'destroy', 'card' => $rname];
        $this->log($state, sprintf('%s : carte révélée détruite (%s).', $player['pseudo'], $rname));
        unset($player);
    }

    /** Effet automatique d'une Embuscade (quand elle n'est pas évitée par une Défense). */
    private function applyAmbushAuto(array &$state, array &$player, string $code): void
    {
        switch ($code) {
            case 'chef-orc':
                if (!empty($player['inPlay'])) {
                    $loc = array_shift($player['inPlay']);
                    $player['discard'][] = $loc;
                    $this->log($state, sprintf('%s perd %s (Lieu → défausse).', $player['pseudo'], $this->def($state, $loc)['name']));
                }
                break;
            case 'spectres-de-anneau':
                $this->discardRandomFromHand($state, $player, 2);
                break;
            case 'ombres-spectres-anneau':
                $this->discardHandWhere($state, $player, fn ($d) => $d['cost'] === 3);
                break;
            case 'cavaliers-noirs':
                $this->discardHandWhere($state, $player, fn ($d) => $d['category'] === 'archenemy');
                break;
        }
    }

    private function discardRandomFromHand(array &$state, array &$player, int $n): void
    {
        for ($i = 0; $i < $n && !empty($player['hand']); ++$i) {
            $idx = array_rand($player['hand']);
            $iid = $player['hand'][$idx];
            array_splice($player['hand'], $idx, 1);
            $player['discard'][] = $iid;
        }
        $this->log($state, sprintf('%s défausse %d carte(s) au hasard.', $player['pseudo'], $n));
    }

    private function discardHandWhere(array &$state, array &$player, callable $pred): void
    {
        $kept = [];
        $discarded = 0;
        foreach ($player['hand'] as $iid) {
            if ($pred($this->def($state, $iid))) {
                $player['discard'][] = $iid;
                ++$discarded;
            } else {
                $kept[] = $iid;
            }
        }
        $player['hand'] = $kept;
        $this->log($state, sprintf('%s défausse %d carte(s).', $player['pseudo'], $discarded));
    }

    /** Regarnit le Chemin ; renvoie les iid des cartes ajoutées. */
    private function refillPath(array &$state): array
    {
        $added = [];
        while (\count($state['path']) < self::PATH_SIZE) {
            if (empty($state['mainDeck'])) {
                // Impossible de regarnir les 5 emplacements → fin de partie.
                $this->endGame($state, 'Le deck principal est épuisé : le Chemin ne peut plus être regarni.');

                return $added;
            }
            $iid = array_shift($state['mainDeck']);
            $state['path'][] = $iid;
            $added[] = $iid;
        }

        return $added;
    }

    // --------------------------------------------------------------- Fin de partie

    private function endGame(array &$state, string $reason): void
    {
        $state['status'] = 'finished';
        $state['phase'] = 'finished';
        $state['endReason'] = $reason;
        $state['scores'] = $this->computeScores($state);
        $this->log($state, 'Fin de partie : '.$reason);

        // Vainqueur : plus de PV, départage par nombre d'Archennemis.
        $best = null;
        foreach ($state['scores'] as $sc) {
            if ($best === null
                || $sc['vp'] > $best['vp']
                || ($sc['vp'] === $best['vp'] && $sc['archenemies'] > $best['archenemies'])) {
                $best = $sc;
            }
        }
        $state['winnerSeat'] = $best['seat'] ?? null;
        if ($best !== null) {
            $this->log($state, sprintf('Vainqueur : %s avec %d PV.', $best['pseudo'], $best['vp']));
        }
    }

    /** Calcule le score de chaque joueur (toutes ses cartes non détruites). */
    public function computeScores(array $state): array
    {
        $scores = [];
        foreach ($state['players'] as $player) {
            $owned = array_merge(
                $player['deck'], $player['hand'], $player['discard'], $player['inPlay'],
                $player['playedThisTurn'] ?? [],
            );

            $vp = 0;
            $archenemies = 0;
            $typeCounts = [];
            $codeCounts = [];
            $defs = [];
            foreach ($owned as $iid) {
                $def = $this->def($state, $iid);
                $defs[] = $def;
                $typeCounts[$def['type']] = ($typeCounts[$def['type']] ?? 0) + 1;
                $codeCounts[$def['code']] = ($codeCounts[$def['code']] ?? 0) + 1;
                if ($def['category'] === 'archenemy') {
                    ++$archenemies;
                }
            }

            foreach ($defs as $def) {
                $vp += $this->cardVictoryPoints($def, $typeCounts, $codeCounts);
            }

            $scores[] = [
                'seat' => $player['seat'],
                'pseudo' => $player['pseudo'],
                'vp' => $vp,
                'archenemies' => $archenemies,
                'cards' => \count($owned),
            ];
        }

        return $scores;
    }

    /** PV d'une carte, en tenant compte des Quête (✱) et de la Corruption. */
    private function cardVictoryPoints(array $def, array $typeCounts, array $codeCounts): int
    {
        $attr = $def['attributes'] ?? [];

        // Carte Quête : PV conditionnel.
        if (isset($attr['questVP'])) {
            return $this->questSatisfied($def, $typeCounts, $codeCounts) ? (int) $attr['questVP'] : 0;
        }

        return (int) ($def['pv'] ?? 0);
    }

    private function questSatisfied(array $def, array $typeCounts, array $codeCounts): bool
    {
        // Heuristique v1 basée sur le type de carte de la Quête (5+ du type, "autres" exclut la carte elle-même).
        $selfType = $def['type'];
        switch ($def['code']) {
            case 'dard':                // 5+ Ennemis dans le deck
                return ($typeCounts['Ennemi'] ?? 0) >= 5;
            case 'broche-elfique':      // 5+ autres Artefacts
                return ($typeCounts['Artefact'] ?? 0) - 1 >= 5;
            case 'grand-pas-rodeur':    // 5+ autres Alliés
                return ($typeCounts['Allié'] ?? 0) - 1 >= 5;
            case 'capitaine-orc-moria': // 3+ Orcs de la Moria
                return ($codeCounts['orcs-de-la-moria'] ?? 0) >= 3;
        }

        return false;
    }

    // --------------------------------------------------------------- Utilitaires

    /** Coût effectif d'une carte du Chemin (réductions des Lieux : Hobbit Bourg). */
    public function effectiveCost(array $state, array $player, array $def): int
    {
        $cost = (int) $def['cost'];
        foreach ($player['inPlay'] as $iid) {
            $loc = $this->def($state, $iid);
            if ($loc['code'] === 'hobbit-bourg' && \in_array($def['type'], ['Artefact', 'Manœuvre'], true)) {
                --$cost;
            }
        }

        return max(0, $cost);
    }

    /** Remet à zéro les compteurs du récapitulatif de tour d'un joueur. */
    private function resetTurnTrackers(array &$player): void
    {
        $player['spentThisTurn'] = 0;
        $player['playedCountThisTurn'] = 0;
        $player['boughtCodesThisTurn'] = [];
        $player['eventsThisTurn'] = [];
        $player['permTriggered'] = []; // Lieux permanents déjà déclenchés ce tour
        $player['playedLieuxThisTurn'] = []; // Lieux joués ce tour (comptages "ce tour")
        unset($player['frodonPlayed'], $player['frodonGranted']); // Frodon Saquet (par tour)
    }

    /** Pioche N cartes (remélange la défausse si le deck est vide). */
    public function draw(array &$state, array &$player, int $n): void
    {
        for ($i = 0; $i < $n; ++$i) {
            if (empty($player['deck'])) {
                if (empty($player['discard'])) {
                    break; // plus rien à piocher
                }
                $player['deck'] = $player['discard'];
                $player['discard'] = [];
                shuffle($player['deck']);
            }
            $player['hand'][] = array_shift($player['deck']);
        }
    }

    // --------------------------------------------------------------- Choix interactifs

    /**
     * Ajoute un effet à la liste. Par défaut il appartient au joueur actif, mais
     * `$step['seat']` permet de le destiner à un AUTRE joueur (effets inter-joueurs,
     * ex. « Mon Capitaine, Mon Roi ») : ce joueur le résoudra depuis son écran,
     * hors de son tour. Les effets négatifs non applicables sont ignorés.
     */
    public function queueEffect(array &$state, array $step): void
    {
        $seat = $step['seat'] ?? $state['activeSeat'];
        $step['seat'] = $seat;
        $player = $this->playerBySeat($state, $seat) ?? $this->active($state);
        if (($step['kind'] ?? 'pos') === 'neg' && !$this->stepApplicable($state, $player, $step)) {
            return;
        }
        $step['eid'] = ($state['nextEid'] = ($state['nextEid'] ?? 0) + 1);
        $step['status'] = 'todo';
        $state['effects'][] = $step;
    }

    /** Joueur d'un siège (lecture), ou null. */
    public function playerBySeat(array $state, int $seat): ?array
    {
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $seat) {
                return $p;
            }
        }

        return null;
    }

    /** Joueur d'un siège (référence, pour mutation). */
    private function &playerRefBySeat(array &$state, int $seat): array
    {
        foreach ($state['players'] as $i => $p) {
            if ($p['seat'] === $seat) {
                return $state['players'][$i];
            }
        }
        throw new \RuntimeException('Joueur introuvable (siège '.$seat.').');
    }

    /** Applique un effet de la liste (avec la sélection du joueur). */
    public function applyEffect(array &$state, int $eid, array $payload): void
    {
        $idx = null;
        foreach ($state['effects'] as $i => $e) {
            if ($e['eid'] === $eid) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            throw new \RuntimeException('Effet introuvable.');
        }
        $step = $state['effects'][$idx];
        // L'effet peut appartenir à un autre siège que le joueur actif.
        $seat = $step['seat'] ?? $state['activeSeat'];
        $player = &$this->playerRefBySeat($state, $seat);
        $remove = true;

        // DÉFENSE : si l'effet est défendable (Embuscade/Attaque) et que le joueur
        // choisit une carte Défense de sa main, l'effet est ÉVITÉ (et parfois dévié).
        if (!empty($step['defendable']) && !empty($payload['defend'])) {
            $target = isset($payload['target']) ? (int) $payload['target'] : null;
            if ($this->useDefense($state, $player, $step, (int) $payload['defend'], $target)) {
                array_splice($state['effects'], $idx, 1);

                return;
            }
        }

        switch ($step['op']) {
            case 'ambushAuto': // effet automatique d'embuscade (non évité)
                $this->applyAmbushAuto($state, $player, $step['code']);
                break;
            case 'attack': // effet automatique d'attaque inter-joueurs (non évité)
                $this->applyAttackAuto($state, $player, $step['code']);
                break;
            case 'groupReveal': // Embuscade de Groupe : ce joueur révèle une carte de sa main
                if (empty($state['groupAmbush'])) {
                    break; // embuscade déjà effacée : effet résiduel → no-op (pas d'auto-création)
                }
                $riid = (int) ($payload['iid'] ?? 0);
                $chosen = \in_array($riid, $player['hand'], true) ? $riid : ($player['hand'][0] ?? null);
                $state['groupAmbush']['reveals'][$seat] = $chosen;
                $this->log($state, sprintf('%s révèle %s.', $player['pseudo'], $chosen !== null ? $this->def($state, $chosen)['name'] : '(aucune carte)'));
                // Reste-t-il d'autres joueurs à révéler ?
                $remaining = 0;
                foreach ($state['effects'] as $ge) {
                    if (($ge['op'] ?? '') === 'groupReveal' && $ge['eid'] !== $eid) {
                        ++$remaining;
                    }
                }
                if ($remaining === 0) {
                    array_splice($state['effects'], $idx, 1); // retire l'effet courant
                    $this->resolveGroupAmbush($state);         // puis résout la comparaison

                    return;
                }
                break;
            case 'guessCost': // Ulaire Nelya : devine le coût du dessus du deck principal
                if (empty($state['groupAmbush'])) {
                    break; // embuscade déjà effacée : effet résiduel → no-op
                }
                $guess = (int) ($payload['guess'] ?? 0);
                if (!empty($state['mainDeck'])) {
                    $top = array_shift($state['mainDeck']);
                    $tdef = $this->def($state, $top);
                    $tcost = (int) ($tdef['cost'] ?? 0);
                    $state['groupAmbush']['reveals'][$seat] = $top; // carte révélée (publique)
                    if ($guess === $tcost) {
                        $player['hand'][] = $top;
                        $state['groupAmbush']['outcomes'][$seat][] = ['type' => 'gain', 'card' => $tdef['name'], 'guess' => $guess, 'cost' => $tcost];
                        $this->log($state, sprintf('%s devine %d → %s (coût %d) : GAGNÉE en main !', $player['pseudo'], $guess, $tdef['name'], $tcost));
                    } else {
                        $state['mainDeck'][] = $top; // repart sous le paquet principal
                        $this->gainCorruption($state, $player);
                        $state['groupAmbush']['outcomes'][$seat][] = ['type' => 'miss', 'card' => $tdef['name'], 'guess' => $guess, 'cost' => $tcost];
                        $this->log($state, sprintf('%s devine %d → %s (coût %d) : raté, sous le paquet + 1 Corruption.', $player['pseudo'], $guess, $tdef['name'], $tcost));
                    }
                } else {
                    $state['groupAmbush']['reveals'][$seat] = null;
                }
                $remaining = 0;
                foreach ($state['effects'] as $ge) {
                    if (($ge['op'] ?? '') === 'guessCost' && $ge['eid'] !== $eid) {
                        ++$remaining;
                    }
                }
                if ($remaining === 0) {
                    array_splice($state['effects'], $idx, 1);
                    $revealed = [];
                    foreach ($state['groupAmbush']['reveals'] as $riid) {
                        if ($riid !== null) {
                            $revealed[] = $this->def($state, $riid)['code'];
                        }
                    }
                    $this->finalizeGroupAmbush($state, $revealed);

                    return;
                }
                break;
            case 'nameCost': // Lurtz : nomme un coût, révèle une carte au hasard de la main
                if (empty($state['groupAmbush'])) {
                    break;
                }
                $named = (int) ($payload['guess'] ?? 0);
                if (!empty($player['hand'])) {
                    $ri = array_rand($player['hand']);
                    $riid = $player['hand'][$ri];
                    $rcost = (int) ($this->def($state, $riid)['cost'] ?? 0);
                    $rname = $this->def($state, $riid)['name'];
                    $state['groupAmbush']['reveals'][$seat] = $riid;
                    if ($named === $rcost) {
                        $state['groupAmbush']['outcomes'][$seat][] = ['type' => 'safe', 'guess' => $named, 'cost' => $rcost, 'card' => $rname];
                        $this->log($state, sprintf('%s nomme %d = coût %d (%s) : main épargnée.', $player['pseudo'], $named, $rcost, $rname));
                    } else {
                        $discarded = \count($player['hand']);
                        foreach ($player['hand'] as $hiid) {
                            $player['discard'][] = $hiid;
                        }
                        $player['hand'] = [];
                        $state['groupAmbush']['outcomes'][$seat][] = ['type' => 'discardHand', 'guess' => $named, 'cost' => $rcost, 'card' => $rname, 'n' => $discarded];
                        $this->log($state, sprintf('%s nomme %d ≠ coût %d (%s) : défausse toute sa main (%d).', $player['pseudo'], $named, $rcost, $rname, $discarded));
                    }
                } else {
                    $state['groupAmbush']['reveals'][$seat] = null;
                }
                $remaining = 0;
                foreach ($state['effects'] as $ge) {
                    if (($ge['op'] ?? '') === 'nameCost' && $ge['eid'] !== $eid) {
                        ++$remaining;
                    }
                }
                if ($remaining === 0) {
                    array_splice($state['effects'], $idx, 1);
                    $revealed = [];
                    foreach ($state['groupAmbush']['reveals'] as $riid2) {
                        if ($riid2 !== null) {
                            $revealed[] = $this->def($state, $riid2)['code'];
                        }
                    }
                    $this->finalizeGroupAmbush($state, $revealed);

                    return;
                }
                break;
            case 'distributeCorruption': // Roi Sorcier : le vainqueur place 1 Corruption sur un joueur
                if (empty($state['groupAmbush'])) {
                    break;
                }
                $tseat = (int) ($payload['seat'] ?? -1);
                if ($this->playerBySeat($state, $tseat) === null) {
                    $tseat = $seat; // sécurité : à défaut, sur soi
                }
                $target = &$this->playerRefBySeat($state, $tseat);
                $this->gainCorruption($state, $target);
                $tpseudo = $target['pseudo'];
                unset($target);
                $state['groupAmbush']['outcomes'][$tseat][] = ['type' => 'corruption', 'n' => 1];
                $this->log($state, sprintf('%s reçoit 1 Corruption (Roi Sorcier).', $tpseudo));
                $step['count'] = (int) ($step['count'] ?? 1) - 1;
                if ($step['count'] > 0) {
                    $remove = false;
                    $state['effects'][$idx] = $step;
                } else {
                    array_splice($state['effects'], $idx, 1);
                    $this->finalizeGroupAmbush($state);

                    return;
                }
                break;
            case 'choosePlayer': // « Mon Capitaine » : choisis un autre joueur ; vous deux pouvez détruire
                $targetSeat = (int) ($payload['seat'] ?? -1);
                $target = $this->playerBySeat($state, $targetSeat);
                // Toi (l'initiateur) : tu peux détruire une carte (optionnel).
                $this->queueEffect($state, [
                    'op' => 'destroy', 'kind' => 'pos', 'seat' => $seat,
                    'source' => $step['source'] ?? null, 'sourceName' => $step['sourceName'] ?? '',
                    'label' => 'Détruis une carte de ta main ou de ta défausse (optionnel)',
                ]);
                // Le joueur choisi : il peut détruire aussi, sur SON siège (hors de son tour).
                if ($target !== null && $targetSeat !== $seat) {
                    $this->queueEffect($state, [
                        'op' => 'destroy', 'kind' => 'pos', 'seat' => $targetSeat,
                        'source' => $step['source'] ?? null, 'sourceName' => $step['sourceName'] ?? '',
                        'label' => sprintf('%s a joué « %s » : tu peux détruire une carte de ta main ou de ta défausse',
                            $player['pseudo'], $step['sourceName'] ?? 'Mon Capitaine, Mon Roi'),
                    ]);
                    $this->log($state, sprintf('%s choisit %s pour « %s ».',
                        $player['pseudo'], $target['pseudo'], $step['sourceName'] ?? 'Mon Capitaine, Mon Roi'));
                }
                break;
            case 'draw':
                $n = (int) ($step['n'] ?? 1);
                $this->draw($state, $player, $n);
                $this->log($state, sprintf('%s pioche %d carte(s).', $player['pseudo'], $n));
                break;
            case 'ulaireGive': // Ulaire Ostea : donne ta pire carte de défausse à un adversaire (Attaque défendable)
                $tseat = (int) ($payload['seat'] ?? -1);
                $ciid = $this->worstDiscardCard($state, $player);
                if ($this->playerBySeat($state, $tseat) !== null && $ciid !== null && $tseat !== $seat) {
                    $this->queueEffect($state, [
                        'op' => 'receiveCard', 'kind' => 'neg', 'defendable' => true, 'seat' => $tseat,
                        'giveCard' => ['iid' => $ciid, 'giver' => $seat],
                        'source' => 'ulaire-ostea', 'sourceName' => 'Ulaire Ostea',
                        'label' => sprintf('Attaque : %s te donne %s dans ta défausse', $player['pseudo'], $this->def($state, $ciid)['name']),
                    ]);
                    $this->log($state, sprintf('%s vise %s avec Ulaire Ostea.', $player['pseudo'], $this->playerBySeat($state, $tseat)['pseudo']));
                }
                break;
            case 'receiveCard': // la carte donnée arrive dans la défausse de la cible (non évitée)
                $gc = $step['giveCard'] ?? null;
                if ($gc !== null) {
                    $giver = &$this->playerRefBySeat($state, (int) $gc['giver']);
                    $gp = array_search((int) $gc['iid'], $giver['discard'], true);
                    if ($gp !== false) {
                        array_splice($giver['discard'], $gp, 1);
                        $player['discard'][] = (int) $gc['iid'];
                        $this->log($state, sprintf('%s reçoit %s dans sa défausse.', $player['pseudo'], $this->def($state, (int) $gc['iid'])['name']));
                    }
                    unset($giver);
                }
                break;
            case 'chooseOthersDraw': // Ils ne servent que des Pintes : tous SAUF le joueur choisi piochent 1
                $exclSeat = (int) ($payload['seat'] ?? -1);
                if ($this->playerBySeat($state, $exclSeat) === null) {
                    break; // aucun joueur choisi (siège invalide)
                }
                foreach ($state['players'] as $pp) {
                    if ($pp['seat'] === $exclSeat) {
                        continue;
                    }
                    $tp = &$this->playerRefBySeat($state, $pp['seat']);
                    $this->draw($state, $tp, 1);
                    unset($tp);
                }
                $this->log($state, sprintf('%s : tous sauf %s piochent 1 carte.', $player['pseudo'], $this->playerBySeat($state, $exclSeat)['pseudo'] ?? '?'));
                break;
            case 'choosePlayerDraw': // Ceci est pour Toi : un autre joueur pioche une carte
                $tseat = (int) ($payload['seat'] ?? -1);
                if ($this->playerBySeat($state, $tseat) !== null) {
                    $target = &$this->playerRefBySeat($state, $tseat);
                    $this->draw($state, $target, 1);
                    $this->log($state, sprintf('%s fait piocher 1 carte à %s.', $player['pseudo'], $target['pseudo']));
                    unset($target);
                }
                break;
            case 'destroyDespair': // Jamais plus de Désespoir : détruit un Désespoir d'une défausse au choix, puis pioche
                $tseat = (int) ($payload['seat'] ?? -1);
                if ($this->playerBySeat($state, $tseat) === null) {
                    break;
                }
                $target = &$this->playerRefBySeat($state, $tseat);
                $dpos = null;
                foreach ($target['discard'] as $k => $diid) {
                    if ($this->def($state, $diid)['code'] === 'desespoir') {
                        $dpos = $k;
                        break;
                    }
                }
                if ($dpos !== null) {
                    $diid = $target['discard'][$dpos];
                    array_splice($target['discard'], $dpos, 1);
                    $target['destroyed'][] = $diid;
                    $this->log($state, sprintf('%s détruit un Désespoir de la défausse de %s.', $player['pseudo'], $target['pseudo']));
                    $this->draw($state, $player, 1);
                    $this->log($state, sprintf('%s pioche 1 carte.', $player['pseudo']));
                }
                unset($target);
                break;
            case 'corruption':
                $n = (int) ($step['n'] ?? 1);
                for ($i = 0; $i < $n; ++$i) {
                    $this->gainCorruption($state, $player);
                }
                $this->log($state, sprintf('%s prend %d Corruption.', $player['pseudo'], $n));
                if (!empty($step['thenDraw'])) {
                    $this->draw($state, $player, (int) $step['thenDraw']);
                    $this->log($state, sprintf('%s pioche %d carte(s).', $player['pseudo'], (int) $step['thenDraw']));
                }
                break;
            case 'destroyTopDeck':
                $tiid = (int) ($step['topIid'] ?? 0);
                $tpos = array_search($tiid, $player['deck'], true);
                if ($tpos !== false) {
                    array_splice($player['deck'], $tpos, 1);
                    $player['destroyed'][] = $tiid;
                    $this->log($state, sprintf('%s détruit %s (dessus du deck).', $player['pseudo'], $this->def($state, $tiid)['name']));
                }
                break;
            case 'discardTopDeck':
                $tiid = (int) ($step['topIid'] ?? 0);
                $tpos = array_search($tiid, $player['deck'], true);
                if ($tpos !== false) {
                    array_splice($player['deck'], $tpos, 1);
                    $player['discard'][] = $tiid;
                    $this->log($state, sprintf('%s défausse %s (dessus du deck).', $player['pseudo'], $this->def($state, $tiid)['name']));
                }
                break;
            case 'putGainedOnDeck': // carte prise (Veste de Mithril) : de la défausse au dessus du deck
                $giid = (int) ($step['giid'] ?? 0);
                $gpos = array_search($giid, $player['discard'], true);
                if ($gpos !== false) {
                    array_splice($player['discard'], $gpos, 1);
                    array_unshift($player['deck'], $giid);
                    $this->log($state, sprintf('%s met %s sur le dessus de son deck.', $player['pseudo'], $this->def($state, $giid)['name']));
                }
                break;
            case 'putOnDeck':
                $piid = (int) ($payload['iid'] ?? 0);
                $ppos = array_search($piid, $player['hand'], true);
                if ($ppos !== false) {
                    array_splice($player['hand'], $ppos, 1);
                    array_unshift($player['deck'], $piid);
                    $this->log($state, sprintf('%s met %s sur son deck.', $player['pseudo'], $this->def($state, $piid)['name']));
                }
                break;
            case 'nameType':
                $this->resolveNameType($state, $player, ['card' => $step['source']], (string) ($payload['value'] ?? ''));
                break;
            case 'nameTypeMain': // Saroumane : nomme un type, révèle N du deck principal, prends celles du type
                $type = (string) ($payload['value'] ?? '');
                $n = (int) ($step['count'] ?? 7);
                $revealed = [];
                for ($i = 0; $i < $n && !empty($state['mainDeck']); ++$i) {
                    $revealed[] = array_shift($state['mainDeck']);
                }
                $takenCodes = [];
                foreach ($revealed as $riid) {
                    if ($this->def($state, $riid)['type'] === $type) {
                        $player['hand'][] = $riid;
                        $takenCodes[] = $this->def($state, $riid)['code'];
                    } else {
                        $state['mainDeck'][] = $riid; // le reste repart sous le paquet
                    }
                }
                $this->log($state, sprintf('%s nomme « %s » (Saroumane) : révèle %d cartes, prend %d en main.',
                    $player['pseudo'], $type, \count($revealed), \count($takenCodes)));
                $this->publicReveal($state, $player['pseudo'],
                    \count($takenCodes) > 0
                        ? sprintf('%s prend en main (Saroumane, type « %s »)', $player['pseudo'], $type)
                        : sprintf('%s : aucune carte « %s » parmi les 7 révélées (Saroumane)', $player['pseudo'], $type),
                    $takenCodes);
                break;
            case 'reveal': // accusé de réception : l'effet est déjà appliqué, on ferme
                break;
            case 'revealCards': // accusé de réception d'une révélation multi-cartes (Seigneur Elrond)
                break;
            case 'destroy':
                $this->resolveChooseCard($state, $player, isset($payload['iid']) ? (int) $payload['iid'] : null);
                break;
            case 'takeFromDiscard':
                $this->moveDiscardToHand($state, $player, (int) ($payload['iid'] ?? 0));
                break;
            case 'gainFromPath':
                $this->resolvePathChoice($state, $player, $step['context'] ?? [], isset($payload['iid']) ? (int) $payload['iid'] : null);
                break;
            case 'playFromPath': // Tambours : joue une carte du sentier comme si de ta main
                $piid = (int) ($payload['iid'] ?? 0);
                $ppos = array_search($piid, $state['path'], true);
                if ($ppos !== false) {
                    array_splice($state['path'], $ppos, 1);
                    $pdef = $this->def($state, $piid);
                    if ($pdef['type'] === 'Lieu') {
                        $player['inPlay'][] = $piid;
                        $player['playedLieuxThisTurn'][] = $piid;
                    } else {
                        $player['playedThisTurn'][] = $piid;
                    }
                    $player['playedCountThisTurn'] = ($player['playedCountThisTurn'] ?? 0) + 1;
                    $player['toDestroy'][] = $piid; // détruite en fin de tour
                    $this->log($state, sprintf('%s joue %s depuis le Chemin (Tambours) — détruite en fin de tour.', $player['pseudo'], $pdef['name']));
                    $this->effects->onPlay($state, $player, $piid, $pdef, $this);
                    $this->triggerPermanents($state, $player, $pdef);
                }
                break;
            case 'discard':
                $this->doDiscardChosen($state, $player, (int) ($payload['iid'] ?? 0));
                if (!empty($step['thenDiscount'])) {
                    $player['archenemyDiscount'] = ($player['archenemyDiscount'] ?? 0) + (int) $step['thenDiscount'];
                    $this->log($state, sprintf('%s : −%d pour vaincre l\'Archennemi ce tour.', $player['pseudo'], (int) $step['thenDiscount']));
                }
                if (!empty($step['thenDraw'])) {
                    $this->draw($state, $player, (int) $step['thenDraw']);
                    $this->log($state, sprintf('%s pioche %d carte(s).', $player['pseudo'], (int) $step['thenDraw']));
                }
                $step['count'] = (int) ($step['count'] ?? 1) - 1;
                if ($step['count'] > 0 && !empty($player['hand'])) {
                    $remove = false;
                    $state['effects'][$idx] = $step;
                }
                break;
        }
        if ($remove) {
            array_splice($state['effects'], $idx, 1);
        }
    }

    /**
     * Utilise une carte Défense de la main pour éviter un effet (Embuscade/Attaque).
     * La plupart se défaussent ; « L'Anneau Unique » se révèle (reste en main).
     * « C'était Proche ! » DÉVIE en plus l'effet vers un adversaire (`$targetSeat`),
     * qui pourra lui aussi l'éviter. Renvoie true si la défense a joué.
     */
    private function useDefense(array &$state, array &$player, array $step, int $iid, ?int $targetSeat): bool
    {
        $pos = array_search($iid, $player['hand'], true);
        if ($pos === false) {
            return false;
        }
        $def = $this->def($state, $iid);
        if (empty($def['attributes']['defense'])) {
            return false;
        }
        $code = $def['code'] ?? null;
        $what = $step['sourceName'] ?? 'l\'embuscade';

        if ($code === 'anneau-unique') {
            // Révélée depuis la main (on la garde) : révèle le dessus du deck
            // principal et le met dessous ; si c'est un Ennemi, prends une Corruption.
            if (!empty($state['mainDeck'])) {
                $top = array_shift($state['mainDeck']);
                $state['mainDeck'][] = $top;
                if ($this->def($state, $top)['type'] === 'Ennemi') {
                    $this->gainCorruption($state, $player);
                }
            }
            $this->log($state, sprintf('%s révèle %s pour éviter %s.', $player['pseudo'], $def['name'], $what));
        } else {
            array_splice($player['hand'], $pos, 1);
            $player['discard'][] = $iid;
            $this->log($state, sprintf('%s défausse %s pour éviter %s.', $player['pseudo'], $def['name'], $what));

            if ($code === 'vous-ne-passerez-pas') {
                $this->draw($state, $player, 1); // « … Si vous le faites, piochez une carte. »
                $this->log($state, sprintf('%s pioche 1 carte (Vous ne passerez pas).', $player['pseudo']));
            }
            if ($code === 'veste-de-mithril' && !empty($state['mainDeck'])) {
                // « … prenez la carte du dessus du deck principal et mettez-la sur le dessus de votre deck. »
                array_unshift($player['deck'], array_shift($state['mainDeck']));
                $this->log($state, sprintf('%s prend le dessus du deck principal sur son deck (Veste de Mithril).', $player['pseudo']));
            }
        }

        // Ulaire Ostea : si la cible évite l'Attaque, la carte donnée est DÉTRUITE
        // (retirée de la défausse du donneur) au lieu d'arriver dans la sienne.
        if (isset($step['giveCard'])) {
            $giver = &$this->playerRefBySeat($state, (int) $step['giveCard']['giver']);
            $gp = array_search((int) $step['giveCard']['iid'], $giver['discard'], true);
            if ($gp !== false) {
                array_splice($giver['discard'], $gp, 1);
                $giver['destroyed'][] = (int) $step['giveCard']['iid'];
                $this->log($state, sprintf('%s évite l\'Attaque : la carte est détruite.', $player['pseudo']));
            }
            unset($giver);
        }

        // Si CET effet avait été dévié vers ce joueur, le dévieur d'origine pioche
        // (un joueur de plus a évité l'Attaque/Embuscade).
        if (isset($step['deflectFrom'])) {
            $orig = &$this->playerRefBySeat($state, (int) $step['deflectFrom']);
            $this->draw($state, $orig, 1);
            $this->log($state, sprintf('%s pioche 1 (un joueur a évité, grâce à C\'était Proche).', $orig['pseudo']));
            unset($orig);
        }

        // DÉVIATION (« C'était Proche ! ») : renvoie le MÊME effet à un adversaire,
        // qui pourra à son tour l'éviter. +1 pioche pour ta propre esquive.
        if ($code === 'cetait-proche' && $targetSeat !== null) {
            $target = $this->playerBySeat($state, $targetSeat);
            if ($target !== null && $targetSeat !== $player['seat']) {
                $this->draw($state, $player, 1);
                $clone = $step;
                unset($clone['eid'], $clone['status']);
                $clone['seat'] = $targetSeat;
                $clone['deflectFrom'] = $player['seat'];
                $clone['defendable'] = true;
                $clone['label'] = sprintf('%s te renvoie : %s', $player['pseudo'], $step['sourceName'] ?? 'une embuscade');
                $this->queueEffect($state, $clone);
                $this->log($state, sprintf('%s dévie %s vers %s (C\'était Proche).', $player['pseudo'], $what, $target['pseudo']));
            }
        }

        return true;
    }

    /** Ignore un effet optionnel (positif). */
    public function skipEffect(array &$state, int $eid): void
    {
        foreach ($state['effects'] as $i => $e) {
            if ($e['eid'] === $eid) {
                if (($e['kind'] ?? 'pos') === 'neg') {
                    throw new \RuntimeException('Cet effet est obligatoire.');
                }
                $this->log($state, sprintf('Effet ignoré : %s.', $e['label'] ?? $e['op']));
                array_splice($state['effects'], $i, 1);

                return;
            }
        }
    }

    /** Reste-t-il, POUR LE JOUEUR ACTIF, un effet négatif obligatoire à résoudre ? */
    public function hasMandatoryPending(array $state): bool
    {
        $player = $this->active($state);
        foreach ($state['effects'] as $e) {
            if (($e['seat'] ?? $state['activeSeat']) !== $state['activeSeat']) {
                continue; // les effets d'un autre siège ne bloquent pas la fin de tour
            }
            if (($e['kind'] ?? 'pos') === 'neg' && $this->stepApplicable($state, $player, $e)) {
                return true;
            }
        }

        return false;
    }

    /** Un effet est-il applicable dans l'état courant ? */
    public function stepApplicable(array $state, array $player, array $step): bool
    {
        switch ($step['op']) {
            case 'ambushAuto':
            case 'attack':
            case 'receiveCard':
                return true; // toujours résoluble (subir ou défendre)
            case 'ulaireGive':
                return \count($state['players']) > 1 && !empty($player['discard']);
            case 'choosePlayer':
            case 'choosePlayerDraw':
            case 'chooseOthersDraw':
                return \count($state['players']) > 1; // il faut au moins un autre joueur
            case 'destroyDespair':
                foreach ($state['players'] as $pp) {
                    foreach ($pp['discard'] as $iid) {
                        if ($this->def($state, $iid)['code'] === 'desespoir') {
                            return true;
                        }
                    }
                }

                return false;
            case 'nameTypeMain':
                return !empty($state['mainDeck']);
            case 'draw':
            case 'nameType':
                return !empty($player['deck']) || !empty($player['discard']);
            case 'corruption':
                return ($state['stacks']['corruption'] ?? 0) > 0;
            case 'takeFromDiscard':
            case 'destroy':
            case 'gainFromPath':
            case 'playFromPath':
            case 'groupReveal':
            case 'discard':
            case 'putOnDeck':
                return !empty($this->effectOptions($state, $player, $step));
            case 'destroyTopDeck':
            case 'discardTopDeck':
                return \in_array((int) ($step['topIid'] ?? 0), $player['deck'], true);
        }

        return true;
    }

    /** Cartes candidates pour un effet à sélection (pour l'IHM). */
    public function effectOptions(array $state, array $player, array $step): array
    {
        $out = [];
        $filter = $step['filter'] ?? [];
        if ($step['op'] === 'destroy') {
            $zones = !empty($step['handOnly']) ? ['hand'] : ['hand', 'discard'];
            foreach ($zones as $zone) {
                foreach ($player[$zone] as $iid) {
                    $d = $this->def($state, $iid);
                    if (!empty($filter['type']) && $d['type'] !== $filter['type']) {
                        continue;
                    }
                    $out[] = ['iid' => $iid, 'zone' => $zone, 'card' => $d];
                }
            }
        } elseif ($step['op'] === 'takeFromDiscard') {
            foreach ($player['discard'] as $iid) {
                $d = $this->def($state, $iid);
                if (isset($filter['costMax']) && ($d['cost'] === null || $d['cost'] > $filter['costMax'])) {
                    continue;
                }
                $out[] = ['iid' => $iid, 'zone' => 'discard', 'card' => $d];
            }
        } elseif ($step['op'] === 'discard' || $step['op'] === 'putOnDeck') {
            foreach ($player['hand'] as $iid) {
                $d = $this->def($state, $iid);
                if (isset($filter['costEq']) && $d['cost'] !== $filter['costEq']) {
                    continue;
                }
                $out[] = ['iid' => $iid, 'zone' => 'hand', 'card' => $d];
            }
        } elseif ($step['op'] === 'groupReveal') {
            foreach ($player['hand'] as $iid) {
                $out[] = ['iid' => $iid, 'zone' => 'hand', 'card' => $this->def($state, $iid)];
            }
        } elseif ($step['op'] === 'playFromPath') {
            foreach ($state['path'] as $iid) {
                $out[] = ['iid' => $iid, 'zone' => 'path', 'card' => $this->def($state, $iid)];
            }
        } elseif ($step['op'] === 'gainFromPath') {
            $ctx = $step['context'] ?? [];
            foreach ($state['path'] as $i => $iid) {
                if (!empty($ctx['onlyFirst']) && $i > 0) {
                    break;
                }
                $d = $this->def($state, $iid);
                if (isset($ctx['costMax']) && ($d['cost'] === null || $d['cost'] > $ctx['costMax'])) {
                    continue;
                }
                if (isset($ctx['costMin']) && ($d['cost'] === null || $d['cost'] < $ctx['costMin'])) {
                    continue;
                }
                $out[] = ['iid' => $iid, 'zone' => 'path', 'card' => $d];
            }
        }

        return $out;
    }

    private function moveDiscardToHand(array &$state, array &$player, int $iid): void
    {
        $pos = array_search($iid, $player['discard'], true);
        if ($pos === false) {
            return;
        }
        array_splice($player['discard'], $pos, 1);
        $player['hand'][] = $iid;
        $this->log($state, sprintf('%s reprend %s de sa défausse.', $player['pseudo'], $this->def($state, $iid)['name']));
    }

    private function doDiscardChosen(array &$state, array &$player, int $iid): void
    {
        $pos = array_search($iid, $player['hand'], true);
        if ($pos === false) {
            if (empty($player['hand'])) {
                return;
            }
            $iid = $player['hand'][0];
            $pos = 0;
        }
        array_splice($player['hand'], $pos, 1);
        $player['discard'][] = $iid;
        $this->log($state, sprintf('%s défausse %s.', $player['pseudo'], $this->def($state, $iid)['name']));
    }

    /** Nomme un type puis révèle le dessus du deck ; applique le bonus et affiche la révélation. */
    private function resolveNameType(array &$state, array &$player, array $ctx, string $named): void
    {
        $card = $ctx['card'];
        $topIid = $this->peekTop($state, $player);
        $revealedDef = $topIid !== null ? $this->def($state, $topIid) : null;
        $topType = $revealedDef['type'] ?? null;
        $match = $topType !== null && $topType === $named;
        $revealedName = $revealedDef['name'] ?? '(deck vide)';
        $this->log($state, sprintf('%s nomme "%s" ; révèle %s.', $player['pseudo'], $named, $revealedName));

        $msg = '';
        switch ($card) {
            case 'lumiere-earendil':
                $player['power'] += $match ? 4 : 1;
                $msg = $match ? 'Type correct → +4 Pouvoir !' : 'Type différent → +1 Pouvoir.';
                break;
            case 'pendentif-etoile-du-soir':
                $player['power'] += $match ? 8 : 1;
                $msg = $match ? 'Type correct → +8 Pouvoir !' : 'Type différent → +1 Pouvoir.';
                break;
            case 'torche-enflammee': // si match, mettre la carte révélée en main
                if ($match && $topIid !== null) {
                    array_shift($player['deck']);
                    $player['hand'][] = $topIid;
                    $msg = 'Type correct → mise dans ta main.';
                } else {
                    $msg = 'Type différent → reste sur ton deck.';
                }
                break;
            case 'je-ne-crains-ni-douleur-ni-mort': // pioche le dessus ; +1 si match
                if ($topIid !== null) {
                    array_shift($player['deck']);
                    $player['hand'][] = $topIid;
                }
                $msg = $match ? 'Type correct → piochée + 1 Pouvoir.' : 'Type différent → piochée.';
                if ($match) {
                    $player['power'] += 1;
                }
                break;
        }

        // Étape "révélation" : le joueur voit la carte révélée avant de fermer.
        $this->queueEffect($state, [
            'op' => 'reveal', 'kind' => 'pos', 'source' => $card,
            'sourceName' => $revealedDef['name'] ?? 'Révélation',
            'label' => $msg,
            'card' => $revealedDef,
        ]);
    }

    /** Détruit (ou non) la carte choisie parmi les candidats. */
    private function resolveChooseCard(array &$state, array &$player, ?int $iid): void
    {
        if ($iid === null) {
            $this->log($state, sprintf('%s ne détruit aucune carte.', $player['pseudo']));

            return;
        }
        // Retire la carte de sa zone (main ou défausse) et la place dans les détruites.
        foreach (['hand', 'discard', 'deck'] as $zone) {
            $pos = array_search($iid, $player[$zone], true);
            if ($pos !== false) {
                array_splice($player[$zone], $pos, 1);
                $player['destroyed'][] = $iid;
                $this->log($state, sprintf('%s détruit %s.', $player['pseudo'], $this->def($state, $iid)['name']));

                return;
            }
        }
    }

    /** Gagne ou détruit une carte choisie du sentier. */
    private function resolvePathChoice(array &$state, array &$player, array $ctx, ?int $iid): void
    {
        if ($iid === null) {
            $this->log($state, sprintf('%s ne prend aucune carte du sentier.', $player['pseudo']));

            return;
        }
        $pos = array_search($iid, $state['path'], true);
        if ($pos === false) {
            return;
        }
        array_splice($state['path'], $pos, 1);
        $name = $this->def($state, $iid)['name'];

        if (($ctx['action'] ?? 'gain') === 'destroy') {
            $state['removed'][] = $iid;
            $this->log($state, sprintf('%s détruit %s du sentier.', $player['pseudo'], $name));
            // Remplacement immédiat du slot (ex. Bâton de Gandalf).
            if (!empty($ctx['replace']) && !empty($state['mainDeck'])) {
                array_splice($state['path'], $pos, 0, [array_shift($state['mainDeck'])]);
            }

            return;
        }

        $dest = $ctx['dest'] ?? 'discard';
        if ($dest === 'hand') {
            $player['hand'][] = $iid;
        } elseif ($dest === 'deckTop') {
            array_unshift($player['deck'], $iid);
        } else {
            $player['discard'][] = $iid;
        }
        $this->log($state, sprintf('%s gagne %s (du sentier).', $player['pseudo'], $name));
    }

    /** Regarde le dessus du deck (remélange si besoin) sans le retirer. */
    public function peekTop(array &$state, array &$player): ?int
    {
        if (empty($player['deck'])) {
            if (empty($player['discard'])) {
                return null;
            }
            $player['deck'] = $player['discard'];
            $player['discard'] = [];
            shuffle($player['deck']);
        }

        return $player['deck'][0] ?? null;
    }

    /** Niveau (1-4) de l'Archennemi actuellement au sommet (face visible), sinon 0. */
    public function currentArchenemyLevel(array $state): int
    {
        $stack = $state['stacks']['archenemy'];
        if (empty($stack) || !$stack[0]['faceUp']) {
            return 0;
        }

        return (int) $this->catalog->card($stack[0]['code'])['level'];
    }

    public function newInstance(array &$state, string $code): int
    {
        $iid = $state['nextIid']++;
        $state['instances'][$iid] = $code;

        return $iid;
    }

    public function gainCorruption(array &$state, array &$player): void
    {
        if ($state['stacks']['corruption'] <= 0) {
            return;
        }
        --$state['stacks']['corruption'];
        $player['discard'][] = $this->newInstance($state, 'corruption');
    }

    // ------------------------------------------------------------ Annuler (undo)

    /**
     * Mémorise l'état complet AVANT de jouer une carte à l'unité, pour pouvoir
     * remettre cette carte en main ensuite. On ne garde qu'un seul point d'annulation
     * (la dernière carte jouée) et jamais pour « Tout jouer ».
     */
    public function snapshotBeforePlay(array &$state): void
    {
        $snapshot = $state;
        unset($snapshot['undo']); // ne pas imbriquer les instantanés
        $state['undo'] = [
            'seat' => $state['activeSeat'] ?? -1,
            'turn' => $state['turn'] ?? 0,
            'snapshot' => $snapshot,
        ];
    }

    /** Remet la dernière carte jouée en main en restaurant l'état d'avant le coup. */
    public function undoLastPlay(array &$state): void
    {
        $this->assertActive($state);
        $undo = $state['undo'] ?? null;
        if ($undo === null
            || ($undo['seat'] ?? -1) !== ($state['activeSeat'] ?? -1)
            || ($undo['turn'] ?? -1) !== ($state['turn'] ?? -2)) {
            throw new \RuntimeException('Aucune carte jouée à remettre en main.');
        }
        // L'instantané ne contient pas la clé 'undo' → l'annulation est consommée.
        $state = $undo['snapshot'];
    }

    /** Invalide le point d'annulation (après un achat, un effet, la fin du tour…). */
    public function clearUndo(array &$state): void
    {
        unset($state['undo']);
    }

    private function log(array &$state, string $msg): void
    {
        $state['log'][] = $msg;
        if (\count($state['log']) > 200) {
            $state['log'] = \array_slice($state['log'], -200);
        }
    }

    private function assertActive(array $state): void
    {
        if ($state['status'] === 'finished') {
            throw new \RuntimeException('La partie est terminée.');
        }
    }
}
