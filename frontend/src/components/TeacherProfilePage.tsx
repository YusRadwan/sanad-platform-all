import { ArrowRight, GraduationCap, BookOpen, Sparkles, PlayCircle } from 'lucide-react';
import CourseCard from './CourseCard';
import { TeacherProfilePageProps } from '../types/index';
import { safeUrl } from '../utils/urlValidation';
import { motion } from 'motion/react';
import { BANNER_PLACEHOLDER, AVATAR_PLACEHOLDER } from '../utils/imagePlaceholders';
import { getSocialPlatformIcon } from '../utils/socialPlatformIcon';
import TeacherWhatsAppSupportButton from './TeacherWhatsAppSupportButton';

/**
 * Arabic label + chip colouring per platform. The ICON itself comes from the
 * shared `getSocialPlatformIcon` util (also used by Footer.tsx) — only this
 * page's own visual treatment lives here. Anything not listed falls back to a
 * neutral blue chip labelled with the raw platform name the admin typed.
 */
const SOCIAL_CHIP_STYLES: Record<string, { label: string; className: string }> = {
  facebook:  { label: 'فيسبوك',  className: 'text-blue-600 hover:bg-blue-600' },
  youtube:   { label: 'يوتيوب',  className: 'text-rose-600 hover:bg-rose-600' },
  whatsapp:  { label: 'واتساب',  className: 'text-emerald-600 hover:bg-emerald-600' },
  telegram:  { label: 'تليجرام', className: 'text-sky-500 hover:bg-sky-500' },
  tiktok:    { label: 'تيك توك', className: 'text-slate-800 hover:bg-slate-900' },
  instagram: { label: 'انستجرام', className: 'text-pink-600 hover:bg-pink-600' },
  twitter:   { label: 'تويتر',   className: 'text-sky-600 hover:bg-sky-600' },
  'x (twitter)': { label: 'تويتر', className: 'text-sky-600 hover:bg-sky-600' },
  linkedin:  { label: 'لينكدإن', className: 'text-blue-700 hover:bg-blue-700' },
  snapchat:  { label: 'سناب شات', className: 'text-amber-600 hover:bg-amber-500' },
};

const DEFAULT_SOCIAL_CHIP = 'text-blue-600 hover:bg-blue-600';




