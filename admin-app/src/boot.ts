/**
 * Boot data handed to the SPA by PHP at first paint.
 *
 * Read once, validated once. Everything the shell needs to render without a
 * round-trip lives here: REST root, nonce, capabilities, locale, branding.
 */

export type Capability =
  | 'hiveclerk_manage_agents'
  | 'hiveclerk_view_conversations'
  | 'hiveclerk_manage_conversations'
  | 'hiveclerk_manage_leads'
  | 'hiveclerk_manage_knowledge'
  | 'hiveclerk_manage_integrations'
  | 'hiveclerk_manage_settings';

export type ThemePreference = 'light' | 'dark' | 'auto';

export interface BootData {
  version: string;
  restUrl: string;
  nonce: string;
  adminUrl: string;
  assetsUrl: string;
  locale: string;
  isRtl: boolean;
  capabilities: Record<Capability, boolean>;
  user: {
    id: number;
    name: string;
    email: string;
    avatar: string;
  };
  branding: {
    productName: string;
    whiteLabel: boolean;
  };
  appearance: {
    theme: ThemePreference;
  };
  licence: {
    tier: 'free' | 'pro' | 'business' | 'agency' | 'managed';
    sites: number;
  };
}

declare global {
  interface Window {
    HVC_BOOT?: BootData;
  }
}

/**
 * A boot object that keeps the app renderable when PHP did not supply one.
 *
 * The app must still mount and explain itself rather than showing a blank
 * screen, so every field has a safe default.
 */
const FALLBACK: BootData = {
  version: '0.0.0',
  restUrl: '/wp-json/hiveclerk/v1',
  nonce: '',
  adminUrl: '',
  assetsUrl: '',
  locale: 'en_US',
  isRtl: false,
  capabilities: {
    hiveclerk_manage_agents: false,
    hiveclerk_view_conversations: false,
    hiveclerk_manage_conversations: false,
    hiveclerk_manage_leads: false,
    hiveclerk_manage_knowledge: false,
    hiveclerk_manage_integrations: false,
    hiveclerk_manage_settings: false,
  },
  user: { id: 0, name: '', email: '', avatar: '' },
  branding: { productName: 'Hiveclerk', whiteLabel: false },
  appearance: { theme: 'auto' },
  licence: { tier: 'free', sites: 1 },
};

let cached: BootData | null = null;

export function boot(): BootData {
  if (cached) {
    return cached;
  }

  cached = window.HVC_BOOT ?? FALLBACK;

  return cached;
}

export function can(capability: Capability): boolean {
  return boot().capabilities[capability] === true;
}
