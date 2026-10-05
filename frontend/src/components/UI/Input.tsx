import { forwardRef } from 'react';
import type { InputHTMLAttributes } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from './cn';
import { CONTROL_SIZES, controlBase } from './controlStyles';
import type { ControlSize } from './controlStyles';

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'size'> {
  /** @default 'md' */
  size?: ControlSize;
  /** Tints the border red and wires `aria-invalid`. */
  hasError?: boolean;
  /** Icon inside the field at the leading edge (right in RTL). */
  icon?: LucideIcon;
  /** Fixed unit/suffix text at the trailing edge, e.g. "ج.م". */
  suffix?: string;
}

/**
 * Standard single-line text input. Padding for the optional leading icon uses
 * logical `ps-*` so it flips correctly with the app's `dir="rtl"` root.
 */
const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { size = 'md', hasError = false, icon: Icon, suffix, className, ...rest },
  ref,
) {
  return (
    <div className="relative w-full">
      {Icon && (
        <Icon
          className="pointer-events-none absolute top-1/2 -translate-y-1/2 start-3 w-4 h-4 text-slate-400 dark:text-slate-500"
          aria-hidden="true"
        />
      )}
      <input
        ref={ref}
        aria-invalid={hasError || undefined}
        className={cn(
          controlBase(hasError),
          CONTROL_SIZES[size],
          Icon && 'ps-9',
          suffix && 'pe-12',
          className,
        )}
        {...rest}
      />
      {suffix && (
        <span className="pointer-events-none absolute top-1/2 -translate-y-1/2 end-3 text-[11px] font-bold text-slate-400 dark:text-slate-500">
          {suffix}
        </span>
      )}
    </div>
  );
});

export default Input;
