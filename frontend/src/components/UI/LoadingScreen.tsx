import PlatformLogo from '../PlatformLogo';

export interface LoadingScreenProps {
  /** Sub-line under the platform name. Defaults to the generic page-transition wording. */
  message?: string;
  /**
   * `true` (default) paints a full viewport screen — the correct mode for a
   * route transition or an auth check, where nothing else is on screen.
   * `false` fills only the space it is given, for a panel-level wait.
   */
  fullScreen?: boolean;
}

/**
 * THE full-page loading screen for route transitions and auth verification.
 *
 * There is exactly one of these on purpose: every genuine "the next screen is
 * not ready yet" moment (the router's Suspense fallback, ProtectedRoute's
 * permission check) renders the same composition, so a visitor never sees two
 * different-looking waits within one navigation. Small inline waits — a button
 * label, a load-more spinner — deliberately do NOT use this; they are not page
 * transitions and blowing them up to full height would be a regression.
 *
 * Visual language is the platform's own: the branding logo, the blue→indigo
 * gradient of the public pages, soft blurred atmosphere glows, and an RTL
 * Arabic caption. Dark mode is handled by the same `dark:` tokens the rest of
 * the app uses, so it follows the site theme with no JS.
 */
export default function LoadingScreen({
  message = 'جاري تحميل الصفحة...',
  fullScreen = true,
}: LoadingScreenProps) {
  return (
    <div
      dir="rtl"
      role="status"
      aria-live="polite"
      aria-busy="true"
      className={`${
        fullScreen ? 'min-h-screen' : 'min-h-[60vh] w-full'
      } relative flex flex-col items-center justify-center gap-7 overflow-hidden bg-slate-50 dark:bg-slate-950 px-6 py-16 select-none transition-colors duration-300`}
    >
      {/* Atmosphere glows — the same pair the public pages open with */}

      {/* Logo inside a rotating gradient ring */}
      <div className="relative z-10 flex items-center justify-center">
        <div className="absolute w-32 h-32 rounded-full bg-[conic-gradient(from_0deg,transparent_0deg,transparent_210deg,var(--color-blue-500)_320deg,var(--color-indigo-500)_360deg)] animate-spin [animation-duration:1.4s]" />
        <div className="absolute w-32 h-32 rounded-full border-2 border-blue-500/15 dark:border-blue-400/15" />
        <div className="relative w-[7.25rem] h-[7.25rem] rounded-full bg-white dark:bg-slate-900 shadow-xl flex items-center justify-center">
          <PlatformLogo className="w-14 h-14 object-contain animate-pulse [animation-duration:2s]" />
        </div>
      </div>

      {/* Wordmark + message */}
      <div className="relative z-10 text-center space-y-2">
        <h2 className="text-2xl font-black text-blue-600 dark:text-blue-400">
          منصة سند التعليمية
        </h2>
        <p className="text-sm font-bold text-slate-500 dark:text-slate-400">{message}</p>
      </div>

      {/* Indeterminate progress rail */}
      <div className="relative z-10 w-56 h-1.5 rounded-full bg-slate-200/80 dark:bg-slate-800 overflow-hidden">
        <div className="h-full w-1/3 rounded-full bg-blue-600 animate-[loading-rail_1.3s_ease-in-out_infinite]" />
      </div>

      {/* Local keyframes — scoped here so no global stylesheet has to change */}
      <style>{`
        @keyframes loading-rail {
          0%   { transform: translateX(120%); }
          100% { transform: translateX(-260%); }
        }
      `}</style>
    </div>
  );
}
