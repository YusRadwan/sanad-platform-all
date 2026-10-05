import { useId } from 'react';
import type { ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from './cn';

export interface FormFieldProps {
  label?: ReactNode;
  /** Marks the field visually as required (adds a red asterisk). */
  required?: boolean;
  /** Secondary hint shown under the control when there is no error. */
  helperText?: ReactNode;
  /** Validation message. When set it replaces `helperText` and tints the control. */
  error?: string | null;
  /** The `id` to bind the label to. One is generated when omitted. */
  htmlFor?: string;
  className?: string;
  /**
   * Render prop receiving the resolved field wiring, so the control can be
   * bound correctly for a11y without the caller repeating ids by hand.
   */
  children: ReactNode | ((field: { id: string; hasError: boolean; describedBy?: string }) => ReactNode);
}

/**
 * Label + control + helper/error wrapper. The single place the platform
 * decides how a form field is laid out and how an error reads.
 */
export function FormField({
  label,
  required = false,
  helperText,
  error,
  htmlFor,
  className,
  children,
}: FormFieldProps) {
  const generatedId = useId();
  const id = htmlFor ?? generatedId;
  const hasError = Boolean(error);
  const messageId = `${id}-message`;
  const describedBy = hasError || helperText ? messageId : undefined;

  return (
    <div className={cn('w-full', className)}>
      {label && (
        <label
          htmlFor={id}
          className="block mb-1.5 text-[11px] sm:text-xs font-bold text-slate-700 dark:text-slate-300"
        >
          {label}
          {required && (
            <span className="text-rose-500 px-1" aria-hidden="true">
              *
            </span>
          )}
        </label>
      )}

      {typeof children === 'function' ? children({ id, hasError, describedBy }) : children}

      {hasError ? (
        <p
          id={messageId}
          role="alert"
          className="mt-1.5 flex items-center gap-1.5 text-[11px] font-bold text-rose-600 dark:text-rose-400"
        >
          <AlertCircle className="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
          {error}
        </p>
      ) : (
        helperText && (
          <p id={messageId} className="mt-1.5 text-[11px] font-medium text-slate-500 dark:text-slate-400">
            {helperText}
          </p>
        )
      )}
    </div>
  );
}

export default FormField;
