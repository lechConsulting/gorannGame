<?php

namespace App\Enum;

/**
 * Catégorie structurelle d'une carte : détermine dans quelle pile / zone elle vit.
 * (À ne pas confondre avec le `type` : Ennemi, Allié, Lieu, Artefact, Manœuvre, Fortune…)
 */
enum CardCategory: string
{
    case MainDeck = 'main_deck';       // deck principal (116 cartes)
    case Starter = 'starter';          // Courage / Désespoir (départ générique)
    case HeroStarting = 'hero_starting'; // carte de départ unique d'un Héros
    case Valor = 'valor';              // pile Valeur
    case Archenemy = 'archenemy';      // pile Archennemi (a un niveau)
    case Corruption = 'corruption';    // pile Corruption
}
