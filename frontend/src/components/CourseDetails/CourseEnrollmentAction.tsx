import { Lock, CheckCircle2 } from 'lucide-react';
import { useNavigate } from 'react-router';
import EnrollmentCodeModal, { useEnrollmentCodeRedemption } from '../EnrollmentCodeModal';

/**
 * The "اشترك الآن" panel on a course page.
 *
 * For a logged-in student the button opens the enrollment-code dialog instead
 * of bouncing to /login. The dialog and its submit logic now live in the
 * shared `EnrollmentCodeModal` module — the course CARD opens the very same
 * flow without navigating here first, and there is exactly one copy of the
 * redemption path. It still submits ONLY the code: the server resolves the
 * course from the code's own row, so a code can never be used to unlock a
 * course other than the one it was generated for — including the course whose
 * page this dialog happens to be open on.
 */
export default function CourseEnrollmentAction({
  course,
  hasAccess,
  loggedIn,
  userRole,
  onEnrolled
}: any) {
  const navigate = useNavigate();

  const {
    modalOpen, closeModal, openModal,
    code, setCode,
    submitting,
    error, setError,
    success,
    handleRedeem,
  } = useEnrollmentCodeRedemption({ onEnrolled });

  return (
    <>

        {/* Access panel: what unlocks this course, and the one action. */}
        <div className="flex flex-col gap-4 rounded-2xl border border-[var(--hair-strong)] bg-[var(--paper-card)] p-5 md:flex-row md:items-center md:justify-between md:p-6">
          <div className="flex flex-col gap-1">
            <span className="text-[17px] font-medium">
              {course.isFree ? 'الكورس ده مجاني' : 'الكورس بيتفتح بكود اشتراك'}
            </span>
            {!course.isFree && !hasAccess && (
              <span className="text-sm text-slate-500 dark:text-slate-400">
                ادفع وهيوصلك كود الاشتراك، أو الأدمن يفعّل لك الكورس على حسابك
              </span>
            )}
          </div>

          <div className="md:min-w-[280px]">
            {hasAccess ? (
              <span className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-[10px] bg-emerald-50 px-6 text-[15px] font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                <CheckCircle2 className="h-5 w-5" aria-hidden="true" />
                إنت مشترك في الكورس ده
              </span>
            ) : loggedIn ? (
              userRole !== 'instructor' ? (
                <button type="button" onClick={openModal} className="btn-primary h-12 w-full px-6 text-base">
                  اشترك بالكود
                </button>
              ) : (
                <span className="inline-flex h-12 w-full items-center justify-center gap-2 rounded-[10px] bg-[var(--paper-raised)] px-6 text-[15px] text-slate-600 dark:text-slate-300">
                  <Lock className="h-4 w-4" aria-hidden="true" />
                  الكورس ده لمدرس تاني
                </span>
              )
            ) : (
              // A guest can't redeem a code without an account.
              <button type="button" onClick={() => navigate('/login')} className="btn-primary h-12 w-full px-6 text-base">
                <Lock className="h-4 w-4" aria-hidden="true" />
                سجّل دخولك واشترك
              </button>
            )}
          </div>
        </div>

        {/* ENROLLMENT CODE DIALOG — the shared one, also opened from CourseCard */}
        <EnrollmentCodeModal
          open={modalOpen}
          onClose={closeModal}
          courseTitle={course?.title}
          code={code}
          setCode={setCode}
          submitting={submitting}
          error={error}
          setError={setError}
          success={success}
          onRedeem={handleRedeem}
        />
    </>
  );
}
