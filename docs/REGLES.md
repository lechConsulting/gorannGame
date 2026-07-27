# Règles — LOTR : La Communauté de l'Anneau (Deck-Building Game, Cryptozoic)

Document de référence canonique pour le moteur de jeu. Traduit/synthétisé depuis le
livret officiel. En cas de doute d'implémentation, cette page fait foi.

## But du jeu

Chaque joueur incarne un membre de la Communauté (Frodo, Gandalf, Aragorn, Legolas,
Samwise…). On commence avec un petit deck faible et on l'améliore en achetant des
cartes. On peut **vaincre des Archennemis**. À la fin, **le joueur avec le plus de
Points de Victoire (PV)** dans son deck gagne. Compétitif, 2 à 5 joueurs.

## Contenu (226 cartes)

| Nb | Type de carte |
|----|---------------|
| 31 | Courage (Départ) |
| 16 | Désespoir (Départ) |
| 116 | Deck principal |
| 16 | Valeur |
| 12 | Archennemi |
| 20 | Corruption |
| 7 | Cartes Héros de départ uniques |
| 8 | Archennemis « Mode Impossible » (avancé) |
| 7 | Cartes Héros surdimensionnées |

## Types de cartes

- **Départ (Starter)** : Courage (+1 Pouvoir), Désespoir (rien), + carte Héros unique.
- **Ennemi** : peut porter Attaque / Embuscade.
- **Lieu (Location)** : reste en jeu devant soi (effet **Permanent/Ongoing**).
- **Allié** : souvent +Pouvoir et/ou effets, beaucoup de PV.
- **Manœuvre** : effets variés, souvent une **Défense**.
- **Artefact** : effets, parfois PV conditionnels (« Quasi » = compte à la fin).
- **Fortune** : coût 0, jouée+détruite immédiatement à l'achat/gain.
- **Corruption** : **pas** de type de carte ; aucun effet ; **−1 PV** en fin de partie.

Anatomie : **PV** (rond doré, en bas à gauche) · **coût** (rond gris, en bas à droite) ·
(les cartes Quête portent un ✱ dans le rond doré = PV variable) ·
type · texte d'effet. Les Archennemis ont un **niveau** (1 à 4) au lieu d'un simple coût.

## Mise en place

