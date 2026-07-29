<?php

namespace App\Repository;

use App\Entity\GameSession;
use App\Enum\SessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSession>
 */
class GameSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSession::class);
    }

    public function findOneByCode(string $code): ?GameSession
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** Parties ouvertes (lobby) qu'on peut rejoindre. @return GameSession[] */
    public function findJoinable(): array
    {
        return $this->findBy(['status' => SessionStatus::Waiting], ['createdAt' => 'DESC']);
    }

    /** Parties en cours (pour la reprise après coupure). @return GameSession[] */
    public function findInProgress(): array
    {
        return $this->findBy(['status' => SessionStatus::InProgress], ['startedAt' => 'DESC']);
    }
}
