import { forwardRef, useId } from 'react';
import type { InputHTMLAttributes, ReactNode } from 'react';
import { cn } from './cn';

export interface RadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label?: ReactNode;
  description?: ReactNode;
}

/** Single radio option. Group several by giving them the same `name`. */
const Radio = forwardRef<HTMLInputElement, RadioProps>(function Radio(
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
        type="radio"
        disabled={disabled}
        className={cn(
          'mt-0.5 w-4 h-4 shrink-0 cursor-pointer accent-blue-600',
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

export interface RadioGroupProps {
  legend?: ReactNode;
  /** Stack vertically (default) or lay out in a row. @default 'vertical' */
  orientation?: 'vertical' | 'horizontal';
  className?: string;
  children: ReactNode;
}

/** Semantic fieldset wrapper for a set of <Radio> options. */
export function RadioGroup({ legend, orientation = 'vertical', className, children }: RadioGroupProps) {
  return (
    <fieldset className={className}>
      {legend && (
        <legend className="mb-2 text-[11px] sm:text-xs font-bold text-slate-700 dark:text-slate-300">
          {legend}
        </legend>
      )}
      <div className={cn('flex', orientation === 'vertical' ? 'flex-col gap-2.5' : 'flex-row flex-wrap gap-5')}>
        {children}
      </div>
    </fieldset>
  );
}

export default Radio;
