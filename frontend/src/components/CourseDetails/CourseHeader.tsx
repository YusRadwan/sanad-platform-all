import { CalendarDays, Clock, GraduationCap, BookOpen, User, ArrowRight } from 'lucide-react';
import { AVATAR_PLACEHOLDER, COURSE_IMAGE_PLACEHOLDER } from '../../utils/imagePlaceholders';
import PlatformLogo from '../PlatformLogo';

export default function CourseHeader({
  course,
  courseDates,
  formatDateArabic,
  teacherId,
  teacherImage,
  navigate,
  onBack
}: any) {
  // Most rows are stored with updated_at === created_at until the course is
  // actually edited after publishing — showing an "آخر تحديث" identical to
  // "تاريخ النشر" is redundant noise, not information. Mirrors the same
  // guard in CourseCard.tsx so the two surfaces stay consistent.
  const showUpdatedAt = !!courseDates.updatedAt && courseDates.updatedAt !== courseDates.createdAt;

  // A deliberately fixed-dark "brand hero" card, mirroring the existing
  // precedent set by this same component before this redesign (a solid
  // #3544e1 card with white text, independent of the site's light/dark
  // toggle) — the glass panels below carry their own colours explicitly
  // rather than switching with `dark:` variants.
  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 lg:gap-6 items-stretch">

      {/* Brand hero card — the course's own cover image is the dominant
          visual (courseService.ts already guarantees `course.image` is
          always a usable string, real upload or placeholder — same
          contract CourseCard.tsx relies on), with grade/subject badges and
          the back button overlaid on top of it, and the publish/update meta
          strip underneath. The specific course identity (title, teacher)
          lives in the side panel instead, so nothing here is duplicated
          between the two cards. */}
      <div className="lg:col-span-7 relative flex flex-col overflow-hidden rounded-[32px] shadow-[0_25px_60px_rgba(15,23,42,0.35)] bg-gradient-to-br from-[#0a0f2e] via-[#141b4d] to-[#1c1454]">
        <div className="relative w-full aspect-[16/10] shrink-0">
          <img
            src={course.image || COURSE_IMAGE_PLACEHOLDER}
            alt={course.title}
            className="absolute inset-0 w-full h-full object-cover"
            onError={(e) => {
              e.currentTarget.onerror = null;
              e.currentTarget.src = COURSE_IMAGE_PLACEHOLDER;
            }}
          />
          {/* Scrim so the overlaid badges/back-button and the platform mark
              stay legible over any photo, bright or dark. */}
          <div className="absolute inset-0 bg-gradient-to-t from-[#0a0f2e]/90 via-[#0a0f2e]/10 to-[#0a0f2e]/55 pointer-events-none" />

          <div className="absolute inset-x-0 top-0 flex items-center justify-between gap-3 px-5 md:px-8 pt-5 md:pt-7">
            {onBack ? (
              <button
                onClick={onBack}
                title="رجوع"
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-full bg-white/15 hover:bg-white/25 backdrop-blur-md text-white font-extrabold text-[11px] ring-1 ring-inset ring-white/20 transition-all duration-300 cursor-pointer active:scale-95"
              >
                <ArrowRight className="w-3.5 h-3.5" />
                <span>العودة</span>
              </button>
            ) : <span />}

            <div className="flex flex-wrap items-center justify-end gap-2.5">
              {course.subject && (
                <span className="inline-flex items-center gap-2 pl-3.5 pr-1.5 py-1.5 rounded-full bg-white/15 backdrop-blur-md ring-1 ring-inset ring-white/20 text-xs md:text-sm font-black text-white">
                  <span className="w-6 h-6 rounded-full bg-emerald-500/90 text-white flex items-center justify-center shrink-0">
                    <BookOpen className="w-3.5 h-3.5" />
                  </span>
                  <span>{course.subject}</span>
                </span>
              )}
              {course.grade && (
                <span className="inline-flex items-center gap-2 pl-3.5 pr-1.5 py-1.5 rounded-full bg-white/15 backdrop-blur-md ring-1 ring-inset ring-white/20 text-xs md:text-sm font-black text-white">
                  <span className="w-6 h-6 rounded-full bg-[#3544e1] text-white flex items-center justify-center shrink-0">
                    <GraduationCap className="w-3.5 h-3.5" />
                  </span>
                  <span>{course.grade}</span>
                </span>
              )}
            </div>
          </div>

          <div className="absolute inset-x-0 bottom-0 flex items-center gap-2 px-5 md:px-8 pb-4 md:pb-5">
            <PlatformLogo className="w-7 h-7 object-contain drop-shadow-[0_0_12px_rgba(84,101,241,0.65)]" />
            <span className="text-xs font-black text-white/85 tracking-wide">منصة سند</span>
          </div>
        </div>

        {/* Frosted footer strip — publish / last-update stats. */}
        <div className="relative flex items-stretch border-t border-white/10 bg-white/[0.06] backdrop-blur-md overflow-hidden">
          <div className="flex-1 flex items-center gap-2.5 px-4 md:px-6 py-3.5">
            <div className="shrink-0 w-8 h-8 rounded-xl bg-amber-400/20 text-amber-200 flex items-center justify-center">
              <CalendarDays className="w-4 h-4" />
            </div>
            <div className="min-w-0">
              <span className="text-[10px] text-white/55 font-extrabold block leading-tight">تاريخ النشر</span>
              <span className="text-xs font-black text-white truncate block">
                {courseDates.createdAt ? formatDateArabic(courseDates.createdAt) : 'غير محدد'}
              </span>
            </div>
          </div>

          <div className="w-px bg-white/15 my-3" />

          <div className="flex-1 flex items-center gap-2.5 px-4 md:px-6 py-3.5">
            <div className="shrink-0 w-8 h-8 rounded-xl bg-sky-300/20 text-sky-100 flex items-center justify-center">
              <Clock className="w-4 h-4" />
            </div>
            <div className="min-w-0">
              <span className="text-[10px] text-white/55 font-extrabold block leading-tight">آخر تحديث</span>
              <span className="text-xs font-black text-white truncate block">
                {showUpdatedAt ? formatDateArabic(courseDates.updatedAt) : 'لم يتم التعديل بعد'}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* Side glass panel — teacher identity (large photo — this is a
          "presented by" credential, not a small avatar chip, so it gets
          real visual weight), then the course's own title and description
          underneath. */}
      <div className="lg:col-span-5 relative overflow-hidden rounded-[28px] border border-white/10 bg-gradient-to-b from-[#151c4c] to-[#0a0f2e] shadow-[0_20px_50px_rgba(15,23,42,0.3)] flex flex-col">
        <div
          aria-hidden="true"
          className="absolute inset-0 opacity-[0.1] pointer-events-none select-none"
          style={{
            backgroundImage:
              'radial-gradient(circle at 1px 1px, rgba(255,255,255,0.9) 1px, transparent 0)',
            backgroundSize: '26px 26px'
          }}
        />

        {course.teacher ? (
          <div
            onClick={() => {
              if (!teacherId) return;
              navigate(`/teacher/${teacherId}`);
              window.scrollTo({ top: 0, behavior: 'instant' });
            }}
            role={teacherId ? 'button' : undefined}
            tabIndex={teacherId ? 0 : undefined}
            onKeyDown={(e: any) => {
              if (teacherId && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                navigate(`/teacher/${teacherId}`);
                window.scrollTo({ top: 0, behavior: 'instant' });
              }
            }}
            title={teacherId ? 'عرض الملف الشخصي للمدرس' : undefined}
            className={`group/teacher relative flex flex-col items-center text-center gap-3 px-6 pt-8 pb-7 border-b border-white/10 ${teacherId ? 'cursor-pointer' : ''}`}
          >
            <span className="text-[11px] font-extrabold text-white/50 tracking-wide">المعلم</span>
            <div className="relative w-32 h-32 md:w-36 md:h-36">
              <div className="absolute -inset-1.5 rounded-full bg-[#3544e1]/40 blur-xl transition-colors duration-300 group-hover/teacher:bg-[#3544e1]/60" />
              <div className="relative w-full h-full rounded-full border-[3px] border-white/70 shadow-[0_10px_30px_rgba(0,0,0,0.35)] overflow-hidden transition-transform duration-300 group-hover/teacher:scale-105">
                <img
                  src={teacherImage || course.teacher.image || AVATAR_PLACEHOLDER}
                  alt={course.teacher.name}
                  className="w-full h-full object-cover"
                  onError={(e) => {
                    e.currentTarget.onerror = null;
                    e.currentTarget.src = AVATAR_PLACEHOLDER;
                  }}
                />
              </div>
            </div>
            <h3 className="text-lg md:text-xl font-black text-white transition-colors duration-300 group-hover/teacher:text-indigo-200">
              {course.teacher.name}
            </h3>
          </div>
        ) : (
          <div className="flex items-center gap-3 mx-6 mt-6 mb-6 p-3.5 rounded-2xl bg-white/5 border border-white/10">
            <div className="w-11 h-11 rounded-full overflow-hidden border-2 border-white/40 select-none bg-white/10 flex items-center justify-center text-white shrink-0">
              <User className="w-5 h-5" />
            </div>
            <span className="text-sm font-black text-white">معلم متميز من سَنَدْ</span>
          </div>
        )}

        <div className="relative flex-1 flex flex-col justify-center gap-2 px-6 py-6 text-center">
          <h2 className="text-lg md:text-xl font-black text-white leading-snug line-clamp-2">
            {course.title}
          </h2>
          {course.description && (
            <p className="text-xs md:text-sm font-semibold text-white/60 leading-relaxed line-clamp-2">
              {course.description}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
