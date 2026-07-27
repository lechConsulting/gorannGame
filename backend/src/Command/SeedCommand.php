<?php

namespace App\Command;

use App\Entity\Card;
use App\Entity\Game;
use App\Entity\Hero;
use App\Entity\User;
use App\Enum\CardCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed',
    description: 'Crée un admin, le jeu LOTR, et importe les cartes + héros depuis data/wave*.json.',
)]
class SeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // --- Admin de démonstration ---
        $adminEmail = 'admin@lotr.local';
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => $adminEmail]);
        if (!$admin) {
            $admin = new User();
            $admin->setEmail($adminEmail);
            $admin->setPseudo('Gandalf');
            $admin->setRoles([User::ROLE_ADMIN]);
            $admin->setPassword($this->hasher->hashPassword($admin, 'admin1234'));
            $this->em->persist($admin);
            $io->success("Admin créé : $adminEmail / admin1234");
        }

        // --- Jeu LOTR ---
        $slug = 'lotr-deck-builder';
        $game = $this->em->getRepository(Game::class)->findOneBy(['slug' => $slug]);
        if (!$game) {
            $game = new Game();
            $game->setName('Le Seigneur des Anneaux — Deck Builder');
            $game->setSlug($slug);
            $game->setDescription('Deck-building dans l\'univers du Seigneur des Anneaux.');
            $game->setMinPlayers(2);
            $game->setMaxPlayers(5);
            $game->setPublished(true);
            $game->setCreatedBy($admin);
            $this->em->persist($game);
            $io->success('Jeu créé : '.$game->getName());
        }

        // On repart d'une base propre pour les cartes et héros de ce jeu.
        foreach ($game->getCards() as $existing) {
            $this->em->remove($existing);
        }
        foreach ($this->em->getRepository(Hero::class)->findBy(['game' => $game]) as $h) {
            $this->em->remove($h);
        }
        $this->em->flush();

        // --- Import des données ---
        // Priorité au deck.json (source de vérité du back-office) ; sinon les vagues d'import.
        if (is_file($this->projectDir.'/data/deck.json')) {
            $waveFiles = [$this->projectDir.'/data/deck.json'];
            $io->note('Source : data/deck.json (back-office).');
        } else {
            $waveFiles = glob($this->projectDir.'/data/wave*.json') ?: [];
            sort($waveFiles);
        }

        $totalCards = 0;
        $totalHeroes = 0;

        foreach ($waveFiles as $file) {
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

            foreach ($data['cards'] ?? [] as $c) {
                $card = new Card();
                $card->setCode($c['code']);
                $card->setName($c['name']);
                $card->setType($c['type']);
                $card->setCategory(CardCategory::from($c['category']));
                $card->setCost($c['cost'] ?? null);
                $card->setVictoryPoints($c['pv'] ?? null);
                $card->setLevel($c['level'] ?? null);
                $card->setHero($c['hero'] ?? null);
                $card->setText($c['text'] ?? null);
                $card->setQuantity($c['quantity'] ?? 1);
                $card->setAttributes($c['attributes'] ?? []);
                $game->addCard($card);
                $this->em->persist($card);
                ++$totalCards;
            }

            foreach ($data['heroes'] ?? [] as $h) {
                $hero = new Hero();
                $hero->setGame($game);
                $hero->setName($h['name']);
                $hero->setRace($h['race']);
                $hero->setStartingCardCode($h['startingCardCode']);
                $this->em->persist($hero);
                ++$totalHeroes;
            }

            $io->writeln(sprintf('  • %s', basename($file)));
        }

        $this->em->flush();

        $io->success(sprintf('%d cartes et %d héros importés depuis %d fichier(s).', $totalCards, $totalHeroes, \count($waveFiles)));

        // Récapitulatif par catégorie.
        $rows = [];
        foreach (CardCategory::cases() as $cat) {
            $count = $this->em->getRepository(Card::class)->count(['game' => $game, 'category' => $cat]);
            if ($count > 0) {
                $rows[] = [$cat->value, $count];
            }
        }
        $io->table(['Catégorie', 'Nb cartes'], $rows);

        return Command::SUCCESS;
    }
}
