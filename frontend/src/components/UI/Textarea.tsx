import { forwardRef } from 'react';
import type { TextareaHTMLAttributes } from 'react';
import { cn } from './cn';
import { controlBase } from './controlStyles';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  hasError?: boolean;
}

/** Multi-line text input sharing Input's surface, border and focus ring. */
const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { hasError = false, rows = 4, className, ...rest },
  ref,
) {
  return (
    <textarea
      ref={ref}
      rows={rows}
      aria-invalid={hasError || undefined}
      className={cn(controlBase(hasError), 'rounded-xl px-3.5 py-2.5 text-xs leading-relaxed resize-y', className)}
      {...rest}
    />
  );
});

export default Textarea;
