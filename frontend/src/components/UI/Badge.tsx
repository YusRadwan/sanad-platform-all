import type { HTMLAttributes, ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

/**
 * Tones are derived from the statuses that ACTUALLY occur in Admin/* and
 * Instructor/* today — a grep of those pages returned: active / نشط,
 * suspended / موقوف, expired / منتهي, completed / مكتمل, pending, cancelled /
 * ملغي, paid, success. Nothing speculative is defined here.
 */
export type BadgeTone = 'neutral' | 'brand' | 'success' | 'danger' | 'warning' | 'info';

export type BadgeSize = 'sm' | 'md';

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  /** @default 'neutral' */
  tone?: BadgeTone;
  /** @default 'md' */
  size?: BadgeSize;
  /** Small filled circle before the label — the platform's "status pill" look. */
  dot?: boolean;
  icon?: LucideIcon;
  children?: ReactNode;
}

const TONES: Record<BadgeTone, string> = {
  neutral:
    'bg-slate-100 text-slate-700 border-slate-200/70 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
  brand:
    'bg-blue-50 text-blue-700 border-blue-200/70 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20',
  success:
    'bg-emerald-50 text-emerald-700 border-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20',
  danger:
    'bg-rose-50 text-rose-700 border-rose-200/70 dark:bg-rose-500/10 dark:text-rose-400 dark:border-rose-500/20',
  warning:
    'bg-amber-50 text-amber-700 border-amber-200/70 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20',
  info: 'bg-sky-50 text-sky-700 border-sky-200/70 dark:bg-sky-500/10 dark:text-sky-400 dark:border-sky-500/20',
};

const DOTS: Record<BadgeTone, string> = {
  neutral: 'bg-slate-400',
  brand: 'bg-blue-500',
  success: 'bg-emerald-500',
  danger: 'bg-rose-500',
  warning: 'bg-amber-500',
  info: 'bg-sky-500',
};

const SIZES: Record<BadgeSize, string> = {
  sm: 'text-[10px] px-2 py-0.5 gap-1',
  md: 'text-[11px] px-2.5 py-1 gap-1.5',
};

/** Compact status pill. Pass a tone directly, or use `statusTone()` to map a raw status string. */
export function Badge({
  tone = 'neutral',
  size = 'md',
  dot = false,
  icon: Icon,
  className,
  children,
  ...rest
}: BadgeProps) {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-lg border font-bold whitespace-nowrap leading-none',
        TONES[tone],
        SIZES[size],
        className,
      )}
      {...rest}
    >
      {dot && <span className={cn('w-1.5 h-1.5 rounded-full shrink-0', DOTS[tone])} aria-hidden="true" />}
      {Icon && <Icon className="w-3 h-3 shrink-0" aria-hidden="true" />}
      {children}
    </span>
  );
}

/**
 * Maps a raw status string (Arabic or English, as stored/returned today) to a
 * tone. Unknown values fall back to `neutral` — never throws, so it is safe to
 * feed straight from an API field.
 */
export function statusTone(status: string | null | undefined): BadgeTone {
  switch ((status ?? '').trim().toLowerCase()) {
    case 'active':
    case 'paid':
    case 'success':
    case 'completed':
    case 'approved':
    case 'نشط':
    case 'مكتمل':
      return 'success';
    case 'suspended':
    case 'cancelled':
    case 'canceled':
    case 'expired':
    case 'failed':
    case 'rejected':
    case 'موقوف':
    case 'ملغي':
    case 'منتهي':
      return 'danger';
    case 'pending':
    case 'قيد المراجعة':
    case 'معلق':
      return 'warning';
    default:
      return 'neutral';
  }
}

export default Badge;
