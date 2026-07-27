<?php

namespace App\Repository;

use App\Entity\Card;
use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Card>
 */
class CardRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Card::class);
    }

    /** @return Card[] */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game], ['type' => 'ASC', 'name' => 'ASC']);
    }
}
