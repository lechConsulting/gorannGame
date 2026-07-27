<?php

namespace App\Repository;

use App\Entity\Game;
use App\Entity\GameSessionPlayer;
use App\Entity\User;
use App\Enum\SessionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GameSessionPlayer>
 */
class GameSessionPlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GameSessionPlayer::class);
    }

    /**
     * Classement agrégé, calculé à partir des parties TERMINÉES.
     *
     * @param \DateTimeImmutable|null $since limite basse sur finishedAt (null = global)
     * @param Game|null               $game filtre optionnel sur un jeu précis
     *
     * @return array<int, array{userId:int, pseudo:string, games:int, wins:int, totalScore:int, avgScore:float}>
     */
    public function leaderboard(?\DateTimeImmutable $since = null, ?Game $game = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('gsp')
            ->select('u.id AS userId', 'u.pseudo AS pseudo')
            ->addSelect('COUNT(gsp.id) AS games')
            ->addSelect('SUM(CASE WHEN gsp.winner = :yes THEN 1 ELSE 0 END) AS wins')
            ->addSelect('COALESCE(SUM(gsp.score), 0) AS totalScore')
            ->join('gsp.session', 's')
            ->join('gsp.user', 'u')
            ->where('s.status = :finished')
            ->setParameter('finished', SessionStatus::Finished->value)
            ->setParameter('yes', true)
            ->groupBy('u.id')
            ->addGroupBy('u.pseudo')
            ->orderBy('wins', 'DESC')
            ->addOrderBy('totalScore', 'DESC')
            ->addOrderBy('games', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('s.finishedAt >= :since')->setParameter('since', $since);
        }
        if ($game !== null) {
            $qb->andWhere('s.game = :game')->setParameter('game', $game);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(static function (array $r): array {
            $games = (int) $r['games'];
            $total = (int) $r['totalScore'];

            return [
                'userId' => (int) $r['userId'],
                'pseudo' => (string) $r['pseudo'],
                'games' => $games,
                'wins' => (int) $r['wins'],
                'totalScore' => $total,
                'avgScore' => $games > 0 ? round($total / $games, 2) : 0.0,
            ];
        }, $rows);
    }

    /** Classement du jour (parties terminées depuis minuit). */
    public function dailyLeaderboard(?Game $game = null, int $limit = 100): array
    {
        $startOfDay = new \DateTimeImmutable('today');

        return $this->leaderboard($startOfDay, $game, $limit);
    }

    /** Classement global (toutes les parties terminées). */
    public function globalLeaderboard(?Game $game = null, int $limit = 100): array
    {
        return $this->leaderboard(null, $game, $limit);
    }

    /** Nombre de parties terminées jouées par un utilisateur. */
    public function countGamesForUser(User $user, ?Game $game = null): int
    {
        $qb = $this->createQueryBuilder('gsp')
            ->select('COUNT(gsp.id)')
            ->join('gsp.session', 's')
            ->where('gsp.user = :user')
            ->andWhere('s.status = :finished')
            ->setParameter('user', $user)
            ->setParameter('finished', SessionStatus::Finished->value);

        if ($game !== null) {
            $qb->andWhere('s.game = :game')->setParameter('game', $game);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Statistiques récapitulatives d'un joueur.
     *
     * @return array{games:int, wins:int, totalScore:int, bestScore:int}
     */
    public function statsForUser(User $user, ?Game $game = null): array
    {
        $qb = $this->createQueryBuilder('gsp')
            ->select('COUNT(gsp.id) AS games')
            ->addSelect('SUM(CASE WHEN gsp.winner = :yes THEN 1 ELSE 0 END) AS wins')
            ->addSelect('COALESCE(SUM(gsp.score), 0) AS totalScore')
            ->addSelect('COALESCE(MAX(gsp.score), 0) AS bestScore')
            ->join('gsp.session', 's')
            ->where('gsp.user = :user')
            ->andWhere('s.status = :finished')
            ->setParameter('user', $user)
            ->setParameter('yes', true)
            ->setParameter('finished', SessionStatus::Finished->value);

        if ($game !== null) {
            $qb->andWhere('s.game = :game')->setParameter('game', $game);
        }

        $r = $qb->getQuery()->getSingleResult();

        return [
            'games' => (int) $r['games'],
            'wins' => (int) $r['wins'],
            'totalScore' => (int) $r['totalScore'],
            'bestScore' => (int) $r['bestScore'],
        ];
    }
}
