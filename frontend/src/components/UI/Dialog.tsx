import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

export type DialogSize = 'sm' | 'md' | 'lg' | 'xl';

export interface DialogProps {
  open: boolean;
  /** Called on backdrop click, Escape, and the × button. */
  onClose: () => void;
  title?: ReactNode;
  description?: ReactNode;
  icon?: LucideIcon;
  /** Tint of the header icon chip. @default 'brand' */
  tone?: 'brand' | 'danger' | 'warning' | 'success';
  /** @default 'md' */
  size?: DialogSize;
  /** Footer slot, typically a right-aligned pair of Buttons. */
  footer?: ReactNode;
  /** Hide the × button (e.g. for a blocking flow). @default false */
  hideCloseButton?: boolean;
  /** Disable dismissing via backdrop/Escape. @default false */
  disableDismiss?: boolean;
  className?: string;
  children?: ReactNode;
}

const SIZES: Record<DialogSize, string> = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
};

const TONES = {
  brand: 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400',
  danger: 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
  warning: 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
  success: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
} as const;

/**
 * The platform's one modal primitive: animated with `motion`'s AnimatePresence,
 * dismissible via backdrop click and Escape, and body-scroll-locking while open.
 *
 * Replaces the hand-rolled `fixed inset-0 z-50` blocks currently inlined in
 * BroadcastNotifications, LoginActivity, ManageTeachers, ManageUsers,
 * RefundManagement, AnnouncementsManagement and CourseManagement.
 */
export function Dialog({
  open,
  onClose,
  title,
  description,
  icon: Icon,
  tone = 'brand',
  size = 'md',
  footer,
  hideCloseButton = false,
  disableDismiss = false,
  className,
  children,
}: DialogProps) {
  useEffect(() => {
    if (!open) return;

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !disableDismiss) onClose();
    };
    document.addEventListener('keydown', onKeyDown);

    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [open, onClose, disableDismiss]);

  return (
    <AnimatePresence>
      {open && (
        <div className="fixed inset-0 z-[90] flex items-center justify-center p-4 sm:p-6" dir="rtl">
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            transition={{ duration: 0.18 }}
            onClick={disableDismiss ? undefined : onClose}
            className="absolute inset-0 bg-slate-950/45 dark:bg-slate-950/70 backdrop-blur-sm"
            aria-hidden="true"
          />

          <motion.div
            role="dialog"
            aria-modal="true"
            initial={{ opacity: 0, scale: 0.96, y: 12 }}
            animate={{ opacity: 1, scale: 1, y: 0 }}
            exit={{ opacity: 0, scale: 0.96, y: 8 }}
            transition={{ duration: 0.2, ease: 'easeOut' }}
            className={cn(
              'relative w-full max-h-[90vh] flex flex-col',
              'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800',
              'rounded-2xl shadow-2xl overflow-hidden',
              SIZES[size],
              className,
            )}
          >
            {(title || !hideCloseButton) && (
              <div className="flex items-start justify-between gap-4 p-5 sm:p-6 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div className="flex items-center gap-3 min-w-0">
                  {Icon && (
                    <div className={cn('shrink-0 p-2.5 rounded-xl', TONES[tone])}>
                      <Icon className="w-5 h-5" aria-hidden="true" />
                    </div>
                  )}
                  <div className="min-w-0">
                    {title && (
                      <h2 className="text-sm sm:text-base font-black text-slate-900 dark:text-white leading-tight">
                        {title}
                      </h2>
                    )}
                    {description && (
                      <p className="mt-1 text-[11px] sm:text-xs font-medium text-slate-500 dark:text-slate-400 leading-relaxed">
                        {description}
                      </p>
                    )}
                  </div>
                </div>

                {!hideCloseButton && (
                  <button
                    type="button"
                    onClick={onClose}
                    aria-label="إغلاق"
                    className="shrink-0 p-2 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                  >
                    <X className="w-4.5 h-4.5" />
                  </button>
                )}
              </div>
            )}

            {children && <div className="flex-1 overflow-y-auto p-5 sm:p-6">{children}</div>}

            {footer && (
              <div className="flex items-center justify-end gap-2 flex-wrap p-5 sm:p-6 pt-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-950/30">
                {footer}
              </div>
            )}
          </motion.div>
        </div>
      )}
    </AnimatePresence>
  );
}

export default Dialog;
