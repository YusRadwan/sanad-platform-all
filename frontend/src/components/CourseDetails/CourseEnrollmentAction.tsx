import { Sparkles, Lock, CheckCircle2, Wallet } from 'lucide-react';
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

        {/* Course Enrollment Quick Action Panel */}
        <div className="bg-white dark:bg-slate-900 rounded-3xl p-5 md:p-6 border border-slate-200/55 dark:border-slate-800 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-5 max-w-6xl mx-auto">
          <div className="flex items-center gap-3.5 w-full md:w-auto">
            <div className={`shrink-0 w-11 h-11 rounded-2xl flex items-center justify-center ${
              course.isFree
                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                : 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400'
            }`}>
              {course.isFree ? <Sparkles className="w-5 h-5" /> : <Wallet className="w-5 h-5" />}
            </div>
            <div className="text-right min-w-0">
              <span className="text-[11px] text-slate-400 dark:text-slate-500 font-extrabold block leading-tight">الوصول للكورس التعليمي</span>
              <span className={`text-base md:text-lg font-black leading-tight ${course.isFree ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-800 dark:text-white'}`}>
                {course.isFree ? 'مجاني بالكامل' : 'يتطلب كود اشتراك'}
              </span>
            </div>
          </div>

          <div className="hidden md:block w-px self-stretch bg-slate-100 dark:bg-slate-800" />

          <div className="flex flex-wrap gap-3 w-full md:w-auto md:flex-1 md:max-w-xs">
            {hasAccess ? (
              <span className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-500/30 font-bold text-sm w-full justify-center select-none shadow-sm">
                <CheckCircle2 className="w-5 h-5 text-emerald-500" />
                <span>أنت مشترك في هذا الكورس ومصرح لك بالوصول</span>
              </span>
            ) : loggedIn ? (
              userRole !== 'instructor' ? (
                <button
                  onClick={openModal}
                  className="inline-flex items-center gap-2 px-8 py-3.5 rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white shadow-lg shadow-emerald-500/25 border border-emerald-600 font-bold text-sm md:text-base w-full justify-center select-none transition-all cursor-pointer"
                >
                  <span>اشترك الآن</span>
                </button>
              ) : (
                <span className="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-slate-50 dark:bg-slate-500/10 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-500/20 font-bold text-sm w-full justify-center select-none shadow-sm">
                  <Lock className="w-4 h-4 text-slate-500" />
                  <span>محتوى مغلق (كورس مخصص لمعلم آخر)</span>
                </span>
              )
            ) : (
              // A guest can't redeem a code without an account — both buttons
              // used to point at /login, which made "اشترك الآن" a lie (it
              // never opened the redemption dialog). One honest CTA instead.
              <button
                onClick={() => navigate('/login')}
                className="px-8 py-3.5 bg-gradient-to-r from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 border border-emerald-600 text-white font-bold text-sm md:text-base rounded-xl shadow-lg shadow-emerald-500/25 transition-all cursor-pointer w-full justify-center flex items-center gap-2 group"
              >
                <Lock className="w-4.5 h-4.5 text-emerald-100 group-hover:scale-110 transition-transform" />
                <span>سجّل دخولك للاشتراك في الكورس</span>
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
