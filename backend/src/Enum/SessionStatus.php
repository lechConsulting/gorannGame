<?php

namespace App\Enum;

enum SessionStatus: string
{
    case Waiting = 'waiting';       // en attente de joueurs (lobby)
    case InProgress = 'in_progress'; // partie en cours
    case Finished = 'finished';     // terminée (classement figé)
    case Abandoned = 'abandoned';   // abandonnée

    public function isPlayable(): bool
    {
        return $this === self::Waiting || $this === self::InProgress;
    }
}
