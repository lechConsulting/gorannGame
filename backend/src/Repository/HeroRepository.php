<?php

namespace App\Repository;

use App\Entity\Game;
use App\Entity\Hero;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Hero>
 */
class HeroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Hero::class);
    }

    /** @return Hero[] */
    public function findByGame(Game $game): array
    {
        return $this->findBy(['game' => $game], ['name' => 'ASC']);
    }
}
