import GameCard from "./GameCard";

// Alerte « Embuscade de Groupe » : montrée à TOUS les joueurs. Affiche le texte
// de l'embuscade et un bloc par joueur. Deux modes :
//  - 'reveal'    : chacun révèle une carte de sa main (révélation simultanée) ;
//  - 'guessCost' : Ulaire Nelya — chacun devine le coût (1-7) de la carte du
//                  dessus du deck principal, puis la révèle (gagnée si juste).
export default function GroupAmbushAlert({ data, busy, onReveal, onGuess, onClose }) {
  const { name, text, done, mode, players, myEid, myOp, myOptions } = data;
  return (
    <div className="modal-overlay">
      <div className="modal ga-alert">
        <div className="modal__head">
          <h3>⚔️ Embuscade de Groupe — {name}</h3>
          {done && <button className="gbtn gbtn--ghost" onClick={onClose}>Fermer</button>}
        </div>
        <p className="ga-alert__text">{text}</p>

        <div className="ga-grid">
          {players.map((p) => (
            <div key={p.seat} className={`ga-block ${p.isMe ? "ga-block--me" : ""}`}>
              <div className="ga-block__n">
                {p.kind === "bot" ? "🤖 " : "🧑 "}{p.pseudo}{p.isMe ? " · toi" : ""}
              </div>

              {/* Mon action à faire */}
              {p.isMe && !p.revealed && myEid && myOp === "guessCost" && (
                <>
                  <div className="ga-block__todo">👉 Devine le coût de la carte du dessus du deck :</div>
                  <div className="ga-guess">
                    {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                      <button key={n} className="gbtn ga-guess__btn" disabled={busy} onClick={() => onGuess(myEid, n)}>{n}</button>
                    ))}
                  </div>
                </>
              )}
              {p.isMe && !p.revealed && myEid && myOp === "groupReveal" && (
                <>
                  <div className="ga-block__todo">👉 Révèle une carte de ta main :</div>
                  <div className="row">
                    {(myOptions || []).map((c) => (
                      <GameCard key={c.iid} card={c} size="sm" onClick={busy ? undefined : () => onReveal(myEid, c.iid)} />
                    ))}
                    {(myOptions || []).length === 0 && <span className="ga-block__none">(aucune carte)</span>}
                  </div>
                </>
              )}

              {/* Carte révélée. En mode 'reveal' la carte des autres reste cachée
                  (dos) jusqu'à la résolution ; en 'guessCost' elle est publique. */}
              {p.revealed && (
                p.card
                  ? <GameCard card={p.card} size="sm" />
                  : p.hasRevealed
                    ? <div className="ga-hidden" title="Révélation simultanée : visible à la résolution">🂠 a révélé (caché)</div>
                    : <div className="ga-block__none">{data.auto ? "a révélé sa main" : (mode === "guessCost" ? "(deck principal vide)" : "(aucune carte à révéler)")}</div>
              )}
              {!p.revealed && !p.isMe && <div className="ga-block__wait">en attente de {p.pseudo}…</div>}

              {/* Issue de l'embuscade */}
              {(p.outcome || []).map((o, i) => (
                <div key={i} className={`ga-block__out ${o.type === "gain" ? "ga-block__out--good" : ""}`}>
                  {o.type === "corruption" && `🕷️ prend ${o.n} Corruptions`}
                  {o.type === "destroy" && `🗑️ carte détruite : ${o.card}`}
                  {o.type === "gain" && `🎯 deviné ${o.guess} = coût ${o.cost} → gagne ${o.card} en main !`}
                  {o.type === "miss" && `❌ deviné ${o.guess} ≠ coût ${o.cost} (${o.card}) → sous le paquet + 1 Corruption`}
                </div>
              ))}
              {done && (p.outcome || []).length === 0 && <div className="ga-block__ok">✅ épargné</div>}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
