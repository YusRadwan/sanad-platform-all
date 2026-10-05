import { forwardRef } from 'react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Loader2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';

export type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'outline'
  | 'ghost'
  | 'success'
  | 'danger'
  | 'warning';

export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
  /** Visual weight / intent. @default 'primary' */
  variant?: ButtonVariant;
  /** @default 'md' */
  size?: ButtonSize;
  /** Shows a spinner and disables interaction. */
  isLoading?: boolean;
  /** Optional text shown while `isLoading`. Falls back to `children`. */
  loadingText?: string;
  /** Icon rendered at the START of the button (visually right-hand side in RTL). */
  icon?: LucideIcon;
  /** Icon rendered at the END of the button (visually left-hand side in RTL). */
  trailingIcon?: LucideIcon;
  /** Stretch to the container's full width. */
  fullWidth?: boolean;
  /** Square icon-only button; pass `aria-label` when using this. */
  iconOnly?: boolean;
  children?: ReactNode;
}

const VARIANTS: Record<ButtonVariant, string> = {
  primary:
    'bg-blue-600 text-white border border-transparent shadow-sm shadow-blue-600/20 hover:bg-blue-700 active:bg-blue-800 focus-visible:ring-blue-500',
  secondary:
    'bg-slate-100 text-slate-800 border border-slate-200/70 hover:bg-slate-200 active:bg-slate-300 dark:bg-slate-800 dark:text-slate-100 dark:border-slate-700 dark:hover:bg-slate-700 focus-visible:ring-slate-400',
  outline:
    'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 hover:border-slate-400 dark:bg-slate-900 dark:text-slate-200 dark:border-slate-700 dark:hover:bg-slate-800 dark:hover:border-slate-600 focus-visible:ring-slate-400',
  ghost:
    'bg-transparent text-slate-600 border border-transparent hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white focus-visible:ring-slate-400',
  success:
    'bg-emerald-600 text-white border border-transparent shadow-sm shadow-emerald-600/20 hover:bg-emerald-700 active:bg-emerald-800 focus-visible:ring-emerald-500',
  danger:
    'bg-rose-600 text-white border border-transparent shadow-sm shadow-rose-600/20 hover:bg-rose-700 active:bg-rose-800 focus-visible:ring-rose-500',
  warning:
    'bg-amber-500 text-white border border-transparent shadow-sm shadow-amber-500/20 hover:bg-amber-600 active:bg-amber-700 focus-visible:ring-amber-400',
};

const SIZES: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-[11px] gap-1.5 rounded-lg',
  md: 'h-10 px-4 text-xs gap-2 rounded-xl',
  lg: 'h-12 px-6 text-sm gap-2.5 rounded-xl',
};

const ICON_ONLY_SIZES: Record<ButtonSize, string> = {
  sm: 'h-8 w-8 p-0 rounded-lg',
  md: 'h-10 w-10 p-0 rounded-xl',
  lg: 'h-12 w-12 p-0 rounded-xl',
};

const ICON_SIZES: Record<ButtonSize, string> = {
  sm: 'w-3.5 h-3.5',
  md: 'w-4 h-4',
  lg: 'w-[18px] h-[18px]',
};

/**
 * The platform's single button primitive.
 *
 * RTL note: `icon` is the LEADING icon in reading order. Because the app root
 * is `dir="rtl"`, flexbox places it on the right automatically — no directional
 * (`ml-`/`mr-`) classes are used anywhere here, only `gap`.
 */
const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    isLoading = false,
    loadingText,
    icon: Icon,
    trailingIcon: TrailingIcon,
    fullWidth = false,
    iconOnly = false,
    disabled,
    className,
    children,
    type = 'button',
    ...rest
  },
  ref,
) {
  const isDisabled = disabled || isLoading;
  const iconClass = ICON_SIZES[size];

  return (
    <button
      ref={ref}
      type={type}
      disabled={isDisabled}
      aria-busy={isLoading || undefined}
      className={cn(
        'inline-flex items-center justify-center font-bold whitespace-nowrap select-none',
        'transition-colors duration-200 cursor-pointer',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
        'focus-visible:ring-offset-white dark:focus-visible:ring-offset-slate-950',
        'disabled:opacity-50 disabled:pointer-events-none',
        VARIANTS[variant],
        iconOnly ? ICON_ONLY_SIZES[size] : SIZES[size],
        fullWidth && 'w-full',
        className,
      )}
      {...rest}
    >
      {isLoading ? (
        <Loader2 className={cn(iconClass, 'animate-spin shrink-0')} aria-hidden="true" />
      ) : (
        Icon && <Icon className={cn(iconClass, 'shrink-0')} aria-hidden="true" />
      )}
      {/*
        `iconOnly` normally suppresses `children` (the label). But several call
        sites pass the glyph itself as a child instead of via `icon` — usually
        because the glyph needs a dynamic class (`animate-spin` on a refresh
        button) that the `icon` prop cannot express. Those buttons rendered
        completely EMPTY. When `iconOnly` is set and no `icon` prop was given,
        fall back to rendering `children` as the glyph (unlabelled, so no
        `<span>` wrapper — it stays centred).
      */}
      {iconOnly
        ? !Icon && !isLoading && children
        : <span>{isLoading && loadingText ? loadingText : children}</span>}
      {!iconOnly && !isLoading && TrailingIcon && (
        <TrailingIcon className={cn(iconClass, 'shrink-0')} aria-hidden="true" />
      )}
    </button>
  );
});

export default Button;
