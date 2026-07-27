<?php

namespace App\Command;

use App\Entity\Game;
use App\Game\CardCatalog;
use App\Game\GameEngine;
use App\Game\GameSetupService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:play-demo', description: 'Simule une partie solo pour valider le moteur de jeu.')]
class PlayDemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CardCatalog $catalog,
        private readonly GameSetupService $setup,
        private readonly GameEngine $engine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $game = $this->em->getRepository(Game::class)->findOneBy(['slug' => 'lotr-deck-builder']);
        if (!$game) {
            $io->error('Jeu introuvable. Lance d\'abord app:seed.');

            return Command::FAILURE;
        }
        $this->catalog->load($game);

        $state = $this->setup->createState([
            ['userId' => null, 'pseudo' => 'Frodon', 'hero' => 'Frodon'],
            ['userId' => null, 'pseudo' => 'Legolas', 'hero' => 'Legolas'],
        ]);

        $io->title('Mise en place');
        $this->dumpPath($io, $state);

        // Simule jusqu'à 12 tours ou fin de partie.
        for ($t = 0; $t < 24 && $state['status'] !== 'finished'; ++$t) {
            $player = $this->activePlayer($state);
            $io->section(sprintf('Tour %d — %s', $state['turn'], $player['pseudo']));

            $this->autoResolve($state);      // embuscades éventuelles en début de tour
            $this->engine->playAll($state);
            $this->autoResolve($state);      // choix issus des cartes jouées
            $player = $this->activePlayer($state);
            $io->writeln(sprintf('Pouvoir accumulé : <info>%d</info>', $player['power']));

            // Stratégie naïve : vaincre l'Archennemi si possible, sinon acheter la carte
            // la plus chère abordable du Chemin, sinon une Valeur.
            $this->autoBuy($state, $io);

            $this->engine->endTurn($state);
        }

        $io->title('Résultat');
        $io->writeln('Raison de fin : '.($state['endReason'] ?? 'limite de tours atteinte (démo)'));
        $scores = $state['scores'] ?? $this->engine->computeScores($state);
        $rows = [];
        foreach ($scores as $s) {
            $rows[] = [$s['pseudo'], $s['vp'], $s['archenemies'], $s['cards']];
        }
        $io->table(['Joueur', 'PV', 'Archennemis', 'Cartes'], $rows);

        $io->section('Journal (extrait)');
        $io->writeln(\array_slice($state['log'], -18));

        return Command::SUCCESS;
    }

    /** IA de démo : applique les effets obligatoires (négatifs), ignore les optionnels. */
    private function autoResolve(array &$state): void
    {
        $guard = 0;
        while (!empty($state['effects']) && $guard++ < 60) {
            $e = $state['effects'][0];
            if (($e['kind'] ?? 'pos') === 'pos') {
                $this->engine->skipEffect($state, $e['eid']);
                continue;
            }
            // Effet négatif obligatoire.
            $payload = [];
            if ($e['op'] === 'nameType') {
                $payload = ['value' => 'Allié'];
            } elseif ($e['op'] === 'discard') {
                $player = $this->activePlayer($state);
                $payload = ['iid' => $player['hand'][0] ?? 0];
            }
            $this->engine->applyEffect($state, $e['eid'], $payload);
        }
    }

    private function autoBuy(array &$state, SymfonyStyle $io): void
    {
        $player = $this->activePlayer($state);

        // Vaincre l'Archennemi si abordable.
        $stack = $state['stacks']['archenemy'];
        if (!empty($stack) && $stack[0]['faceUp']) {
            $adef = $this->catalog->card($stack[0]['code']);
            $discount = $player['archenemyDiscount'] ?? 0;
            if ($player['power'] >= max(0, $adef['cost'] - $discount)) {
                $this->engine->defeatArchenemy($state);

                return;
            }
        }

        // Sinon, meilleure carte abordable du Chemin.
        $best = null;
        $bestCost = -1;
        foreach ($state['path'] as $iid) {
            $def = $this->engine->def($state, $iid);
            $cost = $def['cost'];
            if ($cost !== null && $cost <= $player['power'] && $cost > $bestCost) {
                $best = $iid;
                $bestCost = $cost;
            }
        }
        if ($best !== null) {
            $this->engine->buyFromPath($state, $best);

            return;
        }

        // Sinon une Valeur si possible.
        if ($player['power'] >= $this->catalog->card('valeur')['cost'] && $state['stacks']['valor'] > 0) {
            $this->engine->buyValor($state);
        }
    }

    private function activePlayer(array $state): array
    {
        foreach ($state['players'] as $p) {
            if ($p['seat'] === $state['activeSeat']) {
                return $p;
            }
        }

        return $state['players'][0];
    }

    private function dumpPath(SymfonyStyle $io, array $state): void
    {
        $names = [];
        foreach ($state['path'] as $iid) {
            $d = $this->engine->def($state, $iid);
            $names[] = sprintf('%s (%s, coût %s)', $d['name'], $d['type'], $d['cost'] ?? '✱');
        }
        $io->writeln('Chemin : '.implode(' | ', $names));
        $top = $state['stacks']['archenemy'][0];
        $io->writeln('Archennemi au sommet : '.$this->catalog->card($top['code'])['name']);
    }
}
