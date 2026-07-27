<?php

namespace App\Controller\Api;

use App\Repository\GameRepository;
use App\Repository\GameSessionPlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/leaderboard')]
class LeaderboardController extends AbstractController
{
    /**
     * Classements (public).
     *   GET /api/leaderboard          -> { daily: [...], global: [...] }
     *   GET /api/leaderboard?game=slug -> filtré sur un jeu
     */
    #[Route('', name: 'api_leaderboard', methods: ['GET'])]
    public function index(
        Request $request,
        GameSessionPlayerRepository $players,
        GameRepository $games,
    ): JsonResponse {
        $game = null;
        if ($slug = $request->query->get('game')) {
            $game = $games->findOneBySlug($slug);
            if (!$game) {
                return $this->json(['error' => 'Jeu introuvable.'], 404);
            }
        }

        return $this->json([
            'game' => $game?->getSlug(),
            'daily' => $players->dailyLeaderboard($game),
            'global' => $players->globalLeaderboard($game),
        ]);
    }
}
