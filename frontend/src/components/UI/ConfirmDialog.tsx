import type { ReactNode } from 'react';
import { AlertTriangle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import Dialog from './Dialog';
import Button from './Button';
import type { ButtonVariant } from './Button';

export interface ConfirmDialogProps {
  open: boolean;
  onClose: () => void;
  /** Invoked when the confirm button is pressed. Closing is left to the caller. */
  onConfirm: () => void;
  title: ReactNode;
  /** The consequence being confirmed — say what will happen, not just "are you sure". */
  message?: ReactNode;
  /** @default 'تأكيد' */
  confirmLabel?: string;
  /** @default 'إلغاء' */
  cancelLabel?: string;
  /** 'danger' for destructive actions. @default 'danger' */
  tone?: 'danger' | 'warning' | 'brand';
  icon?: LucideIcon;
  /** Spinner on the confirm button; also blocks dismissal. */
  isLoading?: boolean;
  children?: ReactNode;
}

const CONFIRM_VARIANT: Record<'danger' | 'warning' | 'brand', ButtonVariant> = {
  danger: 'danger',
  warning: 'warning',
  brand: 'primary',
};

/**
 * Confirmation prompt for destructive/irreversible actions, built on Dialog so
 * animation, Escape and backdrop behaviour are identical to every other modal.
 */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel = 'تأكيد',
  cancelLabel = 'إلغاء',
  tone = 'danger',
  icon = AlertTriangle,
  isLoading = false,
  children,
}: ConfirmDialogProps) {
  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={title}
      description={message}
      icon={icon}
      tone={tone}
      size="sm"
      disableDismiss={isLoading}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={isLoading}>
            {cancelLabel}
          </Button>
          <Button variant={CONFIRM_VARIANT[tone]} onClick={onConfirm} isLoading={isLoading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      {children}
    </Dialog>
  );
}

export default ConfirmDialog;
