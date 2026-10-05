import { createContext, useCallback, useContext, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { CheckCircle2, XCircle, X } from 'lucide-react';

export type CenteredNotificationType = 'success' | 'error';

interface CenteredNotificationState {
  message: string;
  type: CenteredNotificationType;
  /** Bumped on every call so re-triggering the same message still restarts the auto-dismiss timer and animation. */
  key: number;
}

interface CenteredNotificationContextType {
  /** Shows a small centered popup confirming an add/edit/delete/save action succeeded or failed. */
  notify: (message: string, type: CenteredNotificationType) => void;
}

const CenteredNotificationContext = createContext<CenteredNotificationContextType | undefined>(undefined);

const AUTO_DISMISS_MS = 3200;

/**
 * The instructor section's shared "did it work?" signal for add/edit/delete/
 * save actions — a small modal-style popup centered in the viewport, distinct
 * from the corner toast (ToastContext) used for validation/permission errors
 * elsewhere in the app. Mounted once in InstructorSystem.tsx so every page
 * under /instructor can call useCenteredNotification().notify(...) instead of
 * maintaining its own local feedback banner.
 */
export function CenteredNotificationProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<CenteredNotificationState | null>(null);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const dismiss = useCallback(() => {
    setState(null);
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const notify = useCallback((message: string, type: CenteredNotificationType) => {
    if (timerRef.current) clearTimeout(timerRef.current);

    setState((prev) => ({ message, type, key: (prev?.key ?? 0) + 1 }));

    timerRef.current = setTimeout(() => {
      setState(null);
      timerRef.current = null;
    }, AUTO_DISMISS_MS);
  }, []);

  const isSuccess = state?.type === 'success';

  return (
    <CenteredNotificationContext.Provider value={{ notify }}>
      {children}

      <AnimatePresence>
        {state && (
          <div
            className="fixed inset-0 z-[95] flex items-center justify-center p-4 pointer-events-none"
            dir="rtl"
          >
            <motion.div
              key={state.key}
              role="alert"
              aria-live="assertive"
              initial={{ opacity: 0, scale: 0.9, y: 10 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0, scale: 0.92, y: 6 }}
              transition={{ duration: 0.22, ease: 'easeOut' }}
              className="pointer-events-auto w-full max-w-xs rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-2xl overflow-hidden"
            >
              <div className="flex flex-col items-center text-center gap-3 px-6 pt-8 pb-6">
                <div
                  className={
                    isSuccess
                      ? 'w-16 h-16 rounded-full bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center'
                      : 'w-16 h-16 rounded-full bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center'
                  }
                >
                  {isSuccess ? (
                    <CheckCircle2 className="w-9 h-9" strokeWidth={1.75} />
                  ) : (
                    <XCircle className="w-9 h-9" strokeWidth={1.75} />
                  )}
                </div>

                <p className="text-sm sm:text-base font-bold text-slate-800 dark:text-slate-100 leading-relaxed">
                  {state.message}
                </p>
              </div>

              <button
                type="button"
                onClick={dismiss}
                aria-label="إغلاق"
                className="absolute top-3 left-3 p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>

              <motion.div
                key={`bar-${state.key}`}
                initial={{ scaleX: 1 }}
                animate={{ scaleX: 0 }}
                transition={{ duration: AUTO_DISMISS_MS / 1000, ease: 'linear' }}
                style={{ transformOrigin: 'right' }}
                className={isSuccess ? 'h-1 bg-emerald-500/70' : 'h-1 bg-rose-500/70'}
              />
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </CenteredNotificationContext.Provider>
  );
}

export function useCenteredNotification() {
  const context = useContext(CenteredNotificationContext);
  if (!context) {
    throw new Error('useCenteredNotification must be used within a CenteredNotificationProvider');
  }
  return context;
}
