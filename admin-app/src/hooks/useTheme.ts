import { useCallback, useEffect, useState } from 'react';
import { boot, type ThemePreference } from '@/boot';

const ATTRIBUTE = 'data-theme';

/**
 * Relative luminance of a CSS colour, or null if it cannot be read.
 */
function luminance(color: string): number | null {
  const match = color.match(/rgba?\(([^)]+)\)/);
  if (!match?.[1]) return null;

  const parts = match[1].split(/[,\s/]+/).filter(Boolean).map(Number);
  const [r, g, b, a = 1] = parts;

  if (r === undefined || g === undefined || b === undefined) return null;
  // A transparent element tells us nothing about the chrome behind it.
  if (a < 0.5) return null;

  const channel = (v: number) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };

  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/**
 * Whether WordPress is running a dark admin colour scheme.
 *
 * Measured from the admin menu's actual background rather than matched
 * against a list of scheme names. WordPress ships nine schemes and sites add
 * their own, so measuring the chrome is the only approach that stays correct
 * for schemes we have never heard of.
 */
function wpAdminIsDark(): boolean | null {
  const menu =
    document.getElementById('adminmenuback') ??
    document.getElementById('adminmenu') ??
    document.getElementById('adminmenuwrap');

  if (!menu) return null;

  const value = luminance(getComputedStyle(menu).backgroundColor);

  return value === null ? null : value < 0.2;
}

/**
 * Resolve a preference to the theme actually rendered.
 *
 * Order, per the design system: explicit choice, then the WordPress admin
 * colour scheme, then the operating system. The middle step matters — an app
 * in light mode sitting inside a dark wp-admin renders near-black text on a
 * dark chrome and reads as broken.
 */
function resolve(preference: ThemePreference): 'light' | 'dark' {
  if (preference !== 'auto') return preference;

  const wp = wpAdminIsDark();
  if (wp !== null) return wp ? 'dark' : 'light';

  return window.matchMedia('(prefers-color-scheme: dark)').matches
    ? 'dark'
    : 'light';
}

/**
 * Stamp the resolved theme on <html>.
 *
 * 'auto' still writes a concrete value, because the resolution depends on
 * wp-admin's chrome which CSS alone cannot inspect.
 */
function apply(theme: 'light' | 'dark'): void {
  document.documentElement.setAttribute(ATTRIBUTE, theme);
}

export function useTheme() {
  const [preference, setPreference] = useState<ThemePreference>(
    () => boot().appearance.theme
  );
  const [resolved, setResolved] = useState<'light' | 'dark'>(() =>
    resolve(boot().appearance.theme)
  );

  useEffect(() => {
    const next = resolve(preference);
    setResolved(next);
    apply(next);
  }, [preference]);

  // Follow the OS only while the user has not chosen explicitly.
  useEffect(() => {
    if (preference !== 'auto') return undefined;

    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => {
      const next = resolve('auto');
      setResolved(next);
      apply(next);
    };

    query.addEventListener('change', onChange);
    return () => query.removeEventListener('change', onChange);
  }, [preference]);

  const setTheme = useCallback((next: ThemePreference) => {
    setPreference(next);
    // Persistence to user meta lands with the settings endpoint in Sprint 2.
  }, []);

  const toggle = useCallback(() => {
    setPreference(resolve(preference) === 'dark' ? 'light' : 'dark');
  }, [preference]);

  return { preference, resolved, setTheme, toggle };
}

export { resolve as resolveTheme };
