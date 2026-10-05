import { useState } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import PlatformLogo from './PlatformLogo';
import { SignupPageProps } from '../types/index';
import { useSignupForm, SIGNUP_STEPS, NAME_PARTS } from '../hooks/useSignupForm';

import { User, Phone, MapPin, Lock, Eye, EyeOff, Check, AlertCircle, GraduationCap, Layers, ArrowLeft, ArrowRight, ShieldCheck, Sparkles, CheckCircle2, Loader2, BookOpenCheck } from 'lucide-react';

// The instructions the student must acknowledge before the signup form
// becomes usable — shown as a blocking modal the first time this page mounts.
const SIGNUP_INSTRUCTIONS = [
  'وانت بتعمل حساب لازم تكون بياناتك صحيحة (اسمك رباعي - رقم الواتساب بتاعك - رقم ولي أمرك).',
  'لازم تحفظ الباسورد بتاعك.',
  'يجب الالتزام بمشاهدة الفيديوهات وحل الواجب والامتحانات.',
  'يتم إرسال تقرير دوري بالمستوى لولي الأمر لمتابعة مستواك.',
  'أي طالب غير ملتزم مش هيكمل معانا.',
  'مشاهدة الفيديو بتتحسب بعد مشاهدة 30% من مدة الفيديو.',
  'لا يتم استرداد الاشتراكات المدفوعة.',
];

/**
 * Public student self-registration, as a 3-step wizard.
 *
 * The platform serves Egypt only, so there is no country/curriculum choice
 * anymore: step 1 collects fullName, gender and governorate; step 2 collects
 * eduType, section and grade (the grade list is the same admin-managed
 * catalog teacher classification uses — see useSignupForm.ts); step 3
 * collects the phones (plain local digits, 11 total) and the password.
 *
 * All form state lives in useSignupForm — the steps below are pure rendering
 * over that one store, which is what makes going back and forward lossless.
 * The wizard shell is built from this page's own raw-Tailwind language
 * (gradient cards, rounded-3xl, RTL) rather than the dashboard `UI/` library,
 * matching the precedent set by Header.tsx.
 */
