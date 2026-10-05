import { forwardRef, useId } from 'react';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { cn } from './cn';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: ReactNode;
  /** Secondary line under the label. */
  description?: ReactNode;
}

/**
 * Checkbox + optional label. Uses the native input with `accent-color` rather
 * than a faux div, so indeterminate/keyboard/form-reset behaviour is free and
 * the checkmark direction is never mirrored incorrectly in RTL.
 */
const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, id, className, disabled, ...rest },
  ref,
) {
  const generatedId = useId();
  const inputId = id ?? generatedId;

  return (
    <div className={cn('flex items-start gap-2.5', disabled && 'opacity-60', className)}>
      <input
        ref={ref}
        id={inputId}
        type="checkbox"
        disabled={disabled}
        className={cn(
          'mt-0.5 w-4 h-4 shrink-0 rounded-[5px] cursor-pointer accent-blue-600',
          'border border-slate-300 dark:border-slate-600',
          'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
          'disabled:cursor-not-allowed',
        )}
        {...rest}
      />
      {(label || description) && (
        <label htmlFor={inputId} className={cn('select-none', !disabled && 'cursor-pointer')}>
          {label && (
            <span className="block text-xs font-bold text-slate-700 dark:text-slate-300">{label}</span>
          )}
          {description && (
            <span className="block text-[11px] font-medium text-slate-500 dark:text-slate-400 mt-0.5">
              {description}
            </span>
          )}
        </label>
      )}
    </div>
  );
});

export default Checkbox;