export default function TeacherProfilePage({ 
  teacher, 
  courses, 
  onBack,
  isLoggedIn = false,
  onNavigate,
  onViewCourse
}: TeacherProfilePageProps) {
  if (!teacher) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950">
        <div className="flex flex-col items-center gap-4">
          <div className="w-12 h-12 border-4 border-blue-500/30 border-t-blue-600 rounded-full animate-spin" />
          <p className="text-slate-600 dark:text-slate-400 font-bold">جاري تحميل صفحة المدرس...</p>
        </div>
      </div>
    );
  }

  const teacherName = teacher.full_name || teacher.name || '';

  // Filter courses belonging to this teacher
  const teacherCourses = (courses || []).filter(
    c => c.teacher?.name?.trim() === teacherName.trim() || c.title.toLowerCase().includes(teacherName.toLowerCase())
  );

  // Only platforms that actually carry a non-empty URL (and are not explicitly
  // disabled) get an icon — an empty `url` would otherwise render a dead chip.
  const activeSocialLinks = (teacher.socialLinks || []).filter(
    link => link.enabled !== false && typeof link.url === 'string' && link.url.trim() !== ''
  );

  // Icon lookup is shared with Footer.tsx; the chip colour stays local to
  // this page. Icon-only, no label — the `title` attribute still carries the
  // Arabic platform name for a tooltip and for screen readers.
  //
  // Solid WHITE circle, not a tinted "/10" wash — this panel's own
  // background is a solid blue-to-indigo gradient, so a light-tinted chip
  // (e.g. bg-blue-600/10 text-blue-600) reads as nearly invisible against
  // it. A plain white disc keeps every platform's brand colour identifiable
  // while staying clearly visible regardless of theme.
  const renderSocialIcon = (platform: string, url: string) => {
    const IconComponent = getSocialPlatformIcon(platform);
    const chip = SOCIAL_CHIP_STYLES[(platform || '').toLowerCase().trim()];
    const label = chip?.label || platform;
    const colorClass = chip?.className || DEFAULT_SOCIAL_CHIP;

    return (
      <a
        key={platform}
        href={safeUrl(url)}
        target="_blank"
        rel="noopener noreferrer"
        title={label}
        aria-label={label}
        className={`group/social inline-flex items-center justify-center w-10 h-10 rounded-2xl bg-white shadow-md transition-all duration-300 hover:text-white hover:shadow-lg hover:-translate-y-0.5 active:translate-y-0 active:scale-[0.98] cursor-pointer ${colorClass}`}
      >
        <IconComponent className="w-4.5 h-4.5 shrink-0 transition-transform duration-300 group-hover/social:scale-110" />
      </a>
    );
  };

  return (
    <div dir="rtl" className="min-h-screen bg-slate-50 dark:bg-slate-950 transition-colors duration-300 pb-20">
      
      {/* UNIFIED, COMPACT PROFILE HEADER — cover photo and identity card are
          ONE piece: the cover is a short strip fading directly into the
          card's own background, and the avatar overlaps its bottom edge
          (the classic, professional "cover + avatar" pattern), all inside
          one rounded container. The container's own background is the SAME
          deep-blue gradient TeacherCard.tsx uses, so the public profile page
          reads as a larger, premium extension of the card the visitor
          clicked through from, not a visually unrelated plain-white page. */}
      <div className="container mx-auto px-6 md:px-12 lg:px-16 pt-6 md:pt-8 mb-10">
        <motion.div
          initial={{ opacity: 0, y: -10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1] }}
          className="relative overflow-hidden rounded-[28px] shadow-xl shadow-blue-900/20"
          style={{
            background:
              'radial-gradient(120% 90% at 15% -10%, rgba(224,250,255,0.55) 0%, rgba(224,250,255,0) 42%), ' +
              'linear-gradient(165deg, #082a63 0%, #0f3f8f 32%, #1f74bd 66%, #52cfe0 100%)',
          }}
        >
          {/* Cover strip — short, and fades straight into the card's own
              deep-blue tone below instead of ending in a hard edge, so the
              photo reads as part of the card rather than a separate block
              stacked on top of it. */}
          <div className="relative h-60 sm:h-72 md:h-80 lg:h-96 w-full overflow-hidden select-none group">
            <img
              src={teacher.banner_url || BANNER_PLACEHOLDER}
              alt="Banner"
              className="w-full h-full object-cover select-none filter contrast-[1.03] brightness-[1.03] transition-transform duration-1000 group-hover:scale-[1.02]"
              referrerPolicy="no-referrer"
              onError={(e) => {
                e.currentTarget.onerror = null;
                e.currentTarget.src = BANNER_PLACEHOLDER;
              }}
            />
            <div className="absolute inset-0 bg-gradient-to-b from-slate-950/35 via-slate-950/10 to-[#082a63]" />

            <button
              onClick={onBack}
              className="absolute top-3 right-3 md:top-4 md:right-4 z-20 flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-white/95 backdrop-blur-md shadow-md text-slate-800 hover:bg-blue-600 hover:text-white transition-all duration-300 font-extrabold text-[11px] cursor-pointer group/back active:scale-95"
            >
              <ArrowRight className="w-3.5 h-3.5 group-hover/back:translate-x-1 transition-transform" />
              <span>العودة للرئيسية</span>
            </button>
          </div>

          {/* Identity row — the avatar overlaps the cover's bottom edge by
              design (negative top margin), which is what visually "merges"
              the two into one piece. */}
          <div className="relative px-5 md:px-8 pb-6 md:pb-7">

            {/* Avatar + name row — stays transparent so the cover's fade
                keeps reading through it; the negative top margin is what
                makes the avatar overlap the cover's bottom edge. Name is now
                always white (the card's own background is a fixed dark blue
                regardless of site theme), matching TeacherCard.tsx's own
                white-on-glass name treatment. */}
            <div className="flex flex-col md:flex-row items-center md:items-end gap-4 md:gap-6 -mt-16 md:-mt-20">
              <div className="relative w-32 h-32 md:w-40 md:h-40 rounded-3xl overflow-hidden ring-4 ring-white/90 shadow-lg shrink-0 bg-white/10 select-none">
                <img
                  src={teacher.avatar_url || AVATAR_PLACEHOLDER}
                  alt={teacher.full_name || teacher.name}
                  className="w-full h-full object-cover"
                  referrerPolicy="no-referrer"
                  onError={(e) => {
                    e.currentTarget.onerror = null;
                    e.currentTarget.src = AVATAR_PLACEHOLDER;
                  }}
                />
              </div>

              <div className="min-w-0 flex-1 text-center md:text-right pt-1 md:pt-0 pb-1">
                <h1 className="text-3xl md:text-5xl font-black tracking-tight leading-tight truncate text-white">
                  {teacherName}
                </h1>
              </div>
            </div>

            {/* Details panel — a lighter "glass" layer (white/10 + blur) over
                the hero's own gradient, rather than a second, different blue
                nested inside it — one background doing the work instead of
                two competing blues. */}
            {((teacher.subjects && teacher.subjects.length > 0) || (teacher.grades && teacher.grades.length > 0) || activeSocialLinks.length > 0 || (teacher.whatsappNumbers && teacher.whatsappNumbers.length > 0)) && (
              <div className="mt-6 rounded-2xl bg-white/10 backdrop-blur-sm border border-white/15 p-5 md:p-6 shadow-lg shadow-blue-950/20">
                <div className="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">

                  {/* RIGHT: subjects/grades */}
                  {((teacher.subjects && teacher.subjects.length > 0) || (teacher.grades && teacher.grades.length > 0)) && (
                    <div className="min-w-0 flex-1 space-y-3">
                      {teacher.subjects && teacher.subjects.length > 0 && (
                        <div className="flex flex-wrap items-center justify-center lg:justify-start gap-2">
                          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-white/70 ml-0.5">
                            <BookOpen className="w-4 h-4" />
                            <span>المواد الدراسية</span>
                          </span>
                          {teacher.subjects.map((subject, idx) => (
                            <span
                              key={`subject-${idx}`}
                              className="inline-flex items-center gap-1.5 bg-white/15 text-white text-sm font-black px-3.5 py-1.5 rounded-lg border border-white/20 backdrop-blur-sm"
                            >
                              {subject}
                            </span>
                          ))}
                        </div>
                      )}

                      {teacher.grades && teacher.grades.length > 0 && (
                        <div className="flex flex-wrap items-center justify-center lg:justify-start gap-2">
                          <span className="inline-flex items-center gap-1.5 text-xs font-bold text-white/70 ml-0.5">
                            <GraduationCap className="w-4 h-4" />
                            <span>الصفوف الدراسية</span>
                          </span>
                          {teacher.grades.map((grade, idx) => (
                            <span
                              key={`grade-${idx}`}
                              className="inline-flex items-center gap-1.5 bg-white/15 text-white text-sm font-black px-3.5 py-1.5 rounded-lg border border-white/20 backdrop-blur-sm"
                            >
                              {grade}
                            </span>
                          ))}
                        </div>
                      )}
                    </div>
                  )}

                  {/* LEFT: social channels + support, side by side — the side
                      opposite the teacher's own data. */}
                  {(activeSocialLinks.length > 0 || (teacher.whatsappNumbers && teacher.whatsappNumbers.length > 0)) && (
                    <div className="shrink-0 flex flex-col lg:flex-row lg:items-end gap-4 text-center lg:text-right lg:border-r lg:border-white/20 lg:pr-6">
                      {activeSocialLinks.length > 0 && (
                        <div>
                          <h3 className="text-[11px] font-black tracking-wide text-white/70 mb-2.5">
                            قنوات الاتصال والشبكات الاجتماعية للمعلم
                          </h3>
                          <div className="flex flex-wrap items-center justify-center lg:justify-end gap-2">
                            {activeSocialLinks.map(({ platform, url }) => renderSocialIcon(platform, url))}
                          </div>
                        </div>
                      )}

                      {/* Per-teacher WhatsApp support numbers (admin-attached
                          assistants, e.g. "مسؤول المادة"). Renders nothing on
                          its own if the teacher has none configured. */}
                      <TeacherWhatsAppSupportButton numbers={teacher.whatsappNumbers || []} />
                    </div>
                  )}

                </div>
              </div>
            )}
          </div>
        </motion.div>
      </div>

      {/* 3. DEDICATED TEACHER COURSES GRID */}
      <div className="container mx-auto px-6 md:px-12 lg:px-16">
        
        {/* Header Block — short, professional section header. The old long
            descriptive paragraph was removed on purpose; the course count
            carries the only information a visitor actually needs here. */}
        <div className="mb-8 md:mb-10 text-right">
          <div className="inline-flex items-center gap-1.5 text-blue-600 dark:text-blue-400 font-black text-xs uppercase tracking-wider mb-2">
            <Sparkles className="w-3.5 h-3.5" />
            <span>المحتوى التعليمي المنشور</span>
          </div>

          <div className="flex flex-wrap items-center justify-start gap-x-4 gap-y-3">
            <h2 className="text-2xl md:text-4xl font-black text-slate-900 dark:text-white tracking-tight leading-none">
              كورسات ومحاضرات
            </h2>

            {teacherCourses.length > 0 && (
              <span className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-blue-50/80 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 text-xs font-black border border-blue-100 dark:border-blue-900/40 shadow-sm">
                <PlayCircle className="w-3.5 h-3.5" />
                <span>{teacherCourses.length} كورس متاح</span>
              </span>
            )}
          </div>

          {/* Short gradient rule keeps the heading from floating above the grid */}
          <div className="mt-4 h-1 w-16 rounded-full bg-gradient-to-l from-blue-600 via-indigo-500 to-blue-400" />
        </div>

        {/* Display grid */}
        {teacherCourses.length > 0 ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            {teacherCourses.map((course, index) => (
              <CourseCard
                key={course.id || index}
                course={course}
                isLoggedIn={isLoggedIn}
                onNavigate={onNavigate}
                onViewCourse={onViewCourse}
                index={index}
              />
            ))}
          </div>
        ) : (
          <div className="relative overflow-hidden bg-white dark:bg-slate-950 rounded-[32px] border border-slate-200/55 dark:border-slate-800/80 px-8 py-14 md:py-16 text-center max-w-xl mx-auto shadow-sm">
            {/* Soft accent lights, matching the cover/profile blocks above */}
            <div className="absolute -top-24 -right-20 w-56 h-56 bg-blue-500/10 dark:bg-blue-500/15 rounded-full blur-[90px] pointer-events-none" />
            <div className="absolute -bottom-24 -left-20 w-56 h-56 bg-indigo-500/10 dark:bg-indigo-500/15 rounded-full blur-[90px] pointer-events-none" />

            <div className="relative flex flex-col items-center justify-center gap-4">
              <div className="w-16 h-16 rounded-3xl bg-gradient-to-tr from-blue-600/10 to-indigo-500/10 dark:from-blue-500/15 dark:to-indigo-500/15 border border-blue-100 dark:border-blue-900/40 flex items-center justify-center">
                <BookOpen className="w-7 h-7 text-blue-600 dark:text-blue-400" />
              </div>
              <h3 className="text-lg md:text-xl font-black text-slate-900 dark:text-white tracking-tight">
                سيتم اضافه محتوى الكورس قريب اوي متستعجلش ⌛️❤️
              </h3>
            </div>
          </div>
        )}

      </div>

    </div>
  );
}