export default function SignupPage({ onBackToHome, onNavigateToLogin }: SignupPageProps) {
  const {
    step,
    goToNextStep, goToPreviousStep,
    isStepValid,
    nameParts, setNamePart,
    fullName,
    studentPhone, setStudentPhone,
    parentPhone, setParentPhone,
    governorate, setGovernorate,
    gender, setGender,
    eduType, setEduType,
    sectionId, setSectionId,
    grade, setGrade,
    password, setPassword,
    confirmPassword, setConfirmPassword,
    showPassword, setShowPassword,
    showConfirmPassword, setShowConfirmPassword,
    phoneDigits,
    sections,
    sectionsLoading,
    grades,
    gradesLoading,
    governorates,
    errors,
    handleBlur,
    isSubmitted, setIsSubmitted,
    isSubmitting,
    setTouched,
    generatedStudentCode,
    handleSignupSubmit
  } = useSignupForm();

  const [showInstructions, setShowInstructions] = useState(true);

  const fieldError = (message: string) =>
    message ? (
      <p className="error-text text-xs font-bold text-rose-500 dark:text-rose-400 flex items-center gap-1.5 mt-1">
        <AlertCircle className="w-3.5 h-3.5 shrink-0" />
        <span>{message}</span>
      </p>
    ) : null;

  const inputClasses = (hasError: string, extra = '') =>
    `w-full py-2.5 bg-slate-50 dark:bg-slate-950 rounded-2xl border ${hasError ? 'border-rose-500' : 'border-slate-200 dark:border-slate-800'} text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 ${hasError ? 'focus:ring-rose-200 dark:focus:ring-rose-800/20' : 'focus:ring-blue-500/20 focus:border-blue-500'} dark:focus:border-blue-500 transition-all ${extra}`;

  const chipClasses = (selected: boolean) =>
    `py-2.5 px-4 rounded-2xl border font-black text-sm flex items-center justify-center gap-2 cursor-pointer ${selected ? 'bg-[#3544e1]/10 border-[#3544e1] text-[#3544e1]' : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-900'}`;

  return (
    <div dir="rtl" className="min-h-[85vh] lg:min-h-[calc(100vh-98px)] flex flex-col lg:flex-row bg-slate-50 dark:bg-slate-900/10 font-sans transition-colors duration-300 select-none">
      {/* Pre-signup instructions gate — a blocking modal shown once when this
          page mounts, before the student can interact with the form behind
          it. Dismissing it only hides the overlay; it does not reset or skip
          any step of the wizard underneath. `fixed inset-0` keeps it a
          full-screen overlay regardless of the two-column split below. */}
      <AnimatePresence>
        {showInstructions && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm"
          >
            <motion.div
              initial={{ opacity: 0, scale: 0.95, y: 20 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0, scale: 0.95, y: 20 }}
              transition={{ duration: 0.3 }}
              className="w-full max-w-lg max-h-[85vh] overflow-y-auto rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-2xl"
            >
              <div className="p-6 sm:p-8 text-right">
                <div className="flex items-center gap-3 mb-2">
                  <span className="shrink-0 w-11 h-11 rounded-2xl flex items-center justify-center" style={{ backgroundColor: '#3544e1' }}>
                    <BookOpenCheck className="w-5.5 h-5.5 text-white" />
                  </span>
                  <h2 className="text-xl sm:text-2xl font-black text-slate-900 dark:text-white">
                    تعليمات مهمة قبل إنشاء الحساب
                  </h2>
                </div>
                <p className="text-sm text-slate-500 dark:text-slate-400 font-medium mb-6">
                  اقرأ التعليمات دي كويس قبل ما تكمل تسجيل بياناتك.
                </p>

                <ul className="space-y-3.5 mb-8">
                  {SIGNUP_INSTRUCTIONS.map((text, idx) => (
                    <li key={idx} className="flex items-start gap-3">
                      <span
                        className="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-black text-white mt-0.5"
                        style={{ backgroundColor: '#3544e1' }}
                      >
                        {idx + 1}
                      </span>
                      <span className="text-sm sm:text-[15px] leading-relaxed font-semibold text-slate-700 dark:text-slate-300">
                        {text}
                      </span>
                    </li>
                  ))}
                </ul>

                <button
                  type="button"
                  onClick={() => setShowInstructions(false)}
                  className="w-full py-3.5 rounded-2xl text-white font-black text-sm sm:text-base cursor-pointer"
                  style={{ backgroundColor: '#3544e1' }}
                >
                  قرأت التعليمات، أكمل التسجيل
                </button>
              </div>
            </motion.div>
          </motion.div>
        )}
      </AnimatePresence>

      {/* RIGHT half — account-creation panel. Default DOM order renders it
          physically on the right under `dir="rtl"`, no order utilities
          needed. Scrolls independently so a tall step never fights the
          fixed-height brand panel beside it. */}
      <div className="relative w-full lg:w-1/2 overflow-y-auto py-4 px-4 sm:px-6 lg:px-10 flex flex-col items-center justify-center">
        {/* Atmosphere Glows */}
        <div className="absolute top-0 right-1/4 w-[350px] h-[350px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
        <div className="absolute bottom-12 left-1/4 w-[400px] h-[400px] bg-indigo-500/5 dark:bg-indigo-500/10 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <div className="max-w-2xl mx-auto w-full">
        <AnimatePresence mode="wait">

          {/* SUCCESS SIGNUP STATE */}
          {isSubmitted ? (
            <motion.div
              key="signup-success"
              initial={{ opacity: 0, scale: 0.95, y: 15 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.5 }}
              className="max-w-2xl mx-auto p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-2xl text-center relative overflow-hidden"
              id="signup_success_card"
            >
              <div className="absolute inset-0 bg-radial-gradient from-blue-500/5 to-transparent pointer-events-none" />

              <div className="w-20 h-20 rounded-full bg-emerald-100 dark:bg-emerald-900/35 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-6 shadow-xl shadow-emerald-500/10">
                <CheckCircle2 className="w-12 h-12" />
              </div>

              <span className="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 font-extrabold text-xs mb-3 border border-emerald-100 dark:border-emerald-900/30">
                <Sparkles className="w-3.5 h-3.5" />
                حساب طالب مفعّل بنجاح
              </span>

              <h2 className="text-3xl md:text-4xl font-black text-slate-900 dark:text-white mb-3">
                مرحباً بك في منصة سند، {fullName.split(' ')[0]}! 🎉
              </h2>

              <p className="text-slate-600 dark:text-slate-400 text-base mb-8 leading-relaxed max-w-lg mx-auto font-medium">
                تم إنشاء حسابك الرباعي كطالب مسجل بنجاح تام. يمكنك الآن الاستمتاع بكامل المزايا والدروس المتاحة لصفك الدراسي.
              </p>

              {/* Student ID Card */}
              <div className="p-6 rounded-2xl bg-slate-50 dark:bg-slate-950/65 border border-slate-200/50 dark:border-slate-800/50 max-w-lg mx-auto text-right mb-8">
                <h3 className="text-sm font-semibold text-slate-400 mb-4 pb-2 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                  <span>بطاقة الطالب التعريفية</span>
                  <ShieldCheck className="w-4 h-4 text-emerald-500" />
                </h3>

                <div className="grid grid-cols-2 gap-y-3.5 gap-x-4 text-sm md:text-base">
                  <div className="col-span-2 bg-blue-50/60 dark:bg-blue-950/45 p-3.5 rounded-xl border border-blue-100/40 dark:border-blue-900/30 flex items-center justify-between gap-3 text-right">
                    <div className="space-y-0.5">
                      <span className="block text-blue-700 dark:text-blue-400 font-extrabold text-xs">كود الطالب التعريفي (الرمز الأكاديمي)</span>
                      <p className="text-[10px] text-slate-400 dark:text-slate-500 font-bold">يرجى حفظ هذا الرمز التعريفي الخاص بك للرجوع إليه دائماً</p>
                    </div>
                    <span className="font-mono font-black text-lg md:text-xl text-blue-600 dark:text-blue-400 tracking-wider bg-white dark:bg-slate-900 px-3 py-1 rounded-xl shadow-sm border border-blue-100/60 dark:border-blue-900/40 select-all">{generatedStudentCode}</span>
                  </div>
                  <div>
                    <span className="block text-xs text-slate-400 font-bold mb-1">اسم الطالب رباعي</span>
                    <span className="font-extrabold text-slate-800 dark:text-slate-100">{fullName}</span>
                  </div>
                  <div>
                    <span className="block text-xs text-slate-400 font-bold mb-1">رقم الهاتف المسجل</span>
                    <span dir="ltr" className="inline-block font-mono font-bold text-blue-600 dark:text-blue-400 bg-blue-100/40 dark:bg-blue-900/25 px-2 py-0.5 rounded text-sm select-all">{studentPhone}</span>
                  </div>
                  <div>
                    <span className="block text-xs text-slate-400 font-bold mb-1">الصف الدراسي</span>
                    <span className="font-extrabold text-slate-800 dark:text-slate-100">{grade}</span>
                  </div>
                  <div className="col-span-2">
                    <span className="block text-xs text-slate-400 font-bold mb-1">المحافظة الجغرافية</span>
                    <span className="font-extrabold text-slate-800 dark:text-slate-100">{governorate}</span>
                  </div>
                </div>
              </div>

              {/* Action buttons */}
              <div className="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <button
                  onClick={() => {
                    setIsSubmitted(false);
                    onBackToHome?.();
                  }}
                  className="w-full sm:w-auto px-8 py-3.5 text-white rounded-2xl font-extrabold text-base cursor-pointer"
                  style={{ backgroundColor: '#3544e1' }}
                >
                  الذهاب إلى لوحة التحكم التعليمية
                </button>
                <button
                  onClick={onNavigateToLogin}
                  className="w-full sm:w-auto px-8 py-3.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl font-bold text-sm transition-all cursor-pointer"
                >
                  تسجيل الدخول بالمستخدم الجديد
                </button>
              </div>

            </motion.div>
          ) : (
            <motion.div
              key="signup-form"
              initial={{ opacity: 0, y: 15 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -15 }}
              transition={{ duration: 0.4 }}
              className="max-w-2xl mx-auto w-full"
            >

              {/* Signup Form Card */}
              <div className="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xl rounded-3xl p-4 sm:p-5">

                <div className="mb-3 pb-2 border-b border-slate-100 dark:border-slate-800/60 text-center flex flex-col items-center">
                  <PlatformLogo className="w-12 h-12 mb-1 object-contain mx-auto" />
                  <h1 className="text-lg sm:text-xl font-black text-slate-900 dark:text-white">
                    إنشاء حساب طالب جديد
                  </h1>
                </div>

                {/* STEP INDICATOR */}
                <div className="mb-3" id="signup_step_indicator">
                  <div className="flex items-start justify-between gap-1 sm:gap-2">
                    {SIGNUP_STEPS.map((s, index) => {
                      const isCurrent = step === s.id;
                      const isDone = step > s.id;
                      return (
                        <div key={s.id} className="flex-1 flex items-start">
                          <div className="flex flex-col items-center flex-1 min-w-0">
                            <div
                              className={`w-8 h-8 rounded-xl flex items-center justify-center font-black text-xs transition-all duration-300 border-2 ${
                                isDone
                                  ? 'bg-emerald-500 border-emerald-500 text-white shadow-lg shadow-emerald-500/20'
                                  : isCurrent
                                    ? 'bg-gradient-to-l from-blue-600 to-blue-500 border-blue-600 text-white shadow-lg shadow-blue-500/25 scale-110'
                                    : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-400 dark:text-slate-600'
                              }`}
                              aria-current={isCurrent ? 'step' : undefined}
                            >
                              {isDone ? <Check className="w-4 h-4" /> : s.id}
                            </div>
                            <span
                              className={`mt-1.5 text-[10px] sm:text-xs font-extrabold text-center leading-tight truncate max-w-full px-1 ${
                                isCurrent
                                  ? 'text-blue-600 dark:text-blue-400'
                                  : isDone
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-slate-400 dark:text-slate-600'
                              }`}
                            >
                              {s.title}
                            </span>
                          </div>
                          {index < SIGNUP_STEPS.length - 1 && (
                            <div className={`h-0.5 flex-1 mt-4 rounded-full transition-all duration-300 ${step > s.id ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-800'}`} />
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>

                <form onSubmit={handleSignupSubmit} className="space-y-4" id="student_signup_form">
                  <AnimatePresence mode="wait">

                    {/* ============ STEP 1 — PERSONAL ============ */}
                    {step === 1 && (
                      <motion.div
                        key="step-1"
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ duration: 0.28 }}
                        className="space-y-4"
                      >
                        {/* Quadruple name — four separate inputs, one `fullName` on submit */}
                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            الاسم الرباعي للطالب <span className="text-rose-500">*</span>
                          </label>
                          <div className="grid grid-cols-2 gap-3">
                            {NAME_PARTS.map(({ key, label, placeholder }) => (
                              <div key={key} className="space-y-1">
                                <div className="relative">
                                  <input
                                    id={`signup_${key}`}
                                    type="text"
                                    aria-label={label}
                                    placeholder={placeholder}
                                    value={nameParts[key]}
                                    onChange={(e) => setNamePart(key, e.target.value)}
                                    onBlur={() => handleBlur(key)}
                                    className={inputClasses(errors[key], 'px-11 font-semibold')}
                                  />
                                  <User className="absolute right-4 top-3.5 w-5 h-5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                                  {!errors[key] && nameParts[key].trim() !== '' && (
                                    <div className="absolute left-4 top-3.5 text-emerald-500">
                                      <Check className="w-5 h-5" />
                                    </div>
                                  )}
                                </div>
                                {fieldError(errors[key])}
                              </div>
                            ))}
                          </div>
                          {fullName !== '' && (
                            <p className="text-[11px] font-bold text-slate-500 dark:text-slate-400">
                              الاسم الرباعي كما سيُحفظ: <span className="text-slate-800 dark:text-slate-100">{fullName}</span>
                            </p>
                          )}
                        </div>

                        {/* Gender */}
                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            النوع <span className="text-rose-500">*</span>
                          </label>
                          <div className="grid grid-cols-2 gap-4">
                            <button
                              type="button"
                              onClick={() => {
                                setGender('ذكر');
                                setTouched((prev) => ({ ...prev, gender: true }));
                              }}
                              className={`py-2.5 px-6 rounded-2xl border font-black text-sm flex items-center justify-center gap-2 cursor-pointer ${gender === 'ذكر' ? 'bg-[#3544e1]/10 border-[#3544e1] text-[#3544e1]' : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-900'}`}
                            >
                              <span>ذكر</span>
                              {gender === 'ذكر' && <div className="w-4 h-4 rounded-full text-white flex items-center justify-center text-[10px] font-bold" style={{ backgroundColor: '#3544e1' }}>✓</div>}
                            </button>
                            <button
                              type="button"
                              onClick={() => {
                                setGender('أنثى');
                                setTouched((prev) => ({ ...prev, gender: true }));
                              }}
                              className={`py-2.5 px-6 rounded-2xl border font-black text-sm flex items-center justify-center gap-2 transition-all cursor-pointer ${gender === 'أنثى' ? 'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-600 text-indigo-600 dark:text-indigo-400 ring-2 ring-indigo-500/10' : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-900'}`}
                            >
                              <span>أنثى</span>
                              {gender === 'أنثى' && <div className="w-4 h-4 rounded-full bg-indigo-600 dark:bg-indigo-500 text-white flex items-center justify-center text-[10px] font-bold">✓</div>}
                            </button>
                          </div>
                          {fieldError(errors.gender)}
                        </div>

                        {/* Governorate */}
                        <div className="space-y-1.5">
                          <label htmlFor="signup_governorate" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            المحافظة <span className="text-rose-500">*</span>
                          </label>
                          <div className="relative">
                            <select
                              id="signup_governorate"
                              value={governorate}
                              onChange={(e) => setGovernorate(e.target.value)}
                              onBlur={() => handleBlur('governorate')}
                              className={inputClasses(errors.governorate, 'pr-11 pl-4 font-bold appearance-none cursor-pointer')}
                            >
                              <option value="">-- اختر محافظة الطالب --</option>
                              {governorates.map((gov) => (
                                <option key={gov} value={gov}>{gov}</option>
                              ))}
                            </select>
                            <MapPin className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                            <div className="absolute left-4 top-4.5 w-3 h-3 border-r-2 border-b-2 border-slate-400 transform rotate-45 pointer-events-none shrink-0" />
                          </div>
                          {fieldError(errors.governorate)}
                        </div>
                      </motion.div>
                    )}

                    {/* ============ STEP 2 — ACADEMIC ============ */}
                    {step === 2 && (
                      <motion.div
                        key="step-2"
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ duration: 0.28 }}
                        className="space-y-4"
                      >
                        {/* Education type — a flat, always-visible choice; no
                            longer gated behind a "stage" question (removed —
                            see useSignupForm.ts's top docblock). */}
                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            نوع التعليم <span className="text-rose-500">*</span>
                          </label>
                          <div className="grid grid-cols-3 gap-3">
                            {(['عام', 'بكالوريا', 'أزهري'] as const).map((type) => (
                              <button
                                key={type}
                                type="button"
                                onClick={() => {
                                  setEduType(type);
                                  setTouched((prev) => ({ ...prev, eduType: true }));
                                }}
                                className={chipClasses(eduType === type) + ' text-xs sm:text-sm px-2'}
                              >
                                {type}
                              </button>
                            ))}
                          </div>
                          {fieldError(errors.eduType)}
                        </div>

                        {/* Section — the admin-managed catalog grouping the
                            grade list (open decision 10.12). Chosen first, as
                            large cards, so the grade select below can be
                            scoped to it. */}
                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            القسم <span className="text-rose-500">*</span>
                          </label>
                          {sectionsLoading ? (
                            <div className="grid grid-cols-2 gap-3" aria-hidden="true">
                              {[0, 1].map((i) => (
                                <div key={i} className="h-[52px] rounded-2xl bg-slate-100 dark:bg-slate-900 animate-pulse" />
                              ))}
                            </div>
                          ) : (
                            <div className="grid grid-cols-2 gap-3">
                              {sections.map((section) => (
                                <button
                                  key={section.id}
                                  type="button"
                                  onClick={() => {
                                    setSectionId(section.id);
                                    setTouched((prev) => ({ ...prev, sectionId: true }));
                                  }}
                                  className={chipClasses(sectionId === section.id) + ' text-xs sm:text-sm px-2'}
                                >
                                  <Layers className="w-4 h-4 shrink-0" />
                                  {section.name}
                                </button>
                              ))}
                            </div>
                          )}
                          {fieldError(errors.sectionId)}
                        </div>

                        {/* Grade — the same admin-managed catalog teacher
                            classification uses (GET /catalogs/grades, public),
                            filtered to the chosen section, in the admin's own
                            display order within that section. */}
                        <div className="space-y-1.5">
                          <label htmlFor="signup_grade" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            الصف الدراسي <span className="text-rose-500">*</span>
                          </label>
                          <div className="relative">
                            <select
                              id="signup_grade"
                              value={grade}
                              disabled={!sectionId || gradesLoading}
                              onChange={(e) => {
                                setGrade(e.target.value);
                                setTouched((prev) => ({ ...prev, grade: true }));
                              }}
                              onBlur={() => handleBlur('grade')}
                              className={inputClasses(errors.grade, 'pr-11 pl-4 font-bold appearance-none cursor-pointer bg-white dark:bg-slate-950 disabled:opacity-55 disabled:cursor-not-allowed')}
                            >
                              <option value="">
                                {!sectionId
                                  ? '-- اختر القسم أولاً --'
                                  : gradesLoading ? '-- جاري تحميل الصفوف... --' : '-- اختر صفك الدراسي --'}
                              </option>
                              {grades.map((g) => (
                                <option key={g} value={g}>{g}</option>
                              ))}
                            </select>
                            <GraduationCap className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                            {gradesLoading
                              ? <Loader2 className="absolute left-4 top-3.5 w-4 h-4 text-slate-400 animate-spin pointer-events-none" />
                              : <div className="absolute left-4 top-4.5 w-3 h-3 border-r-2 border-b-2 border-slate-400 transform rotate-45 pointer-events-none" />}
                          </div>
                          {fieldError(errors.grade)}
                        </div>
                      </motion.div>
                    )}

                    {/* ============ STEP 3 — CONTACT & SECURITY ============ */}
                    {step === 3 && (
                      <motion.div
                        key="step-3"
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ duration: 0.28 }}
                        className="space-y-4"
                      >
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                          {/* Student Mobile */}
                          <div className="space-y-1.5">
                            <label htmlFor="signup_student_phone" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              رقم هاتف الطالب <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                id="signup_student_phone"
                                type="tel"
                                dir="ltr"
                                maxLength={phoneDigits}
                                placeholder="01xxxxxxxxx"
                                value={studentPhone}
                                onChange={(e) => setStudentPhone(e.target.value.replace(/\D/g, ''))}
                                onBlur={() => handleBlur('studentPhone')}
                                className={inputClasses(errors.studentPhone, 'px-11 text-left font-mono font-bold tracking-wider')}
                              />
                              <Phone className="absolute left-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                              {studentPhone && (
                                <div className="absolute right-3.5 top-3.5 text-[10px] bg-slate-200/50 dark:bg-slate-800 px-2 py-0.5 rounded font-sans text-slate-500 font-bold pointer-events-none">
                                  {studentPhone.length}/{phoneDigits}
                                </div>
                              )}
                            </div>
                            {fieldError(errors.studentPhone)}
                          </div>

                          {/* Parent Mobile */}
                          <div className="space-y-1.5">
                            <label htmlFor="signup_parent_phone" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              رقم هاتف ولي الأمر <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                id="signup_parent_phone"
                                type="tel"
                                dir="ltr"
                                maxLength={phoneDigits}
                                placeholder="01xxxxxxxxx"
                                value={parentPhone}
                                onChange={(e) => setParentPhone(e.target.value.replace(/\D/g, ''))}
                                onBlur={() => handleBlur('parentPhone')}
                                className={inputClasses(errors.parentPhone, 'px-11 text-left font-mono font-bold tracking-wider')}
                              />
                              <Phone className="absolute left-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                              {parentPhone && (
                                <div className="absolute right-3.5 top-3.5 text-[10px] bg-slate-200/50 dark:bg-slate-800 px-2 py-0.5 rounded font-sans text-slate-500 font-bold pointer-events-none">
                                  {parentPhone.length}/{phoneDigits}
                                </div>
                              )}
                            </div>
                            {fieldError(errors.parentPhone)}
                          </div>
                        </div>

                        {/* Passwords */}
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                          <div className="space-y-1.5">
                            <label htmlFor="signup_password" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              كلمة المرور <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                id="signup_password"
                                type={showPassword ? "text" : "password"}
                                placeholder="أي كلمة مرور، بحد أدنى 6 أحرف"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                onBlur={() => handleBlur('password')}
                                className={inputClasses(errors.password, 'pr-11 pl-11 font-semibold')}
                              />
                              <Lock className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                              <button
                                type="button"
                                onClick={() => setShowPassword(!showPassword)}
                                aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                                className="absolute left-4 top-3 text-slate-400 hover:text-blue-500 transition-all p-1.5 rounded cursor-pointer"
                              >
                                {showPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
                              </button>
                            </div>
                            {fieldError(errors.password)}
                          </div>

                          <div className="space-y-1.5">
                            <label htmlFor="signup_confirm_password" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              تأكيد كلمة المرور <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                id="signup_confirm_password"
                                type={showConfirmPassword ? "text" : "password"}
                                placeholder="أعد كتابة كلمة المرور للتحقق"
                                value={confirmPassword}
                                onChange={(e) => setConfirmPassword(e.target.value)}
                                onBlur={() => handleBlur('confirmPassword')}
                                className={inputClasses(errors.confirmPassword, 'pr-11 pl-11 font-semibold')}
                              />
                              <Lock className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                              <button
                                type="button"
                                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                aria-label={showConfirmPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                                className="absolute left-4 top-3 text-slate-400 hover:text-blue-500 transition-all p-1.5 rounded cursor-pointer"
                              >
                                {showConfirmPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
                              </button>
                            </div>
                            {fieldError(errors.confirmPassword)}
                          </div>
                        </div>
                      </motion.div>
                    )}
                  </AnimatePresence>

                  {/* WIZARD NAVIGATION — the POST only ever fires from step 3 */}
                  <div className="flex flex-col-reverse sm:flex-row items-center gap-3">
                    {step > 1 && (
                      <button
                        type="button"
                        onClick={goToPreviousStep}
                        disabled={isSubmitting}
                        className="w-full sm:w-auto flex items-center justify-center gap-2 px-6 py-3 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl font-extrabold text-sm transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                      >
                        <ArrowRight className="w-4.5 h-4.5" />
                        <span>السابق</span>
                      </button>
                    )}

                    {step < 3 ? (
                      <button
                        type="button"
                        onClick={goToNextStep}
                        disabled={!isStepValid(step)}
                        className={`flex-1 w-full flex items-center justify-center gap-2.5 text-white py-3 px-6 rounded-2xl font-black text-base cursor-pointer ${!isStepValid(step) ? 'opacity-50 cursor-not-allowed' : ''}`}
                        style={{ backgroundColor: '#3544e1' }}
                      >
                        <span>التالي</span>
                        <ArrowLeft className="w-5 h-5 text-white" />
                      </button>
                    ) : (
                      <button
                        type="submit"
                        disabled={isSubmitting}
                        className={`flex-1 w-full flex items-center justify-center gap-2.5 text-white py-3 px-6 rounded-2xl font-black text-base cursor-pointer ${isSubmitting ? 'opacity-70 cursor-not-allowed' : ''}`}
                        style={{ backgroundColor: '#3544e1' }}
                      >
                        {isSubmitting ? (
                          <>
                            <Loader2 className="w-5 h-5 animate-spin" />
                            <span>جاري إنشاء الحساب...</span>
                          </>
                        ) : (
                          <>
                            <span>إنشاء الحساب</span>
                            <ArrowLeft className="w-5 h-5 text-white" />
                          </>
                        )}
                      </button>
                    )}
                  </div>

                </form>

                {/* Already have an account navigation link */}
                <div className="mt-5 pt-4 border-t border-slate-100 dark:border-slate-800/60 text-center text-sm">
                  <span className="text-slate-500 dark:text-slate-400 font-semibold">لديك حساب مسجل بالفعل؟ </span>
                  <button
                    onClick={onNavigateToLogin}
                    className="font-extrabold hover:underline select-none cursor-pointer p-1"
                    style={{ color: '#3544e1' }}
                  >
                    تسجيل الدخول هنا مباشر
                  </button>
                </div>

              </div>
            </motion.div>
          )}

        </AnimatePresence>
      </div>
      </div>

      {/* LEFT half — solid brand-blue panel with the signup illustration.
          Default DOM order (second child) renders it physically on the left
          under `dir="rtl"`. Hidden below `lg` — a 50/50 split has no room to
          spare on a phone or small tablet, so the form panel above simply
          takes the full width there instead. */}
      <div
        className="hidden lg:flex lg:w-1/2 items-center justify-center p-8 shrink-0"
        style={{ backgroundColor: '#3544e1' }}
      >
        <img
          src="/branding/signup.svg"
          alt=""
          aria-hidden="true"
          className="w-full max-w-md h-auto object-contain"
        />
      </div>
    </div>
  );
}
