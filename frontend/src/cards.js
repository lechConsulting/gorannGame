// Données des cartes du deck de test (LOTR deck-building).
// Chaque carte : id, nom, type, coût (or), pv (points de victoire, gris),
// texte (effet). `qty` servira plus tard pour les quantités réelles du deck.

export const TYPE_STYLES = {
  Ennemi:     { label: "Ennemi",     accent: "#b1222a", accentSoft: "#e9c3c5" },
  Chance:     { label: "Chance",     accent: "#5c6670", accentSoft: "#d5d9dd" },
  Allié:      { label: "Allié",      accent: "#2f7d46", accentSoft: "#c3e0cd" },
  Manœuvre:   { label: "Manœuvre",   accent: "#7a4a2b", accentSoft: "#e2cdba" },
  Lieu:       { label: "Lieu",       accent: "#a23a9c", accentSoft: "#e6c4e4" },
  Artefact:   { label: "Artefact",   accent: "#2a5da2", accentSoft: "#c1d4ec" },
  Départ:     { label: "Départ",     accent: "#8a7a52", accentSoft: "#e4dcc4" },
  Corruption: { label: "Corruption", accent: "#3a3140", accentSoft: "#cfc6d6" },
};

export const CARDS = [
  {
    id: "chef-orc",
    nom: "Chef Orc",
    type: "Ennemi",
    cout: 1,
    pv: 4,
    texte:
      "+1 Pouvoir et piochez une carte. Embuscade : Mettez un Lieu que vous contrôlez dans votre pile de défausse.",
    qty: 1,
  },
  {
    id: "rage-riviere",
    nom: "Rage de la Rivière",
    type: "Chance",
    cout: 0,
    pv: null,
    texte:
      "Lorsque vous achetez ou gagnez cette carte, jouez-la immédiatement puis détruisez-la. +3 Pouvoir ce tour.",
    qty: 1,
  },
  {
    id: "elendil",
    nom: "Elendil",
    type: "Allié",
    cout: 2,
    pv: 5,
    texte: "+3 Pouvoir.",
    qty: 1,
  },
  {
    id: "recuperez-forces",
    nom: "Récupérez vos Forces",
    type: "Manœuvre",
    cout: 1,
    pv: 2,
    texte:
      "+1 Pouvoir. Si cette carte est la première que vous jouez ce tour, défaussez votre main et piochez quatre cartes.",
    qty: 1,
  },
  {
    id: "mines-moria",
    nom: "Les Mines de la Moria",
    type: "Lieu",
    cout: 1,
    pv: 5,
    texte:
      "Lorsque vous jouez cette carte, laissez-la en face de vous pour le reste de la partie. Permanent : La première fois que vous jouez un Ennemi dans votre tour, piochez une carte.",
    qty: 1,
  },
  {
    id: "cor-gondor",
    nom: "Cor du Gondor",
    type: "Artefact",
    cout: 1,
    pv: 3,
    texte:
      "+1 Pouvoir pour chaque type de carte différent (hors Départ) que vous jouez ou avez joué ce tour.",
    qty: 1,
  },
];
