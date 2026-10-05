import { forwardRef } from 'react';
import type { SelectHTMLAttributes, ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { cn } from './cn';
import { CONTROL_SIZES, controlBase } from './controlStyles';
import type { ControlSize } from './controlStyles';

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size'> {
  /** @default 'md' */
  size?: ControlSize;
  hasError?: boolean;
  /** Convenience option list. Ignored when `children` is provided. */
  options?: Array<{ value: string | number; label: string; disabled?: boolean }>;
  /** Non-selectable first option, rendered with an empty value. */
  placeholder?: string;
  children?: ReactNode;
}

/**
 * Native `<select>` with the platform's control skin. Stays native on purpose:
 * it keeps mobile/RTL/keyboard behaviour correct for free, and every existing
 * page already binds `value`/`onChange` this way.
 */
const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { size = 'md', hasError = false, options, placeholder, className, children, ...rest },
  ref,
) {
  return (
    <div className="relative w-full">
      <select
        ref={ref}
        aria-invalid={hasError || undefined}
        className={cn(
          controlBase(hasError),
          CONTROL_SIZES[size],
          'appearance-none cursor-pointer pe-9',
          className,
        )}
        {...rest}
      >
        {placeholder && (
          <option value="" disabled>
            {placeholder}
          </option>
        )}
        {children ??
          options?.map((opt) => (
            <option key={opt.value} value={opt.value} disabled={opt.disabled}>
              {opt.label}
            </option>
          ))}
      </select>
      <ChevronDown
        className="pointer-events-none absolute top-1/2 -translate-y-1/2 end-3 w-4 h-4 text-slate-400 dark:text-slate-500"
        aria-hidden="true"
      />
    </div>
  );
});

export default Select;
