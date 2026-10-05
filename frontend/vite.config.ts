import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import {defineConfig} from 'vite';

export default defineConfig(() => {
  return {
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, '.'),
      },
      // Forces every import of react/react-dom — no matter which module graph
      // entry point pulls it in (a lazy-loaded route, an optimized/pre-bundled
      // dependency, a fresh module Vite re-fetches after HMR invalidation) —
      // to resolve to the exact same module instance. Without this, repeated
      // dev-server restarts/HMR churn can leave two separate copies of React
      // loaded at once; a component rendered from the "wrong" copy then calls
      // hooks against a React whose internal dispatcher is null, which is
      // exactly the "Invalid hook call" / "Cannot read properties of null
      // (reading 'useX')" error this project kept hitting after edits.
      dedupe: ['react', 'react-dom'],
    },
    optimizeDeps: {
      // Pins react/react-dom into the SAME pre-bundle Vite generates once at
      // startup, instead of letting the dependency optimizer decide lazily
      // per-request — the other half of the fix above: dedupe alone still
      // depends on the optimizer having already bundled them consistently.
      include: ['react', 'react-dom', 'react-dom/client'],
    },
    build: {
      rollupOptions: {
        output: {
          // Split only PURE third-party libraries out of the entry chunk.
          //
          // Why this is safe: every group below is a leaf dependency that never
          // imports application code, so no chunk can end up waiting on another
          // in a broken order -- the emitted <script type="module"> graph is
          // acyclic and Rollup orders the imports itself.
          //
          // react/react-dom/scheduler/react-router are grouped TOGETHER on
          // purpose: react-dom and react-router both require the exact same
          // React instance, and separating them into sibling chunks is the
          // classic way to end up with two React copies / a null hook
          // dispatcher (the same failure mode `resolve.dedupe` above guards
          // against). One chunk = one instance, guaranteed.
          //
          // `motion` is the biggest remaining lib in the entry chunk (it is
          // imported by 51 components, including eagerly-rendered homepage
          // sections), so it cannot be lazy-loaded away -- but giving it its
          // own chunk lets the browser fetch it in parallel with app code and
          // keeps it cached across app deploys.
          //
          // recharts is ALREADY isolated (it is imported only by the lazily
          // loaded InstructorStatistics route); it is named here so it stays
          // isolated if a second component ever imports it, instead of being
          // silently duplicated or hoisted into the entry chunk.
          manualChunks(id: string) {
            if (!id.includes('node_modules')) return undefined;
            const normalized = id.replace(/\\/g, '/');
            if (/\/node_modules\/(react|react-dom|scheduler|react-router)\//.test(normalized)) {
              return 'react-vendor';
            }
            if (/\/node_modules\/(motion|motion-dom|motion-utils|framer-motion)\//.test(normalized)) {
              return 'motion-vendor';
            }
            if (/\/node_modules\/(recharts|d3-[^/]+|victory-vendor|decimal\.js-light|internmap)\//.test(normalized)) {
              return 'charts-vendor';
            }
            return undefined;
          },
        },
      },
    },
    server: {
      // HMR is disabled in AI Studio via DISABLE_HMR env var.
      // Do not modifyâfile watching is disabled to prevent flickering during agent edits.
      hmr: process.env.DISABLE_HMR !== 'true',
      // Disable file watching when DISABLE_HMR is true to save CPU during agent edits.
      // `public/` is excluded from the watcher regardless: static assets dropped
      // there (e.g. freshly-saved illustration images) are briefly held by
      // Windows (antivirus/indexer) right after being written, and Vite's
      // Node fs.watch throws an uncaught EBUSY that crashes the whole dev
      // server — this has taken the server down twice from new images in
      // public/branding/. Static files under public/ don't need HMR anyway
      // (they're served as a passthrough, not part of the module graph).
      watch: process.env.DISABLE_HMR === 'true' ? null : { ignored: ['**/public/**'] },
      // Dev-only reverse proxy to the PHP backend.
      //
      // src/services/api.ts deliberately uses a RELATIVE baseURL ('/api/v1')
      // so that in production the SPA and the API are same-origin and no
      // proxy/CORS is involved at all. In dev, Vite serves the SPA on :3000
      // while the PHP built-in server runs on :8000, so without this proxy
      // every request would hit Vite itself and return index.html (or a 404),
      // never reaching PHP.
      //
      // Forwarding here (rather than switching api.ts to an absolute
      // http://localhost:8000 base URL) keeps the browser's origin identical
      // for page and API, which means:
      //   - no cross-origin preflight on every mutating request,
      //   - the session cookie (SESSION_SAMESITE=Lax) and the non-HttpOnly
      //     CSRF cookie `sanad_csrf` are treated as first-party and are both
      //     sent and readable -- a cross-origin setup would need
      //     SameSite=None + Secure, which does not work over plain http.
      // It also means dev and prod exercise the exact same code path.
      //
      // Backend port is overridable via VITE_BACKEND_URL for anyone running
      // PHP somewhere else; 8000 is the documented default.
      proxy: {
        '/api': {
          target: process.env.VITE_BACKEND_URL || 'http://localhost:8000',
          changeOrigin: true,
        },
      },
    },
  };
});
