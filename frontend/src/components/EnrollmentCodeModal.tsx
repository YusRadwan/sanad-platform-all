import { useState, useCallback } from 'react';
import { CheckCircle2, KeyRound, X, RefreshCw, AlertTriangle } from 'lucide-react';
import { enrollmentApiService } from '../services/enrollmentApiService';

/**
 * THE enrollment-code redemption flow — one implementation, two entry points.
 *
 * It used to live inline in CourseDetails/CourseEnrollmentAction.tsx, which
 * meant the only way to redeem a code was to first navigate to a course page.
 * Now the course CARD can open it directly too, so the logic was lifted here
 * instead of copied: a second copy of a security-relevant submit path is how
 * the two drift (one validating 8 digits, the other not).
 *
 * THE SUBMITTED PAYLOAD IS STILL ONLY THE CODE. No course id is sent, and
 * none is accepted as an argument on purpose — the server resolves the course
 * from the code's own row, so a code can never unlock a course other than the
 * one it was generated for, whichever screen the dialog was opened from. The
 * `courseTitle` prop below is display text in the prompt and nothing else.
 */

export interface UseEnrollmentCodeRedemptionOptions {
  /** Called with the API result on success. When absent, the page reloads. */
  onEnrolled?: (result: any) => void;
}

export function useEnrollmentCodeRedemption({ onEnrolled }: UseEnrollmentCodeRedemptionOptions = {}) {
  const [modalOpen, setModalOpen] = useState(false);
  const [code, setCode] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const openModal = useCallback(() => {
    setCode('');
    setError(null);
    setSuccess(null);
    setModalOpen(true);
  }, []);

  const closeModal = useCallback(() => setModalOpen(false), []);

  const handleRedeem = useCallback(async () => {
    const trimmed = code.trim();
    if (!/^\d{8}$/.test(trimmed)) {
      setError('الكود يجب أن يتكون من 8 أرقام');
      return;
    }
    try {
      setSubmitting(true);
      setError(null);
      const result = await enrollmentApiService.redeemEnrollmentCode(trimmed);
      setSuccess(`تم تفعيل اشتراكك في "${result?.course_title ?? 'الكورس'}" بنجاح`);
      // Let the caller re-check access; fall back to a reload so the student
      // immediately sees the unlocked content either way.
      if (typeof onEnrolled === 'function') {
        onEnrolled(result);
        setTimeout(() => setModalOpen(false), 1400);
      } else {
        setTimeout(() => window.location.reload(), 1400);
      }
    } catch (err: any) {
      const data = err?.response?.data;
      setError(
        data?.data?.errors?.code?.[0] ||
        data?.message ||
        'تعذر تفعيل الكود، برجاء المحاولة مرة أخرى'
      );
    } finally {
      setSubmitting(false);
    }
  }, [code, onEnrolled]);

  return {
    modalOpen, setModalOpen,
    openModal, closeModal,
    code, setCode,
    submitting,
    error, setError,
    success,
    handleRedeem,
  };
}

export interface EnrollmentCodeModalProps {
  open: boolean;
  onClose: () => void;
  /** Display-only: the course named in the prompt. Never sent to the server. */
  courseTitle?: string;
  code: string;
  setCode: (value: string) => void;
  submitting: boolean;
  error: string | null;
  setError: (value: string | null) => void;
  success: string | null;
  onRedeem: () => void | Promise<void>;
}

export default function EnrollmentCodeModal({
  open,
  onClose,
  courseTitle,
  code,
  setCode,
  submitting,
  error,
  setError,
  success,
  onRedeem,
}: EnrollmentCodeModalProps) {
  if (!open) return null;

  return (
    <div
      dir="rtl"
      className="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-950/60 backdrop-blur-sm"
      onClick={() => !submitting && onClose()}
    >
      <div
        className="bg-white dark:bg-slate-900 rounded-[28px] border border-slate-200 dark:border-slate-800 shadow-2xl w-full max-w-md p-6 space-y-5 text-right"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="space-y-1">
            <h3 className="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
              <KeyRound className="w-5 h-5 text-emerald-600" />
              <span>أدخل كود الاشتراك</span>
            </h3>
            <p className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 leading-relaxed">
              اكتب الكود المكوّن من 8 أرقام الذي حصلت عليه لفتح كورس
              <strong className="text-slate-800 dark:text-slate-200"> «{courseTitle}» </strong>
            </p>
          </div>
          <button
            onClick={() => !submitting && onClose()}
            className="p-1.5 rounded-xl text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <input
          type="text"
          inputMode="numeric"
          maxLength={8}
          value={code}
          autoFocus
          onChange={(e) => { setCode(e.target.value.replace(/\D/g, '')); setError(null); }}
          onKeyDown={(e) => { if (e.key === 'Enter') void onRedeem(); }}
          placeholder="00000000"
          dir="ltr"
          className="w-full text-center text-2xl font-black font-mono tracking-[0.4em] rounded-2xl bg-slate-50 dark:bg-slate-950 border-2 border-slate-200 dark:border-slate-800 px-4 py-4 focus:outline-none focus:border-emerald-500 text-slate-900 dark:text-white"
        />

        {error && (
          <div className="flex items-center gap-2 text-xs font-bold text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/60 rounded-2xl px-4 py-3">
            <AlertTriangle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        {success && (
          <div className="flex items-center gap-2 text-xs font-bold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-900/60 rounded-2xl px-4 py-3">
            <CheckCircle2 className="w-4 h-4 shrink-0" />
            <span>{success}</span>
          </div>
        )}

        <button
          onClick={() => void onRedeem()}
          disabled={submitting || code.length !== 8 || !!success}
          className="w-full inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-2xl bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white font-black text-sm shadow-lg shadow-emerald-500/25 transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {submitting ? <RefreshCw className="w-4 h-4 animate-spin" /> : <CheckCircle2 className="w-4 h-4" />}
          <span>تفعيل الاشتراك</span>
        </button>

        <p className="text-[10px] font-semibold text-slate-400 text-center leading-relaxed">
          الكود يُستخدم مرة واحدة فقط، ويفتح الكورس الذي أُنشئ من أجله فقط.
        </p>
      </div>
    </div>
  );
}
