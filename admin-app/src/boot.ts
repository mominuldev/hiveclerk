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

export type Tier = 'free' | 'pro' | 'business' | 'agency' | 'managed';

export type LicenceStatus =
  | 'inactive'
  | 'active'
  | 'expired'
  | 'invalid'
  | 'seat_limit'
  | 'unreachable';

export type FeatureKey =
  | 'crm'
  | 'email_sequences'
  | 'remove_badge'
  | 'white_label'
  | 'multisite';

/**
 * The licence as PHP resolved it.
 *
 * Declared here rather than beside its query hook because the boot
 * payload carries one at first paint — putting the type with the fetch
 * would make the module that runs before any fetch depend on the module
 * that does them.
 */
export interface Licence {
  tier: Tier;
  tier_label: string;
  /** The tier whose entitlements actually apply. Differs once a licence lapses. */
  effective_tier: Tier;
  status: LicenceStatus;
  status_label: string;
  guidance: string | null;
  masked: string | null;
  is_set: boolean;
  sites: number;
  site_limit: number;
  customer: string | null;
  expires_at: string | null;
  checked_at: string | null;
  days_remaining: number | null;
  limits: { clerks: number | null; chunks: number | null };
  features: Record<FeatureKey, boolean>;
}

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
    logoUrl: string | null;
    accent: string | null;
    supportUrl: string | null;
  };
  appearance: {
    theme: ThemePreference;
  };
  /**
   * The whole licence, not just its tier.
   *
   * Every gated screen needs to know which features are in force, and a
   * boot payload carrying only the tier name would make each of them
   * repeat the entitlement arithmetic that PHP has already done — in a
   * second language, where it would drift.
   */
  licence: Licence;
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
  branding: {
    productName: 'Hiveclerk',
    whiteLabel: false,
    logoUrl: null,
    accent: null,
    supportUrl: null,
  },
  appearance: { theme: 'auto' },
  licence: {
    tier: 'free',
    tier_label: 'Free',
    effective_tier: 'free',
    status: 'inactive',
    status_label: 'No licence',
    guidance: null,
    masked: null,
    is_set: false,
    sites: 1,
    site_limit: 1,
    customer: null,
    expires_at: null,
    checked_at: null,
    days_remaining: null,
    limits: { clerks: 1, chunks: 200 },
    features: {
      crm: false,
      email_sequences: false,
      remove_badge: false,
      white_label: false,
      multisite: false,
    },
  },
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
