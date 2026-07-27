import GameCard from "./GameCard";

// Alerte « Embuscade de Groupe » : montrée à TOUS les joueurs. Affiche le texte
// de l'embuscade et un bloc par joueur — chacun fait son action (révéler une
// carte), et une fois résolue, on voit qui a subi quoi.
export default function GroupAmbushAlert({ data, busy, onReveal, onClose }) {
  const { name, text, done, players, myEid, myOptions } = data;
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

              {/* Mon action : révéler une carte */}
              {p.isMe && !p.revealed && myEid && (
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

              {/* Carte révélée. Révélation SIMULTANÉE : la carte des autres reste
                  cachée (dos) jusqu'à la résolution — on ne peut pas s'adapter. */}
              {p.revealed && (
                p.card
                  ? <GameCard card={p.card} size="sm" />
                  : p.hasRevealed
                    ? <div className="ga-hidden" title="Révélation simultanée : visible à la résolution">🂠 a révélé (caché)</div>
                    : <div className="ga-block__none">{data.auto ? "a révélé sa main" : "(aucune carte à révéler)"}</div>
              )}
              {!p.revealed && !p.isMe && <div className="ga-block__wait">en attente de {p.pseudo}…</div>}

              {/* Issue de l'embuscade */}
              {(p.outcome || []).map((o, i) => (
                <div key={i} className="ga-block__out">
                  {o.type === "corruption" ? `🕷️ prend ${o.n} Corruptions` : `🗑️ carte détruite : ${o.card}`}
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
