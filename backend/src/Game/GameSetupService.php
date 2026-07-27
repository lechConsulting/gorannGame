<?php

namespace App\Game;

/**
 * Construit l'état initial d'une partie (le "state" JSON de GameSession).
 * Voir docs/REGLES.md pour la mise en place. Adapté au set réel (10 Archennemis).
 */
class GameSetupService
{
    private const HAND_SIZE = 5;
    private const START_COURAGE = 6;
    private const START_DESPAIR = 3;
    private const PATH_SIZE = 5;
    private const VALOR_STACK = 15;
    private const CORRUPTION_STACK = 20;

    public function __construct(private readonly CardCatalog $catalog)
    {
    }

    /**
     * @param array<int, array{userId:?int, pseudo:string, hero:string, kind?:string}> $players
     */
    public function createState(array $players): array
    {
        $state = [
            'version' => 1,
            'status' => 'in_progress',
            'phase' => 'play',
            'turn' => 1,
            'activeSeat' => 0,
            'instances' => [],   // iid => code
            'nextIid' => 1,
            'players' => [],
            'path' => [],
            'mainDeck' => [],
            'removed' => [],      // cartes retirées du jeu (deck principal / sentier)
            'stacks' => [
                'valor' => self::VALOR_STACK,
                'corruption' => self::CORRUPTION_STACK,
                'archenemy' => [],
            ],
            'log' => [],
            'endReason' => null,
            'effects' => [],
            'ambushQueue' => [],
            'nextEid' => 1,
        ];

        // --- Joueurs & decks de départ (6 Courage + 3 Désespoir + carte de héros) ---
        foreach ($players as $seat => $p) {
            $hero = $this->catalog->allHeroes()[$p['hero']] ?? throw new \RuntimeException("Héros inconnu : {$p['hero']}");

            $deck = [];
            for ($i = 0; $i < self::START_COURAGE; ++$i) {
                $deck[] = $this->newInstance($state, 'courage');
            }
            for ($i = 0; $i < self::START_DESPAIR; ++$i) {
                $deck[] = $this->newInstance($state, 'desespoir');
            }
            $deck[] = $this->newInstance($state, $hero['startingCardCode']);
            shuffle($deck);

            // Main de départ de 5 cartes.
            $hand = array_splice($deck, 0, self::HAND_SIZE);

            $state['players'][] = [
                'seat' => $seat,
                'userId' => $p['userId'] ?? null,
                'kind' => $p['kind'] ?? 'human', // 'human' | 'bot'
                'pseudo' => $p['pseudo'],
                'hero' => $p['hero'],
                'deck' => $deck,
                'hand' => $hand,
                'discard' => [],
                'inPlay' => [],       // Lieux permanents
                'destroyed' => [],
                'power' => 0,
                'boughtThisTurn' => 0,
                'playedThisTurn' => [],
                // Compteurs du récapitulatif de tour (montré aux autres joueurs).
                'spentThisTurn' => 0,
                'playedCountThisTurn' => 0,
                'boughtCodesThisTurn' => [],
                'eventsThisTurn' => [],
                'permTriggered' => [],
                'playedLieuxThisTurn' => [],
                'toDestroy' => [],
            ];
        }

        // --- Deck principal : toutes les cartes main_deck étendues par quantité ---
        $main = [];
        foreach ($this->catalog->byCategory('main_deck') as $c) {
            for ($i = 0; $i < $c['quantity']; ++$i) {
                $main[] = $this->newInstance($state, $c['code']);
            }
        }
        shuffle($main);
        $state['mainDeck'] = $main;

        // --- Chemin : 5 cartes, sans Fortune au setup (remises sous le deck) ---
        $path = [];
        while (\count($path) < self::PATH_SIZE && !empty($state['mainDeck'])) {
            $iid = array_shift($state['mainDeck']);
            if ($this->isFortune($state, $iid)) {
                $state['mainDeck'][] = $iid; // sous le deck
                continue;
            }
            $path[] = $iid;
        }
        $state['path'] = $path;

        // --- Pile d'Archennemis (8 : Nazgûl + 3 Niv.2 + 3 Niv.3 + Lurtz) ---
        $state['stacks']['archenemy'] = $this->buildArchenemyStack();

        $state['log'][] = sprintf('Partie créée : %d joueur(s). C\'est au tour de %s.', \count($players), $state['players'][0]['pseudo']);

        return $state;
    }

    /** Construit la pile d'Archennemis, sommet en premier (index 0). */
    private function buildArchenemyStack(): array
    {
        $byLevel = [1 => [], 2 => [], 3 => [], 4 => []];
        foreach ($this->catalog->byCategory('archenemy') as $c) {
            $byLevel[$c['level']][] = $c['code'];
        }

        shuffle($byLevel[2]);
        shuffle($byLevel[3]);

        $level2 = \array_slice($byLevel[2], 0, 3);
        $level3 = \array_slice($byLevel[3], 0, 3); // on a exactement 3 Niv.3 dans ce set

        // Sommet -> fond : Niv1, 3x Niv2, 3x Niv3, Niv4
        $ordered = array_merge($byLevel[1], $level2, $level3, $byLevel[4]);

        $stack = [];
        foreach ($ordered as $i => $code) {
            $stack[] = ['code' => $code, 'faceUp' => $i === 0];
        }

        return $stack;
    }

    private function newInstance(array &$state, string $code): int
    {
        $iid = $state['nextIid']++;
        $state['instances'][$iid] = $code;

        return $iid;
    }

    private function isFortune(array $state, int $iid): bool
    {
        $code = $state['instances'][$iid];

        return $this->catalog->card($code)['type'] === 'Chance';
    }
}
