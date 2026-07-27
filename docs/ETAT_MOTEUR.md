# État du moteur de jeu — reprise

_Rédigé pendant la session nocturne du 2026-07-27. Tout est sur disque et fonctionnel._

---

## ⭐ Phase 2 — MULTIJOUEUR + BOTS (fait le 2026-07-27, après-midi)

Le jeu est passé du solo/hotseat à un **vrai multijoueur en ligne jusqu'à 5**,
avec **joueurs automatiques (bots)** pour compléter une table ou jouer seul
contre 4 IA. Vérifié de bout en bout (API + navigateur).

### Comment lancer (multi)
1. **Backend** : `cd backend && symfony server:start -d --port=8000 --no-tls`
   (MariaDB `lotr_deckbuilder` doit tourner + `php bin/console app:seed`).
2. **Hub Mercure (temps réel)** — voir ci-dessous. **Optionnel** : sans lui,
   l'app bascule automatiquement en **sondage** (getGame toutes les 3 s, lobby
   2,5 s), donc tout reste jouable.
3. **Frontend** : `cd frontend && npm run dev` → http://localhost:5173.
4. Se **connecter/s'inscrire**, « Créer une table », **ajouter des bots** (bouton
   de l'hôte), choisir son héros, **Démarrer**. Pour jouer à plusieurs humains :
   partager le **code de table** (join par code) ; chacun a besoin d'un compte.

### Hub Mercure (à installer pour le temps réel instantané)
Choix d'archi : **Mercure = bus de notification**, pas canal de données. Le
backend publie un *ping* léger sur `game/{id}` / `lobby/{id}` (aucune info
secrète) ; chaque client re-`GET` alors l'état **masqué par siège**. Le hub peut
donc tourner en **mode anonyme**.

- Pas de binaire ni Docker installés sur la machine à ce jour. Pour l'activer,
  installer le hub Mercure standalone (projet officiel `dunglas/mercure`) et le
  lancer en écoutant sur `:3000`, abonnements anonymes autorisés, CORS pour
  `http://localhost:5173`. Vars déjà posées dans `backend/.env.local`
  (`MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET`).
- Front : `VITE_MERCURE_URL` (défaut `http://127.0.0.1:3000/.well-known/mercure`).
- Tant que le hub est absent : `GamePublisher` avale l'échec (try/catch) et le
  front sonde → **aucun blocage**.

### Ce qui a été ajouté (Phase 2)
- **Identité** : comptes obligatoires (JWT Lexik déjà en place). `^/api/play`
  repassé de PUBLIC à **ROLE_JOUEUR**. `AuthGate` (login + inscription) côté front.
- **Lobby** : `LobbyController` (`/api/lobby` : create/join/hero/bot/remove/start),
  roster stocké dans `state['lobby']['seats']` (index = siège) tant que Waiting.
  Front : `Lobby` (accueil créer/rejoindre) + `WaitingRoom` (sièges, choix héros,
  ajout/retrait de bots, démarrage), abonné à `lobby/{id}`.
- **Bots (heuristique simple)** : `BotPlayer` (résout ses effets par défaut, joue
  toute la main, vainc l'Archennemi si abordable puis achète la meilleure carte
  du Chemin / une Valeur, finit son tour) + `GameOrchestrator::advanceBots`
  (enchaîne les tours de bots après chaque action humaine et au démarrage).
  **Réutilise 100 % du `GameEngine`** — aucune règle dupliquée.
- **Masquage** : `StateView::build($state, $viewerSeat)` — main des adversaires
  cachée (compteur seul) ; options d'effets réservées au joueur actif.
- **Garde de tour** : seul l'humain du siège actif peut agir (403 sinon) ; un
  non-participant reçoit 403, un anonyme 401.
- **Classements** : à la fin, `PlayController::recordResults` écrit un
  `GameSessionPlayer` (score/rang/vainqueur) par **humain** (les bots ne sont pas
  classés) → alimente `/api/leaderboard` et les stats `/api/me`.
- **Temps réel** : `GamePublisher` (Mercure) + helper front `subscribe(topic)` ;
  `GameBoard` distingue **spectateur** (mes zones) et **joueur actif** (tour),
  verrouille les contrôles hors de mon tour, affiche une **barre d'adversaires**.

### Reste à faire (Phase 2+)
- Installer/lancer le hub Mercure sur la machine (ci-dessus).
- **Attaques inter-joueurs** (mot-clé Attaque/Défense) et **Embuscades de Groupe**
  des Archennemis — branchées au multi (aujourd'hui : les Embuscades d'Ennemis
  contre le joueur qui commence son tour fonctionnent déjà, y compris pour les bots).
