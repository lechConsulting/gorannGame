<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Garantit que le compte super-admin configuré possède ROLE_ADMIN.
 * Idempotent : à lancer au déploiement (ou après coup si le compte existait
 * déjà sans le rôle). Si le compte n'existe pas encore, il sera promu
 * automatiquement à son inscription (même email).
 */
#[AsCommand(name: 'app:promote-super-admin', description: 'Donne le rôle ADMIN au super-admin configuré (app.super_admin_email).')]
class PromoteSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly string $superAdminEmail,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->users->findOneBy(['email' => $this->superAdminEmail]);
        if (!$user) {
            $io->warning(sprintf(
                'Aucun compte « %s » pour l’instant. Il deviendra ADMIN automatiquement à son inscription.',
                $this->superAdminEmail,
            ));

            return Command::SUCCESS;
        }

        if ($user->isAdmin()) {
            $io->success(sprintf('« %s » est déjà administrateur.', $this->superAdminEmail));

            return Command::SUCCESS;
        }

        $user->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();
        $io->success(sprintf('« %s » est désormais administrateur.', $this->superAdminEmail));

        return Command::SUCCESS;
    }
}
