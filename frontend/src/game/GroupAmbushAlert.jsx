import GameCard from "./GameCard";

// Alerte « Embuscade de Groupe » (montrée à TOUS). Modes d'action du viewer :
//  - groupReveal          : révèle une carte de sa main (révélation simultanée) ;
//  - guessCost (Nelya)    : devine le coût du dessus du deck (gagnée si juste) ;
//  - nameCost (Lurtz)     : nomme un coût ; carte de la main révélée au hasard,
//                           si ≠ → défausse toute sa main ;
//  - distributeCorruption : (Roi Sorcier) le vainqueur place N Corruptions.
export default function GroupAmbushAlert({ data, busy, onReveal, onGuess, onDistribute, onClose }) {
  const { name, text, done, mode, players, myEid, myOp, myOptions, myCount } = data;
  return (
    <div className="modal-overlay">
      <div className="modal ga-alert">
        <div className="modal__head">
          <h3>⚔️ Embuscade de Groupe — {name}</h3>
          {done && <button className="gbtn gbtn--ghost" onClick={onClose}>Fermer</button>}
        </div>
        <p className="ga-alert__text">{text}</p>

        {/* Répartition des Corruptions (Roi Sorcier) : action globale du vainqueur */}
        {myEid && myOp === "distributeCorruption" && (
          <div className="ga-distribute">
            <div className="ga-block__todo">👉 Répartis {myCount} Corruption(s) — clique un joueur à chaque fois :</div>
            <div className="ga-guess">
              {players.map((p) => (
                <button key={p.seat} className="gbtn gbtn--danger" disabled={busy} onClick={() => onDistribute(myEid, p.seat)}>
                  🕷️ {p.kind === "bot" ? "🤖 " : ""}{p.pseudo}
                </button>
              ))}
            </div>
          </div>
        )}

        <div className="ga-grid">
          {players.map((p) => (
            <div key={p.seat} className={`ga-block ${p.isMe ? "ga-block--me" : ""}`}>
              <div className="ga-block__n">
                {p.kind === "bot" ? "🤖 " : "🧑 "}{p.pseudo}{p.isMe ? " · toi" : ""}
              </div>

              {/* Mon action : deviner / nommer un coût */}
              {p.isMe && !p.revealed && myEid && (myOp === "guessCost" || myOp === "nameCost") && (
                <>
                  <div className="ga-block__todo">
                    {myOp === "guessCost"
                      ? "👉 Devine le coût de la carte du dessus du deck :"
                      : "👉 Nomme un coût (une carte de ta main sera révélée au hasard) :"}
                  </div>
                  <div className="ga-guess">
                    {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                      <button key={n} className="gbtn ga-guess__btn" disabled={busy} onClick={() => onGuess(myEid, n)}>{n}</button>
                    ))}
                  </div>
                </>
              )}
              {/* Mon action : révéler une carte de ma main */}
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

              {/* Carte révélée (cachée pour les autres tant que non résolu en mode 'reveal') */}
              {p.revealed && (
                p.card
                  ? <GameCard card={p.card} size="sm" />
                  : p.hasRevealed
                    ? <div className="ga-hidden" title="Révélation simultanée : visible à la résolution">🂠 a révélé (caché)</div>
                    : <div className="ga-block__none">{data.auto ? "a révélé sa main" : (mode === "guessCost" || mode === "nameCost" ? "(deck/main vide)" : "(aucune carte à révéler)")}</div>
              )}
              {!p.revealed && !p.isMe && <div className="ga-block__wait">en attente de {p.pseudo}…</div>}

              {/* Issues */}
              {(p.outcome || []).map((o, i) => (
                <div key={i} className={`ga-block__out ${(o.type === "gain" || o.type === "safe") ? "ga-block__out--good" : ""}`}>
                  {o.type === "corruption" && `🕷️ prend ${o.n} Corruption(s)`}
                  {o.type === "destroy" && `🗑️ détruit : ${o.card}`}
                  {o.type === "discard" && `🗑️ défausse ${o.n}${o.card ? ` : ${o.card}` : ""}`}
                  {o.type === "discardExtra" && `🗑️ défausse ${o.n} carte(s) de plus (coût le + élevé)`}
                  {o.type === "gain" && `🎯 deviné ${o.guess} = coût ${o.cost} → gagne ${o.card} en main !`}
                  {o.type === "miss" && `❌ deviné ${o.guess} ≠ coût ${o.cost} (${o.card}) → sous le paquet + 1 Corruption`}
                  {o.type === "safe" && `🛡️ nommé ${o.guess} = coût ${o.cost} (${o.card}) → main épargnée`}
                  {o.type === "discardHand" && `💥 nommé ${o.guess} ≠ coût ${o.cost} (${o.card}) → défausse toute sa main (${o.n})`}
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