- Reprise de partie / rejoindre une partie **en cours** (actuellement on rejoint
  seulement en salle d'attente) ; verrou optimiste sur `GameSession.state`.
- Bots plus fins (viser les cartes moteur, pas seulement les PV).

---

## En une phrase
Le jeu est **jouable de bout en bout en solo** dans le navigateur : choix du héros,
tour de jeu (jouer ses cartes → Pouvoir → acheter dans le Chemin / la Valeur /
vaincre un Archennemi → fin de tour), fin de partie et calcul des scores (PV, Quête,
Corruption).

## Comment lancer (2 terminaux)

**Backend** (API + moteur) :
```bash
cd backend
symfony server:start -d --port=8000 --no-tls   # ou: symfony server:start
```
(La base MariaDB `lotr_deckbuilder` doit tourner et être seedée : `php bin/console app:seed`.)

**Frontend** (IHM) :
```bash
cd frontend
npm run dev        # http://localhost:5173
```
Ouvre http://localhost:5173 → choisis un héros → « Commencer la partie ».

**Vérifier le moteur sans navigateur** (simulation) :
```bash
cd backend && php bin/console app:play-demo
```

## Ce qui est construit

### Backend (`backend/src/Game/`)
- `CardCatalog` — charge les définitions de cartes/héros en mémoire (indexées par code).
- `GameSetupService` — mise en place : decks de départ (6 Courage + 3 Désespoir + carte
  de héros), deck principal mélangé (Fortunes exclues du Chemin au setup), Chemin de 5,
  piles Valeur/Corruption, pile d'Archennemis (Nazgûl → 3 Niv.2 → 3 Niv.3 → Lurtz).
- `GameEngine` — actions : `playCard`, `playAll`, `buyFromPath`, `buyValor`,
  `defeatArchenemy`, `endTurn` (défausse, pioche 5, regarnissage du Chemin, retournement
  Archennemi, joueur suivant), fin de partie + `computeScores`.
- `EffectRegistry` — effets des cartes à la mise en jeu (Pouvoir + bonus conditionnels +
  pioche + réductions). Registre extensible par code de carte.
- `StateView` — sérialise l'état pour le front (cartes hydratées, compteurs).

### API (`backend/src/Controller/Api/PlayController.php`) — publique (démo)
- `POST /api/play/new` `{slug, players:[{pseudo,hero}]}` → crée une partie.
- `GET  /api/play/{id}` → état courant.
- `POST /api/play/{id}/action` `{type, iid?}` → `play|play-all|buy-path|buy-valor|defeat|end-turn`.

L'état est persisté dans `GameSession.state` (JSON) à chaque action.

### Frontend (`frontend/src/`)
- `game/Lobby.jsx` — choix du pseudo + héros.
- `game/GameBoard.jsx` — plateau : piles, Chemin, main, HUD (Pouvoir/deck/défausse),
  contrôles, journal, écran de fin (classement).
- `game/GameCard.jsx` — carte (anatomie **corrigée** : PV doré à gauche, coût gris à
  droite, ✱ = valeur variable Quête).
- `api.js` — client fetch vers `:8000` (surchargeable via `VITE_API_BASE`).

## Choix / simplifications assumés (à valider ensemble)
1. **Solo/hotseat** : un navigateur pilote la partie. Pas encore de temps réel Mercure
   ni de secret des mains adverses.
2. **Effets inter-joueurs (Attaque/Embuscade/Embuscade de Groupe)** : non appliqués en
   solo (ils ciblent « les autres joueurs »). La partie "self" des cartes (Pouvoir,
   pioche) est bien jouée. À implémenter en Phase 2 avec le multi.
3. **Effets à choix** ("nommez un type", "vous pouvez détruire…", "révélez le dessus")
   sont approximés par un comportement déterministe raisonnable, faute d'UI de choix.
   À affiner (cf. `EffectRegistry` — cas documentés).
4. **Endpoints `/api/play` publics** pour la démo → à re-sécuriser (JWT + appartenance à
   la partie) avec le lobby.
5. **Classements** : la fin de partie calcule et stocke les scores dans le state, mais
   n'écrit pas encore de lignes `GameSessionPlayer` (nécessite des joueurs authentifiés).

## Prochaines étapes (ordre suggéré)
1. **Couverture des effets** : UI de choix (cibler un joueur, nommer un type, choisir une
   carte à détruire/défausser) + brancher les Attaques/Embuscades.
2. **Multijoueur** : lobby (créer/rejoindre à 5), tours synchronisés, **Mercure (SSE)**
   pour pousser l'état ; masquage des mains adverses dans `StateView`.
3. **Embuscades de Groupe** des Archennemis au retournement.
4. **Classements** : écrire `GameSessionPlayer` (score/rang/vainqueur) en fin de partie
   pour alimenter `/api/leaderboard`.
5. **Persistance/robustesse** : verrou optimiste sur `GameSession.state`, reprise de
   partie, tests.

## Fichiers clés
- Moteur : `backend/src/Game/*`
- API : `backend/src/Controller/Api/PlayController.php`
- Front : `frontend/src/game/*`, `frontend/src/api.js`
- Règles : `docs/REGLES.md` · Données cartes : `backend/data/wave*.json`
