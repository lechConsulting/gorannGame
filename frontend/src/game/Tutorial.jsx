// Tutoriel ludique des règles, ouvert à la demande depuis le plateau.
// Ton volontairement léger : on explique le but, l'anatomie d'une carte,
// le déroulé d'un tour, le plateau, les pièges, et la victoire.
export default function Tutorial({ onClose }) {
  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal tuto" onClick={(e) => e.stopPropagation()}>
        <div className="modal__head">
          <h3>📖 Comment jouer — en 2 minutes</h3>
          <button className="gbtn gbtn--ghost" onClick={onClose}>Fermer</button>
        </div>

        <div className="tuto__body">
          <section className="tuto__sec">
            <h4>🎯 Le but</h4>
            <p>
              Tu bâtis ton deck au fil de la partie pour accumuler des <strong>Points de Victoire (PV)</strong> 🏅.
              La partie s'arrête quand <strong>Lurtz est vaincu</strong> ⚔️ ou que le <strong>Chemin</strong> ne peut
              plus être regarni 🗺️. Le joueur avec le <strong>plus de PV</strong> l'emporte. Simple, non ?
            </p>
          </section>

          <section className="tuto__sec">
            <h4>🃏 Anatomie d'une carte</h4>
            <ul>
              <li><span className="pill pill--pv">●</span> Rond <strong>doré</strong> en bas à <strong>gauche</strong> = les <strong>PV</strong> 🏅 (comptés en fin de partie).</li>
              <li><span className="pill pill--cost">●</span> Rond <strong>gris</strong> en bas à <strong>droite</strong> = le <strong>coût</strong> 💰 (le Pouvoir à payer pour l'acheter).</li>
              <li><span className="pill pill--quest">✱</span> = carte spéciale : ne s'achète pas normalement (Quête).</li>
            </ul>
          </section>

          <section className="tuto__sec">
            <h4>🔁 Un tour, étape par étape</h4>
            <ol>
              <li><strong>Joue tes cartes</strong> 👉 chacune donne du <strong>Pouvoir ⚡</strong> (et parfois un effet).</li>
              <li><strong>Dépense ton Pouvoir</strong> ⚡ : achète des cartes du <strong>Chemin</strong> 🗺️ ou de la pile <strong>Valeur</strong> 🎖️, ou <strong>vaincs l'Archennemi</strong> ⚔️.</li>
              <li><strong>Applique les effets ✨</strong> proposés (pioche, destruction…). Les <span className="danger">obligatoires ⚠️</span> d'abord.</li>
              <li><strong>Finis ton tour</strong> ⏭️ : tout part à la défausse, tu repioches une main de 5.</li>
            </ol>
            <p className="tuto__tip">💡 Astuce : clique une carte pour la voir en grand et l'acheter / la vaincre.</p>
          </section>

          <section className="tuto__sec">
            <h4>🗺️ Le plateau</h4>
            <ul>
              <li><strong>Chemin</strong> : 5 cartes à acheter, regarnies à chaque tour.</li>
              <li><strong>Ennemi Principal</strong> 🐉 : l'Archennemi du moment ; le vaincre rapporte gros (et de belles départages).</li>
              <li><strong>Valeur</strong> 🎖️ & <strong>Corruption</strong> 🕷️ : deux piles toujours dispo.</li>
              <li><strong>Toi</strong> : ta main ✋, ta pioche 🂠 et ta défausse 🗑️.</li>
            </ul>
          </section>

          <section className="tuto__sec">
            <h4>⚠️ Les pièges</h4>
            <ul>
              <li><strong className="danger">Corruption 🕷️</strong> : elle <strong>retire des PV</strong> en fin de partie. Certaines cartes permettent de t'en débarrasser 🧹.</li>
              <li><strong className="danger">Embuscade</strong> (texte rouge) : quand un Ennemi entre au Chemin, il te tombe dessus au début de ton tour 😱.</li>
              <li><strong>Défense</strong> (en gras) : des cartes permettent d'esquiver Attaques et Embuscades 🛡️.</li>
            </ul>
          </section>

          <section className="tuto__sec">
            <h4>🏆 La victoire</h4>
            <p>
              À la fin, on compte les PV de <strong>toutes</strong> tes cartes (deck, main, défausse) moins la Corruption.
              Le plus riche en PV gagne — en cas d'égalité, celui qui a vaincu le plus d'Archennemis 👑.
            </p>
          </section>
        </div>

        <div className="controls" style={{ justifyContent: "center", marginTop: "0.6rem" }}>
          <button className="gbtn" onClick={onClose}>⚔️ C'est parti !</button>
        </div>
      </div>
    </div>
  );
}
