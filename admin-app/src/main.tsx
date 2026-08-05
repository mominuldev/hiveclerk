import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { boot } from './boot';
import { resolveTheme } from './hooks/useTheme';
import './styles/tailwind.css';

const container = document.getElementById('hvc-root');

if (container) {
  // Resolve and stamp the theme before first paint so the shell never
  // flashes the wrong background. 'auto' still resolves to a concrete value
  // here because it depends on wp-admin's colour scheme.
  document.documentElement.setAttribute(
    'data-theme',
    resolveTheme(boot().appearance.theme)
  );

  if (boot().isRtl) {
    container.setAttribute('dir', 'rtl');
  }

  createRoot(container).render(
    <StrictMode>
      <App />
    </StrictMode>
  );
}
