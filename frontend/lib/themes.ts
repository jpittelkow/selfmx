/**
 * Theme registry — defines all available color themes.
 *
 * Each theme is a CSS file in `frontend/styles/themes/<id>.css` that sets
 * CSS custom properties under `[data-theme="<id>"]` and `[data-theme="<id>"].dark`.
 *
 * To add a new theme, see: docs/ai/recipes/add-theme.md
 */

export interface ThemePreviewColors {
  primary: string;
  secondary: string;
  background: string;
  foreground: string;
}

export interface ThemeDefinition {
  /** Unique ID — matches the CSS `[data-theme="<id>"]` selector and CSS filename */
  id: string;
  /** Display name shown in the theme picker */
  name: string;
  /** Short description for the picker tooltip */
  description: string;
  /** Hex preview colors for the theme picker swatches */
  preview: {
    light: ThemePreviewColors;
    dark: ThemePreviewColors;
  };
}

/** All registered themes. Order determines display order in the picker. */
export const themes: ThemeDefinition[] = [
  {
    id: "default",
    name: "Default",
    description: "Clean neutral slate",

    preview: {
      light: {
        primary: "#1e293b",
        secondary: "#f1f5f9",
        background: "#ffffff",
        foreground: "#0f172a",
      },
      dark: {
        primary: "#f1f5f9",
        secondary: "#1e293b",
        background: "#0f172a",
        foreground: "#f1f5f9",
      },
    },
  },
  {
    id: "bubblegum",
    name: "Bubblegum",
    description: "Hot pink on cream — playful and loud",

    preview: {
      light: {
        primary: "#da0b78",
        secondary: "#eea8c8",
        background: "#ebbaca",
        foreground: "#240612",
      },
      dark: {
        primary: "#ff6ec7",
        secondary: "#3d0f2a",
        background: "#1a0812",
        foreground: "#fce4f0",
      },
    },
  },
  {
    id: "cyberpunk",
    name: "Cyberpunk",
    description: "Neon cyan on deep black — electric and futuristic",

    preview: {
      light: {
        primary: "#00a1c2",
        secondary: "#a3d9e3",
        background: "#c8e8ed",
        foreground: "#011718",
      },
      dark: {
        primary: "#00e5ff",
        secondary: "#0a2a30",
        background: "#030d10",
        foreground: "#d0f8ff",
      },
    },
  },
  {
    id: "dracula",
    name: "Dracula",
    description: "Purple and green on charcoal — the iconic dev theme",

    preview: {
      light: {
        primary: "#9b5cf5",
        secondary: "#c3aee0",
        background: "#cec8e4",
        foreground: "#191c24",
      },
      dark: {
        primary: "#bd93f9",
        secondary: "#2d2640",
        background: "#282a36",
        foreground: "#f8f8f2",
      },
    },
  },
  {
    id: "forest",
    name: "Forest",
    description: "Deep emerald on parchment — earthy and grounded",

    preview: {
      light: {
        primary: "#10600f",
        secondary: "#a8cfa8",
        background: "#d4ddb8",
        foreground: "#0a1e0a",
      },
      dark: {
        primary: "#34d399",
        secondary: "#14332a",
        background: "#0a1f14",
        foreground: "#d1fae5",
      },
    },
  },
  {
    id: "sunset",
    name: "Sunset",
    description: "Burnt orange on warm ivory — cozy and bold",

    preview: {
      light: {
        primary: "#b84a09",
        secondary: "#e4c08b",
        background: "#f0d4ab",
        foreground: "#290f0a",
      },
      dark: {
        primary: "#fb923c",
        secondary: "#3b1a08",
        background: "#1a0e04",
        foreground: "#fff1e6",
      },
    },
  },
  {
    id: "ocean",
    name: "Ocean",
    description: "Deep navy and bright blue — nautical and crisp",

    preview: {
      light: {
        primary: "#1445d0",
        secondary: "#97bade",
        background: "#bdd0ea",
        foreground: "#081228",
      },
      dark: {
        primary: "#60a5fa",
        secondary: "#172554",
        background: "#050e1f",
        foreground: "#dbeafe",
      },
    },
  },
  {
    id: "rose",
    name: "Rose",
    description: "Deep crimson on blush — romantic and refined",

    preview: {
      light: {
        primary: "#9e1435",
        secondary: "#e09da8",
        background: "#e8b8c4",
        foreground: "#220b10",
      },
      dark: {
        primary: "#fb7185",
        secondary: "#4c0519",
        background: "#1a0508",
        foreground: "#ffe4e6",
      },
    },
  },
  {
    id: "lavender",
    name: "Lavender",
    description: "Rich purple on soft lilac — dreamy and elegant",

    preview: {
      light: {
        primary: "#6125cc",
        secondary: "#c0a5e2",
        background: "#d0bde8",
        foreground: "#180b2a",
      },
      dark: {
        primary: "#a78bfa",
        secondary: "#2e1065",
        background: "#0f0720",
        foreground: "#ede9fe",
      },
    },
  },
  {
    id: "midnight",
    name: "Midnight",
    description: "Electric indigo on pitch black — high contrast hacker",

    preview: {
      light: {
        primary: "#0a1aa4",
        secondary: "#99a6d9",
        background: "#c0c3ea",
        foreground: "#080c21",
      },
      dark: {
        primary: "#a5b4fc",
        secondary: "#1e1b4b",
        background: "#050414",
        foreground: "#e0e7ff",
      },
    },
  },
  {
    id: "mono",
    name: "Mono",
    description: "Pure black and white — stark, typographic, no nonsense",

    preview: {
      light: {
        primary: "#000000",
        secondary: "#d1d1d1",
        background: "#e6e6e6",
        foreground: "#000000",
      },
      dark: {
        primary: "#ffffff",
        secondary: "#262626",
        background: "#000000",
        foreground: "#ffffff",
      },
    },
  },
  {
    id: "coffee",
    name: "Coffee",
    description: "Rich brown on warm cream — artisan cafe vibes",

    preview: {
      light: {
        primary: "#762f18",
        secondary: "#cdb68b",
        background: "#dfc89e",
        foreground: "#210d05",
      },
      dark: {
        primary: "#d4a574",
        secondary: "#3d2815",
        background: "#1a0f08",
        foreground: "#f5e6d3",
      },
    },
  },
  {
    id: "catppuccin",
    name: "Catppuccin",
    description: "Warm pastels on creamy bases — the beloved dev palette",

    preview: {
      light: {
        primary: "#8839ef",
        secondary: "#dbd5e6",
        background: "#eff1f5",
        foreground: "#4c4f69",
      },
      dark: {
        primary: "#cba6f7",
        secondary: "#313244",
        background: "#1e1e2e",
        foreground: "#c6d0f5",
      },
    },
  },
  {
    id: "nord",
    name: "Nord",
    description: "Arctic cool blue-gray — Nordic polar frost",

    preview: {
      light: {
        primary: "#5e81ac",
        secondary: "#d8dee9",
        background: "#eceff4",
        foreground: "#2e3440",
      },
      dark: {
        primary: "#5e81ac",
        secondary: "#3b4252",
        background: "#2e3440",
        foreground: "#d8dee9",
      },
    },
  },
  {
    id: "solarized",
    name: "Solarized",
    description: "Precision-engineered palette with signature yellow accent",

    preview: {
      light: {
        primary: "#b58900",
        secondary: "#ddd0a6",
        background: "#fdf6e3",
        foreground: "#073642",
      },
      dark: {
        primary: "#b58900",
        secondary: "#194854",
        background: "#073642",
        foreground: "#fdf6e3",
      },
    },
  },
  {
    id: "sakura",
    name: "Sakura",
    description: "Soft cherry blossom pinks — serene and elegant",

    preview: {
      light: {
        primary: "#c03b6b",
        secondary: "#e8d2da",
        background: "#f7eff2",
        foreground: "#382028",
      },
      dark: {
        primary: "#d97da3",
        secondary: "#32222a",
        background: "#231820",
        foreground: "#e6d0da",
      },
    },
  },
  {
    id: "amber",
    name: "Amber",
    description: "Rich golden amber — luxury editorial warmth",

    preview: {
      light: {
        primary: "#d97706",
        secondary: "#e4d6c0",
        background: "#f5f0e8",
        foreground: "#2b2114",
      },
      dark: {
        primary: "#e5a220",
        secondary: "#2e2418",
        background: "#201a10",
        foreground: "#e8dbc6",
      },
    },
  },
  {
    id: "slate",
    name: "Slate",
    description: "Cool blue-gray professional — modern SaaS refined",

    preview: {
      light: {
        primary: "#2563a8",
        secondary: "#e1e5eb",
        background: "#f5f7fa",
        foreground: "#1e293b",
      },
      dark: {
        primary: "#5b8fd4",
        secondary: "#262f3d",
        background: "#171d28",
        foreground: "#e2e8f0",
      },
    },
  },
];

/** Look up a theme by ID. Returns undefined if not found. */
export function getThemeById(id: string): ThemeDefinition | undefined {
  return themes.find((t) => t.id === id);
}

/** The default theme ID used when no theme is selected. */
export const DEFAULT_THEME_ID = "default";

/** localStorage key for persisting the user's color theme choice. */
export const COLOR_THEME_STORAGE_KEY = "sourdough-color-theme";
