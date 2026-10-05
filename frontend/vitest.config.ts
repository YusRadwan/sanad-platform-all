import {defineConfig} from 'vitest/config';

/**
 * Vitest lives in its OWN config file rather than inside vite.config.ts on
 * purpose.
 *
 * vite.config.ts is the file the running dev server watches; editing it forces
 * a full dev-server restart. It also carries the dev-only `server.proxy` to the
 * PHP backend and the production `build.rollupOptions.manualChunks` split,
 * none of which a unit-test run should have to load. Vitest picks
 * vitest.config.ts ahead of vite.config.ts automatically, so the two stay
 * independent.
 *
 * Scope: these are UNIT tests for pure functions only — no dev server, no PHP
 * backend and no network are required, which is what makes them runnable in CI
 * (.github/workflows/ci.yml).
 */
export default defineConfig({
  test: {
    // jsdom (not 'node') because the functions under test touch browser
    // globals: `new URL(...)` plus `window.open` in safeOpen().
    environment: 'jsdom',
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
});
