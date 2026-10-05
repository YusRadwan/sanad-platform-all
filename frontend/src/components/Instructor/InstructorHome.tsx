
import { motion } from 'motion/react';
import { BookOpen, GraduationCap } from 'lucide-react';
import { authService } from '../../services/authService';
import { AVATAR_PLACEHOLDER } from '../../utils/imagePlaceholders';

export default function InstructorHome() {
  const user = authService.getUser();
  const currentTeacher = {
    name: user?.fullName || authService.getUsername() || 'مستخدم',
    full_name: user?.fullName || authService.getUsername() || 'مستخدم',
    // The photo the admin uploads as the "card image" on Admin/AddTeacher.tsx
    // is persisted to `users.avatar_url` (see CreateTeacher.php) and exposed
    // by PublicUser.php as `avatar_url` — the previous `avatarUrl` (camelCase)
    // read here didn't match that key, so it was always undefined.
    image: user?.avatar_url || '',
    // Attached to the login/me payload only for the instructor role — see
    // PublicUser.php's `$teacher?->grades`/`$teacher?->subjects`.
    subjects: Array.isArray(user?.subjects) ? user.subjects : [],
    grades: Array.isArray(user?.grades) ? user.grades : [],
  };

  return (
    <div className="w-full space-y-8">
    <motion.div
      key="instructor_default_home_section"
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -15 }}
      transition={{ duration: 0.2 }}
      className="w-full flex justify-center items-center py-6 text-right"
    >
      <div className="w-full flex flex-col-reverse lg:flex-row items-center justify-between gap-12 lg:gap-20 relative">

        {/* Decorative background elements */}
        <div className="absolute top-0 right-0 w-96 h-96 bg-blue-50/50 dark:bg-blue-900/10 rounded-full blur-3xl pointer-events-none -translate-y-1/2 translate-x-1/3" />
        <div className="absolute bottom-0 left-0 w-80 h-80 bg-indigo-50/50 dark:bg-indigo-900/10 rounded-full blur-3xl pointer-events-none translate-y-1/3 -translate-x-1/3" />

        {/* Text Content (Right side in RTL) */}
        <div className="flex-1 space-y-8 relative z-10 w-full">
          {/*
            The brand slot and the sub-headline paragraph that used to sit here
            were both empty elements — they rendered nothing but still occupied
            a `space-y-8` / `space-y-4` gap each, which is why the greeting sat
            oddly low. Removed as dead markup; no text was lost.
          */}
          <div className="space-y-6">
            <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black text-slate-900 dark:text-white leading-[1.3] tracking-tight">
              مرحباً بك مجدداً،<br/>
              <span className="text-transparent bg-clip-text bg-gradient-to-l from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 leading-normal">
                {currentTeacher.full_name || currentTeacher.name}
              </span>
            </h1>

            {/* Subject / grade summary — the teacher's own scope on the
                platform, surfaced as two labelled badge rows instead of a
                single crowded sentence. */}
            {(currentTeacher.subjects.length > 0 || currentTeacher.grades.length > 0) && (
              <div className="space-y-3">
                {currentTeacher.subjects.length > 0 && (
                  <div className="flex flex-wrap items-center gap-2.5">
                    <span className="shrink-0 flex items-center gap-1.5 text-xs font-black text-slate-400 dark:text-slate-500">
                      <BookOpen className="w-3.5 h-3.5" />
                      المادة العلمية
                    </span>
                    {currentTeacher.subjects.map((subject: string) => (
                      <span
                        key={subject}
                        className="px-3.5 py-1.5 rounded-full text-xs sm:text-sm font-bold bg-blue-50 text-blue-700 border border-blue-100 dark:bg-blue-500/10 dark:text-blue-400 dark:border-blue-500/20"
                      >
                        {subject}
                      </span>
                    ))}
                  </div>
                )}

                {currentTeacher.grades.length > 0 && (
                  <div className="flex flex-wrap items-center gap-2.5">
                    <span className="shrink-0 flex items-center gap-1.5 text-xs font-black text-slate-400 dark:text-slate-500">
                      <GraduationCap className="w-3.5 h-3.5" />
                      الصفوف الدراسية
                    </span>
                    {currentTeacher.grades.map((grade: string) => (
                      <span
                        key={grade}
                        className="px-3.5 py-1.5 rounded-full text-xs sm:text-sm font-bold bg-indigo-50 text-indigo-700 border border-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-400 dark:border-indigo-500/20"
                      >
                        {grade}
                      </span>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Large Profile Image/Avatar (Left side in RTL) - Enhanced Pop-out Style */}
        <div className="relative shrink-0 z-10 w-full sm:w-auto flex justify-center py-20 lg:py-0">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.8, ease: "easeOut" }}
            className="relative w-[340px] h-[340px] lg:w-[500px] lg:h-[500px] flex items-end justify-center mt-8 lg:mt-0"
          >
            {/* Background Glows (Lights) */}
            <div className="absolute top-[20%] w-[80%] h-[80%] bg-blue-600/30 dark:bg-blue-500/20 rounded-full blur-[100px] animate-pulse" />
            <div className="absolute top-[20%] w-[60%] h-[60%] bg-indigo-600/30 dark:bg-indigo-500/20 rounded-full blur-[80px] delay-700 animate-pulse" />

            {/* The Main Decorative Circle */}
            <motion.div
              animate={{
                scale: [1, 1.02, 1],
                rotate: [0, 2, 0]
              }}
              transition={{ duration: 6, repeat: Infinity, ease: "easeInOut" }}
              className="absolute bottom-0 left-[12%] right-[12%] aspect-square rounded-full bg-gradient-to-tr from-blue-600 to-indigo-600 shadow-[0_0_60px_rgba(37,99,235,0.4)] border-[8px] border-white dark:border-slate-800"
            />

            {/* Instructor Image Wrapper (Syncs Hover for both layers) */}
            <motion.div
              className="absolute inset-0 z-10"
              whileHover={{ y: -10 }}
              transition={{ type: "spring", stiffness: 300, damping: 20 }}
            >
              {/*
                Always renders the photo (the real admin-uploaded one, or the
                shared AVATAR_PLACEHOLDER when the teacher has none) — no more
                text/initial fallback filling the circle.
              */}
              <>
                {/* Layer 1: Bottom half (Clipped by circle) */}
                <div className="absolute bottom-[8px] left-[calc(12%+8px)] right-[calc(12%+8px)] aspect-square rounded-full overflow-hidden flex items-end justify-center">
                  <img
                    src={currentTeacher.image || AVATAR_PLACEHOLDER}
                    alt={currentTeacher.full_name || currentTeacher.name}
                    className="w-full h-[125%] object-cover object-bottom drop-shadow-[0_20px_50px_rgba(0,0,0,0.3)]"
                    onError={(e) => {
                      e.currentTarget.onerror = null;
                      e.currentTarget.src = AVATAR_PLACEHOLDER;
                    }}
                  />
                </div>

                {/* Layer 2: Top half (Pop-out, no limits) */}
                <div
                  className="absolute bottom-[8px] left-[calc(12%+8px)] right-[calc(12%+8px)] aspect-square flex items-end justify-center pointer-events-none"
                  style={{ clipPath: 'inset(-100% -100% 50% -100%)' }}
                >
                  <img
                    src={currentTeacher.image || AVATAR_PLACEHOLDER}
                    alt={currentTeacher.full_name || currentTeacher.name}
                    className="w-full h-[125%] object-cover object-bottom drop-shadow-[0_20px_50px_rgba(0,0,0,0.3)]"
                    onError={(e) => {
                      e.currentTarget.onerror = null;
                      e.currentTarget.src = AVATAR_PLACEHOLDER;
                    }}
                  />
                </div>
              </>
            </motion.div>
          </motion.div>
        </div>

      </div>
    </motion.div>
    </div>
  );
}