### 1. Héros & decks de départ
- Chaque joueur reçoit un Héros (aléatoire ou au choix). Deck de départ = **10 cartes** :
  **6 Courage + 3 Désespoir + 1 carte Héros unique** (ex. « L'Anneau Unique » pour Frodo,
  « Bâton de Gandalf », « Épée d'Aragorn »…). Mélanger.

### 2. Deck principal
- Mélanger les **116 cartes** du deck principal, au centre.
- **Ne jamais y mettre** : Archennemi, Corruption, Courage, Désespoir, Valeur, cartes
  Héros uniques, cartes Héros surdimensionnées.

### 3. Pile d'Archennemis
- 12 Archennemis disponibles ; en partie standard on en utilise **8** (plus pour une
  partie longue). **Nazgûl est toujours utilisé** et démarre **face visible au sommet**.
- 4 niveaux : **1×Niveau 1 (Nazgûl)**, **5×Niveau 2**, **5×Niveau 3**, **1×Niveau 4 (Lurtz)**.
- Construction de la pile (de bas en haut) :
  1. Lurtz (Niv. 4) face cachée, tout en bas.
  2. Mélanger les 5 Niv. 3, en poser **3** face cachée sur Lurtz (les 2 autres de côté).
  3. Mélanger les 5 Niv. 2, en poser **3** face cachée par-dessus (les 2 autres de côté).
  4. Nazgûl (Niv. 1) face visible au sommet.
- Ordre résultant (8 Archennemis) : Niv.1 → 3× Niv.2 → 3× Niv.3 → Niv.4.
- Coûts des Archennemis : **entre 8 et 14** (selon la carte révélée).
- ⚠️ **Variante de ce jeu** : le set physique ne contient que **10 Archennemis** (2 cartes
  Niveau 3 manquantes, ignorées). Une partie standard à 8 reste jouable : on utilise les
  **3 Niv.3 disponibles** (au lieu de 3 tirés parmi 5). Le moteur se base sur ces 10 cartes.

### 4. Le Chemin (Path) & les piles
- Poser les **5 premières cartes** du deck principal, face visible, dans le **Chemin**.
- NOTE setup : le 1ᵉʳ joueur **n'est pas affecté** par les Embuscades entrées au setup.
  Si une **Fortune** est dans le Chemin au départ → la mettre sous le deck principal et
  la remplacer.
- Piles en bout de Chemin : **Valeur = 16**, **Corruption = 20** (quel que soit le nombre
  de joueurs), **Archennemi = 8** (variable). Valeur & Archennemi sont **toujours
  achetables/vaincables** pendant son tour. Les Corruptions **ne s'achètent jamais**
  (gagnées uniquement par effets hostiles). Le deck principal et les 3 piles **ne font
  pas partie** du Chemin.

## Déroulement d'un tour

Ordre de jeu déterminé au hasard ; on joue **dans le sens horaire** (joueur de gauche).

### A. Jouer ses cartes
- Jouer les cartes de sa main **dans l'ordre de son choix** ; l'effet se résout
  **immédiatement**. L'ordre n'a en général pas d'importance (sauf effets liés).
- Désespoir & Corruption ne donnent **aucun Pouvoir** (on peut les jouer, sans effet).
- On cumule du **Pouvoir** (Courage = +1 chacun, + bonus des cartes jouées).

### B. Acheter / Vaincre
- Acheter autant de cartes disponibles que le **coût cumulé ≤ Pouvoir** du tour.
  (ex. 4 Pouvoir → un achat coût 4, ou deux achats coût 2, etc.)
- Sources d'achat : le **Chemin**, la pile **Valeur**, la pile **Archennemi**.
- **Vaincre un Archennemi** = « acheter » la carte du **sommet (face visible)** de la
  pile Archennemi en payant son coût. On ne peut vaincre **qu'un seul Archennemi par
  tour** (la carte suivante reste face cachée jusqu'à la fin du tour). La carte vaincue
  va dans la **défausse** (sauf indication contraire).
- On peut **passer** si on ne peut/veut rien acheter.

### C. Fin de tour
1. Mettre **toutes** les cartes jouées **et** la main restante dans la **défausse**.
   Le Pouvoir non dépensé est **perdu**. Piocher une **nouvelle main de 5**. Passer au
   joueur de gauche.
2. Si des emplacements du Chemin sont **vides**, les **regarnir** depuis le deck
   principal (⚠️ pas au moment de l'achat — **uniquement en fin de tour**).
3. Si la carte au sommet de la pile Archennemi est **face cachée**, la **retourner**
   face visible (révèle le prochain Archennemi → déclenche son **Embuscade de Groupe**).

## Attaques & Défenses

- Une carte **Attaque** cible **les autres joueurs**. Chaque autre joueur peut l'**éviter**
  en jouant une carte **Défense** (annule l'Attaque, **1 Défense par Attaque**).
- Les joueurs qui ne défendent pas subissent l'effet.
- Éviter une Attaque **n'annule pas** les autres effets de la carte, **sauf** si l'effet
  d'Attaque compte spécifiquement les joueurs touchés.

## Embuscade (Ambush)

- Type d'Attaque porté par des **Ennemis** du deck principal (mot « Embuscade »).
- Se déclenche quand une carte à Embuscade **entre dans le Chemin entre deux tours**
  (lors du regarnissage de fin de tour) → résolue contre le **prochain joueur** au début
  de son tour. Une Embuscade qui apparaît **suite à une carte jouée pendant un tour ne se
  déclenche pas**.
- Plusieurs Embuscades : le joueur choisit l'**ordre**. Après chaque résolution, le tour
  continue. Une **Défense** peut éviter une Embuscade, mais il faut **une Défense
  distincte par Embuscade**.

## Fortunes

- Coût **0**. Achetables même sans carte en main. À l'achat/gain (par n'importe quel
  moyen), la Fortune est **jouée immédiatement, résolue, puis détruite**.
- Gagnée pendant le tour d'un **autre** joueur : elle se résout normalement, **mais** le
  bonus « +3 Pouvoir » (Rage de la Rivière) n'est **pas utilisable** hors de son tour.

## Gagner des cartes (Gain)

- « Gagner » une carte = la prendre et la mettre **immédiatement dans sa défausse**, sans
  coût supplémentaire (sauf indication).
- Si l'effet vise une carte d'un **nom / type / coût** précis et qu'il n'y en a **aucune
  disponible**, on ne gagne simplement rien.

## Résolution des effets multi-joueurs

- Si un effet affecte plusieurs joueurs et que l'ordre compte (ex. Attaque « chaque
  adversaire gagne une Corruption » mais il ne reste que 2 Corruptions), résoudre
  **joueur par joueur, sens horaire**, en commençant par celui qui a joué l'effet.
- Résoudre **entièrement** la carte jouée **avant** de résoudre les effets secondaires
  déclenchés (ex. un Lieu qui « pioche quand tu joues un Ennemi »).

## Les Archennemis (détail)

- Quand on a assez de Pouvoir, on peut **vaincre** l'Archennemi du sommet (face visible) :
  on prend la carte, elle va en défausse (sauf indication). **1 seul par tour.** La pile
  est randomisée à chaque partie.
- **Nazgûl** (Niv. 1) reste passif au sommet. Les autres Archennemis, une fois révélés,
  déclenchent une **Embuscade de Groupe** (Group Ambush) contre **chaque joueur**
  (sauf sous Nazgûl). Une Défense peut la parer (sauf mention contraire). Les Embuscades
  de Groupe se déclenchent **entre les tours** (au moment du retournement face visible),
  **avant** les Embuscades ordinaires.

## Corruption

- Certaines Attaques/Embuscades forcent à **gagner une Corruption** (va en défausse →
  entre dans le deck). Aucune capacité ; jouable sans effet.
- **−1 PV** par Corruption en fin de partie → il faut les **détruire**.
- Si la pile Corruption est **vide**, les effets « gagne une Corruption » ne donnent rien
  (mais les autres effets de la carte se résolvent). On peut toujours jouer une Défense
  même s'il n'y a plus de Corruption à gagner.

## Mélange du deck

- On **ne remélange pas** la défausse dans le deck au fur et à mesure. Mais dès qu'on doit
  **piocher / défausser / révéler** une carte et que le deck est **vide**, on remélange
  **immédiatement** la défausse → nouveau deck.

## Lieux (Locations)

- Achetés/gagnés → vont d'abord en **défausse** (comme toute carte). Quand on les
  **joue** (depuis la main), ils **restent face visible en jeu** devant soi pour le reste
  de la partie. Effet **Permanent (Ongoing)** = agit tour après tour. Nombre illimité de
  Lieux en jeu. Un Lieu en jeu ne compte plus comme carte en main.

## Détruire des cartes

- Certaines cartes permettent de **détruire** une carte (de la main, du deck, de la
  défausse, voire du Chemin) → placée dans une **pile de cartes détruites** (retirée du
  jeu). Détruire ses **Désespoir** et **Corruption** améliore fortement le deck.
- Cartes Valeur/Corruption détruites **ne retournent pas** dans leurs piles (retirées).
  *(Exception Mode Impossible : les Corruptions détruites retournent dans la pile.)*

## Fin de partie

Fin **immédiate** dès que l'une de ces conditions est remplie :
- Le dernier Archennemi, **Lurtz**, est vaincu ; **ou**
- On est **incapable de regarnir les 5 emplacements** du Chemin.

Puis chaque joueur totalise les **PV** des cartes de son deck. Les **Corruptions
retranchent** des PV. **Le plus haut total gagne.** Égalité → le joueur avec **le plus
de cartes Archennemi** gagne.

## FAQ (extraits)

- **« Vaincre / vaincu »** ne s'emploie que pour les Archennemis (on « vainc » en
  l'achetant au sommet de la pile).
- Un Lieu (ex. Mines de la Moria « pioche à la 1ʳᵉ fois que tu joues un Ennemi ») ne se
  déclenche que si l'Ennemi joué est bien **le premier** du tour — l'ordre de jeu compte.

## Mode Impossible (avancé)

- Utilise les **8 Archennemis « Mode Impossible »** (les 12 réguliers ne sont pas
  utilisés). Ordre imposé, sans repérage préalable. Nazgûl Impossible face visible.
- **Règle modifiée** : une Corruption détruite **retourne dans la pile** (au lieu d'être
  retirée du jeu).
- Clarifications de cartes (spoilers) : Lurtz Impossible → si le deck principal se vide,
  la Communauté est vaincue, **tous perdent** immédiatement. Saruman Impossible →
  mécanique de **draft** de mains. Ulaire Cantea → sans effet si ta défausse est vide.

---

## Cartes de référence (extraites du livret) — à compléter à réception du set complet

| Nom | Type | Coût | PV | Effet |
|-----|------|:--:|:--:|-------|
| Courage | Départ | 0 | 0 | +1 Pouvoir |
| Désespoir | Départ | 0 | 0 | Aucun effet |
| Corruption | (aucun) | — | −1 | Aucun effet ; −1 PV en fin de partie |
| Sting (Dard) | Artefact | 4 | 0 | +2 Pouvoir. *Quasi* : vaut 3 PV en fin de partie si ≥ 2 Ennemis dans ton deck |
| Galadriel, Dame de Lumière | Allié | 2 | 7 | +2 Pouvoir. Révèle la 1ʳᵉ carte de ton deck ; pioche-la si c'est un Allié |
| Don't Tempt Me Frodo | Manœuvre | 3 | 1 | +2 Pouvoir. *Défense* : détruis cette carte pour éviter une Attaque/Embuscade ; si tu le fais, pioche une carte |
| Rivendell | Lieu | 5 | ? | *Permanent* : pour chaque Allié que tu joues, +1 Pouvoir |
| Still Sharp | Manœuvre | 1 | 5 | Pioche une carte. Tu peux détruire une carte de ta main ou de ta défausse |
| Uruk-Hai | Ennemi | 1 | ? | +2 Pouvoir. *Attaque/Embuscade* : gagne une Corruption |
| Moria Orc Captain | Ennemi | 2 | ? | (à confirmer) |
| Gandalf's Fireworks | Artefact | 1 | 4 | +1 Pouvoir. Tu peux gagner une carte du Chemin de coût 5 ou moins ; mets-la sur ton deck |
| Cave Troll | Ennemi | 5 | 6 | +4 Pouvoir. *Embuscade de Groupe* : chaque joueur révèle une carte au hasard ; détruis chaque carte révélée partageant un coût avec une autre |
| Watcher in the Water | Ennemi | 5 | 9 | (à confirmer) |
| Nazgûl | Archennemi Niv.1 | 4 | 8 | +2 Pouvoir. Tu peux gagner une carte du Chemin de coût 5 ou moins. Démarre au sommet de la pile |
| The Balrog | Archennemi | 10 | ? | (à confirmer) |
| Lurtz | Archennemi Niv.4 | 10–14 | ? | *Embuscade de Groupe* : chaque joueur nomme un coût puis révèle une carte au hasard ; sauf si le coût nommé est révélé, il défausse sa main. Démarre au fond de la pile |

> Les `?` et « à confirmer » seront complétés à réception du scan complet des cartes.
