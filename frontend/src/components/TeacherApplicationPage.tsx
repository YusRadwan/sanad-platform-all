import { motion, AnimatePresence } from 'motion/react';
import PlatformLogo from './PlatformLogo';
import { TeacherApplicationPageProps } from '../types/index';
import {
  useTeacherApplicationForm,
  APPLICATION_STEPS,
  TEACHER_NAME_PARTS,
} from '../hooks/useTeacherApplicationForm';
import {
  User, Phone, MapPin, GraduationCap, Layers, ArrowLeft, ArrowRight,
  AlertCircle, CheckCircle2, Sparkles, Loader2, Link2, Plus, Trash2, Briefcase,
} from 'lucide-react';

/**
 * Public teacher self-application, as a 3-step wizard — visually and
 * structurally the same language SignupPage.tsx uses (gradient-free flat
 * cards, `rounded-3xl`, RTL, one shared hook holding all state). See open
 * decision 10.23.
 *
 * Reached from JoinUsSection.tsx's "ابدأ التدريس معنا الآن" button. There is
 * no login/redirect flow afterwards — a submitted application is reviewed by
 * an admin on a separate page, and this one just shows a thank-you state.
 */
export default function TeacherApplicationPage({ onBackToHome }: TeacherApplicationPageProps) {
  const {
    step,
    goToNextStep, goToPreviousStep,
    isStepValid,
    nameParts, setNamePart,
    phone, setPhone,
    whatsapp, setWhatsapp,
    governorate, setGovernorate,
    age, setAge,
    yearsOfExperience, setYearsOfExperience,
    subject, setSubject,
    sectionId, setSectionId,
    selectedGrades, toggleGrade,
    socialLinks, addSocialLink, removeSocialLink, setSocialLink,
    phoneDigits,
    sections,
    sectionsLoading,
    availableGrades,
    gradesLoading,
    governorates,
    errors,
    handleBlur,
    isSubmitted,
    isSubmitting,
    handleSubmit,
  } = useTeacherApplicationForm();

  const fieldError = (message: string) =>
    message ? (
      <p className="error-text text-xs font-bold text-rose-500 dark:text-rose-400 flex items-center gap-1.5 mt-1">
        <AlertCircle className="w-3.5 h-3.5 shrink-0" />
        <span>{message}</span>
      </p>
    ) : null;

  const inputClasses = (hasError: string, extra = '') =>
    `w-full py-3 bg-slate-50 dark:bg-slate-950 rounded-2xl border ${hasError ? 'border-rose-500' : 'border-slate-200 dark:border-slate-800'} text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 ${hasError ? 'focus:ring-rose-200 dark:focus:ring-rose-800/20' : 'focus:ring-blue-500/20 focus:border-blue-500'} dark:focus:border-blue-500 transition-all ${extra}`;

  const chipClasses = (selected: boolean) =>
    `py-3 px-4 rounded-2xl border font-black text-sm flex items-center justify-center gap-2 cursor-pointer ${selected ? 'bg-[#3544e1]/10 border-[#3544e1] text-[#3544e1]' : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-900'}`;

  return (
    <div dir="rtl" className="min-h-[85vh] bg-slate-50 dark:bg-slate-900/10 font-sans transition-colors duration-300 py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-2xl mx-auto">
        <AnimatePresence mode="wait">

          {isSubmitted ? (
            <motion.div
              key="application-success"
              initial={{ opacity: 0, scale: 0.95, y: 15 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0 }}
              transition={{ duration: 0.5 }}
              className="p-8 rounded-3xl bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-2xl text-center"
            >
              <div className="w-20 h-20 rounded-full bg-emerald-100 dark:bg-emerald-900/35 text-emerald-600 dark:text-emerald-400 flex items-center justify-center mx-auto mb-6">
                <CheckCircle2 className="w-12 h-12" />
              </div>

              <span className="inline-flex items-center gap-1.5 px-3.5 py-1 rounded-full bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 font-extrabold text-xs mb-3 border border-emerald-100 dark:border-emerald-900/30">
                <Sparkles className="w-3.5 h-3.5" />
                تم إرسال طلبك بنجاح
              </span>

              <h2 className="text-2xl md:text-3xl font-black text-slate-900 dark:text-white mb-3">
                شكراً لتقديمك للتدريس معنا!
              </h2>

              <p className="text-slate-600 dark:text-slate-400 text-base mb-8 leading-relaxed max-w-lg mx-auto font-medium">
                استلمنا بيانات طلبك بنجاح، وسيقوم فريقنا بمراجعتها والتواصل معك في أقرب وقت ممكن.
              </p>

              <button
                onClick={onBackToHome}
                className="px-8 py-3.5 text-white rounded-2xl font-extrabold text-base cursor-pointer"
                style={{ backgroundColor: '#3544e1' }}
              >
                العودة إلى الصفحة الرئيسية
              </button>
            </motion.div>
          ) : (
            <motion.div
              key="application-form"
              initial={{ opacity: 0, y: 15 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -15 }}
              transition={{ duration: 0.4 }}
            >
              <div className="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-xl rounded-3xl p-6 sm:p-8">

                <div className="mb-7 pb-5 border-b border-slate-100 dark:border-slate-800/60 text-center flex flex-col items-center">
                  <PlatformLogo className="w-24 h-24 mb-4 object-contain mx-auto" />
                  <h1 className="text-2xl sm:text-3xl font-black text-slate-900 dark:text-white">
                    انضم إلى نخبة معلمي منصة سند
                  </h1>
                </div>

                {/* STEP INDICATOR */}
                <div className="mb-8">
                  <div className="flex items-start justify-between gap-1 sm:gap-2">
                    {APPLICATION_STEPS.map((s) => {
                      const isCurrent = step === s.id;
                      const isDone = step > s.id;
                      return (
                        <div key={s.id} className="flex-1 flex items-start">
                          <div className="flex flex-col items-center flex-1 min-w-0">
                            <div
                              className={`w-10 h-10 rounded-2xl flex items-center justify-center font-black text-sm border-2 ${
                                isDone
                                  ? 'bg-emerald-500 border-emerald-500 text-white'
                                  : isCurrent
                                    ? 'text-white'
                                    : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-400 dark:text-slate-600'
                              }`}
                              style={isCurrent ? { backgroundColor: '#3544e1', borderColor: '#3544e1' } : undefined}
                            >
                              {isDone ? <CheckCircle2 className="w-5 h-5" /> : s.id}
                            </div>
                            <div className="text-center mt-2 hidden sm:block">
                              <p className={`text-xs font-black ${isCurrent ? 'text-slate-900 dark:text-white' : 'text-slate-400 dark:text-slate-600'}`}>
                                {s.title}
                              </p>
                              <p className="text-[10px] font-bold text-slate-400 dark:text-slate-600">{s.subtitle}</p>
                            </div>
                          </div>
                          {s.id !== 3 && (
                            <div className={`h-0.5 flex-1 mt-5 ${isDone ? 'bg-emerald-500' : 'bg-slate-200 dark:bg-slate-800'}`} />
                          )}
                        </div>
                      );
                    })}
                  </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-5">
                  <AnimatePresence mode="wait">

                    {/* ============ STEP 1 — PERSONAL ============ */}
                    {step === 1 && (
                      <motion.div
                        key="step-1"
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ duration: 0.28 }}
                        className="space-y-6"
                      >
                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            الاسم الرباعي <span className="text-rose-500">*</span>
                          </label>
                          <div className="grid grid-cols-2 gap-3">
                            {TEACHER_NAME_PARTS.map(({ key, placeholder }) => (
                              <div key={key} className="relative">
                                <input
                                  type="text"
                                  placeholder={placeholder}
                                  value={nameParts[key]}
                                  onChange={(e) => setNamePart(key, e.target.value)}
                                  onBlur={() => handleBlur(key)}
                                  className={inputClasses(errors[key], 'pr-11 pl-4 font-semibold')}
                                />
                                <User className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                              </div>
                            ))}
                          </div>
                          {TEACHER_NAME_PARTS.map(({ key }) => fieldError(errors[key])).find(Boolean)}
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <div className="space-y-1.5">
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              رقم هاتف المدرس <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                type="text"
                                placeholder={`رقم من ${phoneDigits} خانة`}
                                value={phone}
                                onChange={(e) => setPhone(e.target.value)}
                                onBlur={() => handleBlur('phone')}
                                className={inputClasses(errors.phone, 'pr-11 pl-4 font-semibold')}
                                dir="ltr"
                              />
                              <Phone className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                            </div>
                            {fieldError(errors.phone)}
                          </div>

                          <div className="space-y-1.5">
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              رقم واتساب المدرس <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                type="text"
                                placeholder={`رقم من ${phoneDigits} خانة`}
                                value={whatsapp}
                                onChange={(e) => setWhatsapp(e.target.value)}
                                onBlur={() => handleBlur('whatsapp')}
                                className={inputClasses(errors.whatsapp, 'pr-11 pl-4 font-semibold')}
                                dir="ltr"
                              />
                              <Phone className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                            </div>
                            {fieldError(errors.whatsapp)}
                          </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <div className="space-y-1.5">
                            <label htmlFor="application_governorate" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              المحافظة <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <select
                                id="application_governorate"
                                value={governorate}
                                onChange={(e) => setGovernorate(e.target.value)}
                                onBlur={() => handleBlur('governorate')}
                                className={inputClasses(errors.governorate, 'pr-11 pl-4 font-bold appearance-none cursor-pointer')}
                              >
                                <option value="">-- اختر المحافظة --</option>
                                {governorates.map((gov) => (
                                  <option key={gov} value={gov}>{gov}</option>
                                ))}
                              </select>
                              <MapPin className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-600 pointer-events-none" />
                            </div>
                            {fieldError(errors.governorate)}
                          </div>

                          <div className="space-y-1.5">
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              سن المدرس <span className="text-rose-500">*</span>
                            </label>
                            <input
                              type="number"
                              placeholder="مثال: 30"
                              value={age}
                              onChange={(e) => setAge(e.target.value)}
                              onBlur={() => handleBlur('age')}
                              className={inputClasses(errors.age, 'px-4 font-semibold')}
                            />
                            {fieldError(errors.age)}
                          </div>
                        </div>
                      </motion.div>
                    )}

                    {/* ============ STEP 2 — TEACHING ============ */}
                    {step === 2 && (
                      <motion.div
                        key="step-2"
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ duration: 0.28 }}
                        className="space-y-6"
                      >
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                          <div className="space-y-1.5">
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              سنوات الخبرة <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                type="number"
                                placeholder="مثال: 5"
                                value={yearsOfExperience}
                                onChange={(e) => setYearsOfExperience(e.target.value)}
                                onBlur={() => handleBlur('yearsOfExperience')}
                                className={inputClasses(errors.yearsOfExperience, 'pr-11 pl-4 font-semibold')}
                              />
                              <Briefcase className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                            </div>
                            {fieldError(errors.yearsOfExperience)}
                          </div>

                          <div className="space-y-1.5">
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                              المادة التي تدرّسها <span className="text-rose-500">*</span>
                            </label>
                            <div className="relative">
                              <input
                                type="text"
                                placeholder="مثال: رياضيات"
                                value={subject}
                                onChange={(e) => setSubject(e.target.value)}
                                onBlur={() => handleBlur('subject')}
                                className={inputClasses(errors.subject, 'pr-11 pl-4 font-semibold')}
                              />
                              <GraduationCap className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                            </div>
                            {fieldError(errors.subject)}
                          </div>
                        </div>

                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            المرحلة الدراسية <span className="text-rose-500">*</span>
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
                                    handleBlur('sectionId');
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

                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            الصفوف التي تستطيع تدريسها <span className="text-rose-500">*</span>
                            <span className="text-slate-400 font-medium"> (يمكن اختيار أكثر من صف)</span>
                          </label>
                          {!sectionId ? (
                            <p className="text-xs font-bold text-slate-400 dark:text-slate-600 py-3">
                              اختر المرحلة الدراسية أولاً لعرض صفوفها.
                            </p>
                          ) : gradesLoading ? (
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3" aria-hidden="true">
                              {[0, 1, 2].map((i) => (
                                <div key={i} className="h-[48px] rounded-2xl bg-slate-100 dark:bg-slate-900 animate-pulse" />
                              ))}
                            </div>
                          ) : (
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                              {availableGrades.map((grade) => (
                                <button
                                  key={grade}
                                  type="button"
                                  onClick={() => { toggleGrade(grade); handleBlur('grades'); }}
                                  className={chipClasses(selectedGrades.includes(grade)) + ' text-xs sm:text-sm px-2'}
                                >
                                  {grade}
                                </button>
                              ))}
                            </div>
                          )}
                          {fieldError(errors.grades)}
                        </div>
                      </motion.div>
                    )}

                    {/* ============ STEP 3 — SOCIAL LINKS ============ */}
                    {step === 3 && (
                      <motion.div
                        key="step-3"
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ duration: 0.28 }}
                        className="space-y-6"
                      >
                        <div className="space-y-1.5">
                          <label className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                            روابط حساباتك التعليمية أو صفحات السوشيال ميديا <span className="text-rose-500">*</span>
                          </label>

                          <div className="space-y-2.5">
                            {socialLinks.map((link, index) => (
                              <div key={index} className="relative flex items-center gap-2">
                                <div className="relative flex-1">
                                  <input
                                    type="text"
                                    placeholder="مثال: رابط صفحتك على يوتيوب أو فيسبوك"
                                    value={link}
                                    onChange={(e) => setSocialLink(index, e.target.value)}
                                    onBlur={() => handleBlur('socialLinks')}
                                    className={inputClasses(index === 0 ? errors.socialLinks : '', 'pr-11 pl-4 font-semibold')}
                                    dir="ltr"
                                  />
                                  <Link2 className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                                </div>
                                {socialLinks.length > 1 && (
                                  <button
                                    type="button"
                                    onClick={() => removeSocialLink(index)}
                                    aria-label="حذف الرابط"
                                    className="shrink-0 w-11 h-11 rounded-2xl bg-rose-50 dark:bg-rose-950/30 text-rose-500 flex items-center justify-center cursor-pointer"
                                  >
                                    <Trash2 className="w-4.5 h-4.5" />
                                  </button>
                                )}
                              </div>
                            ))}
                          </div>

                          <button
                            type="button"
                            onClick={addSocialLink}
                            className="inline-flex items-center gap-1.5 text-sm font-extrabold cursor-pointer p-1"
                            style={{ color: '#3544e1' }}
                          >
                            <Plus className="w-4 h-4" />
                            إضافة رابط آخر
                          </button>

                          {fieldError(errors.socialLinks)}
                        </div>
                      </motion.div>
                    )}
                  </AnimatePresence>

                  {/* WIZARD NAVIGATION */}
                  <div className="flex flex-col-reverse sm:flex-row items-center gap-3 pt-2">
                    {step > 1 && (
                      <button
                        type="button"
                        onClick={goToPreviousStep}
                        disabled={isSubmitting}
                        className="w-full sm:w-auto flex items-center justify-center gap-2 px-6 py-3.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl font-extrabold text-sm transition-all cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
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
                        className={`flex-1 w-full flex items-center justify-center gap-2.5 text-white py-4 px-6 rounded-2xl font-black text-base cursor-pointer ${!isStepValid(step) ? 'opacity-50 cursor-not-allowed' : ''}`}
                        style={{ backgroundColor: '#3544e1' }}
                      >
                        <span>التالي</span>
                        <ArrowLeft className="w-5 h-5 text-white" />
                      </button>
                    ) : (
                      <button
                        type="submit"
                        disabled={isSubmitting}
                        className={`flex-1 w-full flex items-center justify-center gap-2.5 text-white py-4 px-6 rounded-2xl font-black text-base cursor-pointer ${isSubmitting ? 'opacity-70 cursor-not-allowed' : ''}`}
                        style={{ backgroundColor: '#3544e1' }}
                      >
                        {isSubmitting ? (
                          <>
                            <Loader2 className="w-5 h-5 animate-spin" />
                            <span>جاري إرسال الطلب...</span>
                          </>
                        ) : (
                          <>
                            <span>إرسال الطلب</span>
                            <ArrowLeft className="w-5 h-5 text-white" />
                          </>
                        )}
                      </button>
                    )}
                  </div>
                </form>

                <div className="mt-8 pt-5 border-t border-slate-100 dark:border-slate-800/60 text-center text-sm">
                  <button
                    onClick={onBackToHome}
                    className="font-extrabold hover:underline select-none cursor-pointer p-1 text-slate-500 dark:text-slate-400"
                  >
                    الرجوع إلى الصفحة الرئيسية
                  </button>
                </div>
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
    </div>
  );
}
