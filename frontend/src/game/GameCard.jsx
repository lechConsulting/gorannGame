import { motion } from "framer-motion";
import { TYPE_STYLES } from "../cards";
import { emojiFor, artFor } from "../cardArt";

// Carte de jeu (forme API : {code,name,type,cost,pv,text,...}).
// Anatomie corrigée : PV = rond doré (bas gauche), COÛT = rond gris (bas droite).
export default function GameCard({ card, size = "md", onClick, disabled, badge, hideAmbush = false, muted = false, permanentOnly = false }) {
  const style = TYPE_STYLES[card.type] ?? TYPE_STYLES.Chance;
  const emoji = emojiFor(card);
  const { hueShift, runes } = artFor(card);
  const clickable = !!onClick && !disabled;

  let text = card.text ?? "";
  // Lieu en jeu : n'afficher que l'effet Permanent.
  if (permanentOnly) {
    const permIdx = text.indexOf("Permanent");
    if (permIdx >= 0) text = text.slice(permIdx);
  }
  // Mise en évidence :
  //  - Ennemi → clause "Embuscade" en ROUGE (menace subie depuis le sentier).
  //  - autres cartes → clause "Défense" en GRAS (capacité pour éviter une Attaque/Embuscade).
  const isEnemy = card.type === "Ennemi";
  const keyword = isEnemy ? "Embuscade" : "Défense";
  const kIdx = text.indexOf(keyword);
  const mainText = kIdx >= 0 ? text.slice(0, kIdx).trim() : text;
  const highlightText = kIdx >= 0 ? text.slice(kIdx) : "";
  const highlightClass = isEnemy ? "gcard__ambush" : "gcard__defense";
  // On ne masque que l'Embuscade des Ennemis une fois la carte jouée.
  const showHighlight = highlightText && !(isEnemy && hideAmbush);

  return (
    <motion.div
      className={`gcard gcard--${size} ${clickable ? "gcard--clickable" : ""} ${disabled ? "gcard--disabled" : ""} ${muted ? "gcard--muted" : ""}`}
      style={{ "--accent": style.accent, "--accent-soft": style.accentSoft }}
      onClick={clickable ? onClick : undefined}
      layout
      whileHover={clickable ? { y: -8, scale: 1.05 } : undefined}
      initial={{ opacity: 0, scale: 0.92 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ duration: 0.2 }}
    >
      {badge && <div className="gcard__badge">{badge}</div>}
      <div className="gcard__title">{card.name}</div>
      <div className="gcard__art">
        <svg className="gcard__art-bg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true" style={{ filter: `hue-rotate(${hueShift}deg)` }}>
          {runes.map((r, i) => (
            <text key={i} x={r.x} y={r.y} fontSize={r.size} fill="#fff" opacity={r.opacity} transform={`rotate(${r.rot} ${r.x} ${r.y})`}>{r.char}</text>
          ))}
        </svg>
        <span className="gcard__emoji">{emoji}</span>
      </div>
      <div className="gcard__type">{style.label}{card.level ? ` · Niv.${card.level}` : ""}</div>
      {size !== "sm" && (
        <div className="gcard__text">
          {mainText}
          {showHighlight && <span className={highlightClass}>{highlightText}</span>}
        </div>
      )}
      {card.pv != null && <div className="gcard__pv" title="Points de victoire">{card.pv}</div>}
      {card.pv == null && card.attributes?.questVP && <div className="gcard__pv gcard__pv--quest" title="PV variable (Quête)">✱</div>}
      {card.cost != null && <div className="gcard__cost" title="Coût">{card.cost}</div>}
      {card.cost == null && card.attributes?.specialCost && <div className="gcard__cost gcard__cost--quest" title="Coût spécial">✱</div>}
    </motion.div>
  );
}
