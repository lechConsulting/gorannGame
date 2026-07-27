import { motion } from "framer-motion";
import { TYPE_STYLES } from "./cards";
import { emojiFor, artFor } from "./cardArt";

// Une carte de jeu stylisée. `index` sert à décaler l'animation de distribution.
export default function Card({ card, index }) {
  const style = TYPE_STYLES[card.type] ?? TYPE_STYLES.Chance;
  const emoji = emojiFor(card);
  const { hueShift, runes } = artFor(card);

  return (
    <motion.div
      className="card"
      style={{ "--accent": style.accent, "--accent-soft": style.accentSoft }}
      layout
      initial={{ opacity: 0, y: 40, rotateY: 90, scale: 0.9 }}
      animate={{ opacity: 1, y: 0, rotateY: 0, scale: 1 }}
      exit={{ opacity: 0, y: -30, scale: 0.9 }}
      transition={{
        duration: 0.5,
        delay: index * 0.09,
        type: "spring",
        stiffness: 120,
        damping: 14,
      }}
      whileHover={{ y: -14, scale: 1.04, transition: { duration: 0.18 } }}
    >
      <div className="card__title">{card.nom}</div>

      <div className="card__art">
        {/* Décor procédural : runes dispersées, unique à chaque carte */}
        <svg
          className="card__art-bg"
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
          aria-hidden="true"
          style={{ filter: `hue-rotate(${hueShift}deg)` }}
        >
          {runes.map((r, i) => (
            <text
              key={i}
              x={r.x}
              y={r.y}
              fontSize={r.size}
              fill="#fff"
              opacity={r.opacity}
              transform={`rotate(${r.rot} ${r.x} ${r.y})`}
            >
              {r.char}
            </text>
          ))}
        </svg>
        <span className="card__art-emoji">{emoji}</span>
      </div>

      <div className="card__type">{style.label}</div>

      <div className="card__text">{card.texte}</div>

      <div className="card__cost" title="Coût d'achat">
        {card.cout}
      </div>
      {card.pv != null && (
        <div className="card__vp" title="Points de victoire">
          {card.pv}
        </div>
      )}
    </motion.div>
  );
}
