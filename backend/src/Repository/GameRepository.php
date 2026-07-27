<?php

namespace App\Repository;

use App\Entity\Game;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Game>
 */
class GameRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Game::class);
    }

    public function findOneBySlug(string $slug): ?Game
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return Game[] */
    public function findPublished(): array
    {
        return $this->findBy(['published' => true], ['name' => 'ASC']);
    }
}
