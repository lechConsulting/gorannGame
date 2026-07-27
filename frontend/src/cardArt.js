// Génère une illustration "fun" et déterministe pour chaque carte :
//  - un emoji thématique (par code de carte, repli par type)
//  - un décor procédural (teinte + runes dispersées) dérivé du nom
// Aucune image externe : tout est calculé côté client.

// --- Emoji par carte connue (mappé sur le `code`) ---
const EMOJI_BY_CODE = {
  // Génériques
  courage: "🔥", desespoir: "💧", corruption: "🕷️", valeur: "🎖️",
  // Fortunes
  "rage-riviere": "🌊", "decouverte-de-anneau": "💍", "un-cadeau": "🎁",
  "evasion-ailee": "🦅", "jetez-le-dans-le-feu": "🌋",
  // Alliés
  elendil: "👑", pippin: "🍄", merry: "🍺", haldir: "🏹",
  "legolas-vertefeuille": "🏹", "gimli-fils-de-gloin": "🪓", "gandalf-le-gris": "🧙",
  "seigneur-elrond": "🧝", "galadriel-dame-de-lumiere": "✨", "samsagace-gamegie": "🥔",
  "grand-pas-rodeur": "🗡️", "bilbon-saquet": "📖", "boromir-capitaine-tour-blanche": "🛡️",
  "frodon-porteur-anneau": "💍",
  // Manœuvres
  "recuperez-forces": "😴", "conseil-elrond": "🗣️", "toujours-tranchante": "🔪",
  "ne-me-tentez-pas-frodon": "😅", "cetait-proche": "😰", "fuyez-pauvres-fous": "🏃",
  "mon-capitaine-mon-roi": "🫡", "ils-ne-servent-que-des-pintes": "🍺",
  "ceci-est-pour-toi": "🎯", "vous-ne-passerez-pas": "🧙", "seduit-par-anneau": "😵‍💫",
  "espoir-perdure": "🕯️", "tambours-profondeurs": "🥁", "on-entre-pas-en-mordor": "🌋",
  // Artefacts
  "cor-gondor": "📯", dard: "🗡️", "pierres-de-vision": "🔮", "lumiere-earendil": "🌟",
  "broche-elfique": "🍃", "miroir-de-galadriel": "🪞", "pendentif-etoile-du-soir": "💎",
  "torche-enflammee": "🔥", "livre-de-mazarbul": "📕", "feux-artifice-gandalf": "🎆",
  "eclats-de-narsil": "⚔️", "veste-de-mithril": "🦺", "herbe-a-pipe": "🌿",
  "bouclier-de-boromir": "🛡️",
  // Lieux
  "mines-moria": "⛏️", "hobbit-bourg": "🏡", isengard: "🗼", "poney-fringant": "🍻",
  rivendell: "🏞️", lothlorien: "🌳",
  // Ennemis
  "orcs-de-la-moria": "👹", "uruk-hai": "👺", "eclaireurs-uruk-hai": "🐺",
  "grognement-uruk-hai": "😡", "cavaliers-noirs": "🐴", "spectres-de-anneau": "👻",
  "ombres-spectres-anneau": "🌫️", "capitaine-orc-moria": "💀",
  // Archennemis
  nazgul: "🐉", "troupe-uruk-hai": "⚔️", "troll-des-cavernes": "🗿",
  "ulaire-ostea": "🖤", "guetteur-de-leau": "🐙", "fourmiliere-de-la-moria": "🐜",
  "ulaire-nelya": "🖤", saroumane: "🧙‍♂️", "roi-sorcier": "👑", lurtz: "💀",
};

// --- Repli par type de carte ---
const EMOJI_BY_TYPE = {
  Ennemi: "⚔️",
  Allié: "🛡️",
  Lieu: "🏔️",
  Artefact: "💍",
  Chance: "🍀",
  Manœuvre: "🎯",
  Départ: "✨",
  Corruption: "🕷️",
};

// Runes/glyphes façon "vieux grimoire" pour le décor de fond.
const RUNES = "ᚠᚢᚦᚨᚱᚲᚷᚹᚺᚾᛁᛃᛇᛈᛉᛊᛏᛒᛖᛗᛚᛜᛞᛟ".split("");

export function emojiFor(card) {
  return (
    EMOJI_BY_CODE[card.code] ??
    EMOJI_BY_TYPE[card.type] ??
    "✨"
  );
}

// Hash déterministe d'une chaîne (FNV-1a 32 bits).
function hashString(str) {
  let h = 0x811c9dc5;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 0x01000193);
  }
  return h >>> 0;
}

// PRNG déterministe (mulberry32) à partir d'une graine entière.
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/**
 * Calcule les paramètres du décor procédural d'une carte.
 * Retour : { hueShift, runes: [{ char, x, y, size, rot, opacity }] }
 */
export function artFor(card) {
  const seed = hashString(`${card.code}:${card.name}`);
  const rand = mulberry32(seed);

  const hueShift = Math.floor(rand() * 360); // rotation de teinte unique
  const count = 10 + Math.floor(rand() * 8); // 10 à 17 runes

  const runes = Array.from({ length: count }, () => ({
    char: RUNES[Math.floor(rand() * RUNES.length)],
    x: Math.round(rand() * 100),
    y: Math.round(rand() * 100),
    size: 8 + Math.round(rand() * 22),
    rot: Math.round(rand() * 360),
    opacity: 0.06 + rand() * 0.16,
  }));

  return { hueShift, runes };
}
