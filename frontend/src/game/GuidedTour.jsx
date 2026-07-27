import { useState, useEffect, useRef } from "react";

// Visite guidée : un « projecteur » met en surbrillance chaque zone du plateau
// (repérée par son attribut data-tour) pendant qu'une bulle explicative glisse
// de l'une à l'autre. La dernière étape ouvre la modale « Comment jouer »
// déjà existante (onFinish(true)). « Passer » ferme sans l'ouvrir (onFinish(false)).
//
// Positionnement : UNE seule boucle requestAnimationFrame lit, à chaque frame, la
// zone de l'étape courante (via targetRef, synchronisé sur l'index affiché) et
// écrit la position du projecteur + de la bulle DIRECTEMENT dans le DOM, avec un
// lissage (lerp) qui produit le glissement. Aucun état/écouteur à synchroniser →
// pas de décalage possible entre le contenu affiché et la zone surlignée.
export default function GuidedTour({ steps, onFinish }) {
  const [active, setActive] = useState(null); // étapes réellement présentes à l'écran
  const [index, setIndex] = useState(0);
  const [ready, setReady] = useState(false); // positionné au moins une fois (anti-flash)
  const [place, setPlace] = useState("bottom"); // bulle au-dessus/au-dessous (flèche)

  const spotRef = useRef(null);
  const bubbleRef = useRef(null);
  const targetRef = useRef(null); // sélecteur data-tour de l'étape courante
  const curRef = useRef(null); // rect animé courant du projecteur (lissage)
  const placeRef = useRef("bottom");

  const step = active ? active[index] : null;
  const isLast = active ? index === active.length - 1 : false;

  // Filtrage APRÈS le montage : le DOM du plateau existe alors.
  useEffect(() => {
    const found = steps.filter((s) => document.querySelector(`[data-tour="${s.target}"]`));
    if (found.length === 0) { onFinish(true); return; } // rien à montrer → droit aux règles
    targetRef.current = found[0].target;
    setActive(found);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // À chaque changement d'étape : cible courante + on amène la zone au centre.
  useEffect(() => {
    if (!active) return;
    targetRef.current = active[index].target;
    document.querySelector(`[data-tour="${active[index].target}"]`)
      ?.scrollIntoView({ behavior: "smooth", block: "center" });
  }, [index, active]);

  // Boucle d'animation unique : suit la zone courante en permanence.
  useEffect(() => {
    if (!active) return;
    let raf = 0;
    const lerp = (a, b, t) => a + (b - a) * t;
    const loop = () => {
      const el = document.querySelector(`[data-tour="${targetRef.current}"]`);
      if (el) {
        const r = el.getBoundingClientRect();
        const pad = 8;
        const goal = { top: r.top - pad, left: r.left - pad, width: r.width + pad * 2, height: r.height + pad * 2 };
        let cur = curRef.current;
        if (!cur) { cur = { ...goal }; setReady(true); } // 1re frame : pas de glissement depuis 0
        const k = 0.24;
        cur = {
          top: lerp(cur.top, goal.top, k),
          left: lerp(cur.left, goal.left, k),
          width: lerp(cur.width, goal.width, k),
          height: lerp(cur.height, goal.height, k),
        };
        curRef.current = cur;

        const spot = spotRef.current;
        if (spot) {
          spot.style.top = `${cur.top}px`;
          spot.style.left = `${cur.left}px`;
          spot.style.width = `${cur.width}px`;
          spot.style.height = `${cur.height}px`;
        }

        // Bulle : centrée sur la zone, au-dessus si la zone est plutôt basse.
        const vw = window.innerWidth, vh = window.innerHeight, gap = 18;
        const bw = Math.min(340, vw - 24);
        const pl = r.bottom > vh * 0.62 ? "top" : "bottom";
        const by = pl === "bottom" ? cur.top + cur.height + gap : cur.top - gap;
        let bx = cur.left + cur.width / 2;
        bx = Math.min(Math.max(bw / 2 + 12, bx), vw - bw / 2 - 12);
        const bubble = bubbleRef.current;
        if (bubble) {
          bubble.style.top = `${by}px`;
          bubble.style.left = `${bx}px`;
          bubble.style.transform = pl === "bottom" ? "translateX(-50%)" : "translate(-50%, -100%)";
        }
        if (pl !== placeRef.current) { placeRef.current = pl; setPlace(pl); }
      }
      raf = requestAnimationFrame(loop);
    };
    raf = requestAnimationFrame(loop);
    return () => cancelAnimationFrame(raf);
  }, [active]);

  // Navigation clavier : ← → Échap.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === "Escape") onFinish(false);
      else if (e.key === "ArrowRight") goNext();
      else if (e.key === "ArrowLeft") setIndex((i) => Math.max(0, i - 1));
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [index, active]);

  function goNext() {
    if (!active || index >= active.length - 1) onFinish(true); // dernière étape → les règles
    else setIndex(index + 1);
  }

  if (!step) return <div className="tour" aria-hidden="true"><div className="tour__catch" /></div>;

  return (
    <div className="tour" role="dialog" aria-label="Visite guidée">
      {/* Capte les clics pour neutraliser le plateau pendant la visite */}
      <div className="tour__catch" />

      {/* Le projecteur : glisse d'une zone à l'autre (positionné par la boucle) */}
      <div className="tour__spot" ref={spotRef} style={{ opacity: ready ? 1 : 0 }} />

      {/* La bulle explicative : suit le projecteur, centrée sur la zone */}
      <div
        ref={bubbleRef}
        className={`tour__bubble tour__bubble--${place}`}
        style={{ opacity: ready ? 1 : 0 }}
      >
        <div className="tour__emoji">{step.emoji}</div>
        <h4 className="tour__title">{step.title}</h4>
        <p className="tour__text">{step.text}</p>

        <div className="tour__foot">
          <div className="tour__dots">
            {active.map((_, i) => (
              <span key={i} className={`tour__dot ${i === index ? "tour__dot--on" : ""}`} />
            ))}
          </div>
          <div className="tour__btns">
            <button className="gbtn gbtn--ghost tour__skip" onClick={() => onFinish(false)}>Passer</button>
            {index > 0 && (
              <button className="gbtn gbtn--ghost" onClick={() => setIndex((i) => Math.max(0, i - 1))}>◀</button>
            )}
            <button className="gbtn" onClick={goNext}>{isLast ? "📖 Voir les règles" : "Suivant ▶"}</button>
          </div>
        </div>
      </div>
    </div>
  );
}
