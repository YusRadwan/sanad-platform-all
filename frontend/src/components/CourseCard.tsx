import { memo, useState, useEffect } from 'react';
import { useNavigate } from 'react-router';
import { motion } from 'motion/react';
import { Sparkles, CheckCircle2, Tag, Loader2, CalendarDays, Clock } from 'lucide-react';
import { CourseCardProps } from '../types/index';
import { authService } from '../services/authService';
import { courseService } from '../services/courseService';
import { AVATAR_PLACEHOLDER, COURSE_IMAGE_PLACEHOLDER } from '../utils/imagePlaceholders';
import { formatDate } from '../utils/dateUtils';
import { formatNumber } from '../utils/formatMoney';
import EnrollmentCodeModal, { useEnrollmentCodeRedemption } from './EnrollmentCodeModal';

function CourseCard({ course, isLoggedIn, onViewCourse, index = 1 }: CourseCardProps) {
  const [isSubscribed, setIsSubscribed] = useState<boolean>(false);
  const [isCheckingSubscription, setIsCheckingSubscription] = useState<boolean>(true);
  const navigate = useNavigate();

  // The enrollment-code flow is the SHARED one (see EnrollmentCodeModal.tsx),
  // identical to the course-details page's. Opening it from the card only
  // changes where it is triggered from — the submit still sends the code
  // alone and the server still resolves the course from the code's own row.
  const {
    modalOpen, openModal, closeModal,
    code, setCode,
    submitting,
    error, setError,
    success,
    handleRedeem,
  } = useEnrollmentCodeRedemption({ onEnrolled: () => setIsSubscribed(true) });

  // Check subscription status using real API call
  const userRole = authService.getRole() || '';
  
  useEffect(() => {
    let active = true;
    const checkSubscription = async () => {
      if (isLoggedIn && userRole === 'student') {
        try {
          setIsCheckingSubscription(true);
          const enrolled = await courseService.getMyCourses();
          if (active) {
            const hasSubscription = enrolled.some((c: any) => String(c.id) === String(course.id));
            setIsSubscribed(hasSubscription);
          }
        } catch (error) {
          if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) console.error("Error checking subscription:", error);
        } finally {
          if (active) {
            setIsCheckingSubscription(false);
          }
        }
      } else {
        if (active) {
          setIsSubscribed(false);
          setIsCheckingSubscription(false);
        }
      }
    };
    checkSubscription();
    return () => {
      active = false;
    };
  }, [course.id, isLoggedIn, userRole]);

  // Navigates immediately. There used to be a 1.2s "جاري الانتقال..." banner
  // here that did nothing but delay the click; it is gone.
  const handleViewCourse = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (onViewCourse) {
      onViewCourse(course.id);
    } else {
      navigate(`/course/${course.id}`);
    }
  };

  const handleSubscribe = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (!isLoggedIn) {
      // A logged-out visitor cannot enrol: send them to the login screen,
      // matching CourseDetails/CourseEnrollmentAction.tsx's logged-out CTA.
      navigate('/login');
    } else {
      // A logged-in student redeems the code right here — no detour through
      // the course-details page just to reach the same dialog.
      openModal();
    }
  };

  // Both action buttons sit SIDE BY SIDE now, so each one gets `flex-1` and a
  // 44px floor rather than `w-full` — the row is shorter without ever falling
  // below a comfortable mobile tap target.
  const actionButtonBase =
    'flex-1 min-h-[44px] px-2.5 py-2.5 rounded-2xl font-black text-xs md:text-sm leading-tight flex items-center justify-center gap-1.5 text-center';

  const renderSubscribeButton = () => {
    if (!isLoggedIn) {
      return (
        <button
          onClick={handleSubscribe}
          title="سجّل للاشتراك"
          className={`${actionButtonBase} bg-white text-slate-700 hover:bg-indigo-600 hover:text-white transition-all duration-300 transform hover:-translate-y-0.5 active:scale-98 cursor-pointer shadow-sm hover:shadow-indigo-500/10`}
        >
          <span>سجّل للاشتراك</span>
        </button>
      );
    }

    if (userRole === 'student') {
      if (isCheckingSubscription) {
        return (
          <button
            disabled
            title="جاري التحقق من الاشتراك"
            className={`${actionButtonBase} bg-white/80 text-slate-400 border border-white/40`}
          >
            <Loader2 className="w-3.5 h-3.5 shrink-0 animate-spin" />
            <span>جاري التحقق...</span>
          </button>
        );
      }

      if (isSubscribed) {
        return (
          <button
            disabled
            title="أنت مشترك بالفعل في هذا الكورس"
            className={`${actionButtonBase} bg-white text-emerald-600 border border-emerald-200/60`}
          >
            <CheckCircle2 className="w-3.5 h-3.5 shrink-0 text-emerald-500" />
            <span>أنت مشترك بالفعل</span>
          </button>
        );
      }
    }

    // Default for logged in and not subscribed, or other roles
    return (
      <button
        onClick={handleSubscribe}
        title="اشترك الآن في الكورس"
        className={`${actionButtonBase} text-white cursor-pointer`}
        style={{ backgroundColor: '#3544e1' }}
      >
        <span>اشترك الآن</span>
      </button>
    );
  };

  const originalPrice = course.price !== undefined && course.price !== null ? Number(course.price) : 0;
  const discountPriceVal = course.discountPrice !== undefined ? course.discountPrice : course.discount_price;
  const discountPrice = discountPriceVal !== undefined && discountPriceVal !== null ? Number(discountPriceVal) : undefined;
  const hasDiscount = discountPrice !== undefined && discountPrice >= 0 && originalPrice > discountPrice;
  const discountPercentage = hasDiscount ? Math.round(((originalPrice - discountPrice!) / originalPrice) * 100) : 0;

  // Presentation-only guard: most records are stored with updated_at === created_at
  // until the course is actually edited, so an "آخر تحديث" line identical to the
  // publish date is redundant noise. The underlying data is untouched.
  const createdTime = course.created_at ? new Date(course.created_at).getTime() : NaN;
  const updatedTime = course.updated_at ? new Date(course.updated_at).getTime() : NaN;
  const showUpdatedAt = !!course.updated_at
    && course.updated_at !== course.created_at
    && !(Number.isFinite(createdTime) && Number.isFinite(updatedTime) && createdTime === updatedTime);

  return (
    <motion.div
      initial={{ opacity: 0, y: 30 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, ease: 'easeOut', delay: index * 0.05 }}
      whileHover={{ y: -10, scale: 1.015 }}
      className="group relative mt-24 md:mt-28 w-full max-w-[92%] mx-auto"
    >
      {/* Ambient colour bloom — a soft blurred glow behind the whole card,
          only visible on hover. Single brand-blue tone (not the previous
          indigo/fuchsia/emerald mix) to stay calm and match the site's own
          palette instead of reading as a separate rainbow accent. */}
      <div className="absolute -inset-4 rounded-[36px] bg-blue-500/30 dark:bg-blue-500/20 blur-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-700 pointer-events-none -z-10" />

      {/* Floating thumbnail — deliberately OUTSIDE the gradient-ring frame
          below (a sibling before it, not nested inside it), so it genuinely
          straddles the card's outer edge rather than just the inner white
          box: top half sits above/outside the frame, bottom half sits over
          it. Fixed heights (not aspect-video) so the -mt/-mb offsets below
          can be exactly half the image's own height at every breakpoint —
          that exact 50/50 split is what makes it a "floating" thumbnail
          instead of a thumbnail that merely peeks out unevenly. */}
      <div className="relative w-[92%] mx-auto h-48 md:h-56 -mt-24 md:-mt-28 -mb-24 md:-mb-28 z-20 rounded-2xl overflow-hidden border-[3px] border-white dark:border-slate-900 shadow-xl transition-all duration-500 group-hover:shadow-2xl group-hover:shadow-indigo-500/30 group-hover:-translate-y-1.5">
        <img
          src={course.image}
          alt={course.title}
          className="w-full h-full object-cover transform group-hover:scale-110 group-hover:rotate-1 transition-all duration-700 ease-in-out"
          referrerPolicy="no-referrer"
          // course.image already defaults to COURSE_IMAGE_PLACEHOLDER when
          // empty (courseService.ts) — this catches the OTHER failure mode:
          // a real URL that 404s because the uploaded file no longer exists
          // on the server. `onerror = null` stops a loop if the placeholder
          // data: URI itself ever failed (it can't, but belt and braces).
          onError={(e) => {
            e.currentTarget.onerror = null;
            e.currentTarget.src = COURSE_IMAGE_PLACEHOLDER;
          }}
        />
        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/20 to-transparent pointer-events-none" />
        {/* Colour wash that blooms in on hover, matching the halo/ring hue */}
        <div className="absolute inset-0 bg-blue-600/0 group-hover:bg-blue-600/20 transition-all duration-500 pointer-events-none" />

        {/* Discount Badge — gently pulses so a sale never goes unnoticed */}
        {hasDiscount && (
          <motion.div
            initial={{ scale: 0.8, opacity: 0 }}
            animate={{ scale: [1, 1.06, 1], opacity: 1 }}
            transition={{ opacity: { delay: 0.2 }, scale: { delay: 0.6, duration: 1.8, repeat: Infinity, ease: 'easeInOut' } }}
            className="absolute top-1.5 right-1.5 z-20 bg-rose-600 text-white font-black text-[10px] px-2 py-1 rounded-lg flex items-center gap-1 shadow-lg shadow-rose-900/30 ring-1 ring-white/25"
          >
            <Sparkles className="w-2.5 h-2.5 text-rose-100" />
            <span>خصم {discountPercentage}%</span>
          </motion.div>
        )}
      </div>

      {/* Gradient-ring border: a thin, ALWAYS-visible colour ring (not just a
          hover reveal) via the classic "gradient padding" trick — an outer
          box painted with the gradient, an inner box painted white/slate that
          leaves only `p-[1.5px]` of the outer showing as a ring. Single
          brand-blue tone (not the previous indigo/purple/emerald mix) to
          read as calm and consistent with the site's own palette. */}
      {/* Ring is now ALWAYS fully opaque (no `opacity-70` on the wrapper) —
          that opacity used to sit on the div that also contains the white
          card as a child, so it was washing out the ENTIRE card, not just
          the thin ring, which is the "transparency" this was fixed for.
          Hover now only intensifies the shadow, not the ring's alpha. */}
      <div className="relative rounded-[26px] p-[1.5px] bg-gradient-to-br from-blue-300 via-blue-400 to-blue-500 dark:from-blue-600 dark:via-blue-500 dark:to-blue-400">
        <div
          className="relative flex flex-col rounded-[24.5px] p-2 pt-24 md:pt-28 overflow-hidden shadow-lg group-hover:shadow-2xl group-hover:shadow-blue-500/25 transition-shadow duration-500"
          style={{
            // Same background as TeacherCard.tsx's card, so the two card
            // families read as one consistent design language site-wide.
            background:
              'radial-gradient(120% 90% at 15% -10%, rgba(224,250,255,0.55) 0%, rgba(224,250,255,0) 42%), ' +
              'linear-gradient(165deg, #082a63 0%, #0f3f8f 32%, #1f74bd 66%, #52cfe0 100%)',
          }}
        >

          {/* Content Wrapper */}
          <div className="flex flex-col flex-grow relative z-10 w-full px-1">

            <div className="flex flex-col w-full text-center items-center mb-1.5">

              {/* Grade & Subject — plain text labels, no icons and no pill
                  chrome, separated by a thin vertical divider. */}
              {(course.grade || course.subject) && (
                <div className="flex items-center justify-center gap-2.5 mb-3">
                  {course.grade && (
                    <span className="text-xs font-black tracking-wide text-white">{course.grade}</span>
                  )}
                  {course.grade && course.subject && (
                    <span className="w-px h-3 bg-white/30" />
                  )}
                  {course.subject && (
                    <span className="text-xs font-black tracking-wide text-white">{course.subject}</span>
                  )}
                </div>
              )}

              {/* Title on the right, teacher block raised back up onto the
                  same row, sitting on the opposite (left) side. `min-w-0` on
                  the title keeps `line-clamp` truncating correctly inside the
                  flex row instead of forcing an overflow. */}
              <div className="flex items-center justify-between gap-2 w-full mb-1">
                <h3 className="flex-1 min-w-0 text-right text-xl md:text-2xl font-black text-white leading-snug line-clamp-2 transition-all duration-300 group-hover:text-blue-100">
                  {course.title}
                </h3>

                {course.teacher && (
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation();
                      if (course.teacher_id) {
                        navigate(`/teacher/${course.teacher_id}`);
                      }
                    }}
                    title={course.teacher_id ? `عرض الملف الشخصي لـ ${course.teacher.name}` : undefined}
                    className={`flex items-center gap-2 shrink-0 p-1 pr-2.5 rounded-full border transition-all duration-300 ${
                      course.teacher_id
                        ? 'cursor-pointer bg-white/10 border-white/20 shadow-sm hover:border-white/35 hover:bg-white/15 active:scale-95'
                        : 'cursor-default bg-transparent border-transparent'
                    }`}
                  >
                    <img
                      src={course.teacher.image || AVATAR_PLACEHOLDER}
                      alt={course.teacher.name}
                      className="w-9 h-9 rounded-full object-cover ring-2 ring-white/70 shadow-sm shrink-0 transition-all duration-300"
                      onError={(e) => {
                        e.currentTarget.onerror = null;
                        e.currentTarget.src = AVATAR_PLACEHOLDER;
                      }}
                    />
                    <span className="text-base md:text-lg font-extrabold text-white leading-tight whitespace-nowrap transition-colors duration-300">
                      {course.teacher.name}
                    </span>
                  </button>
                )}
              </div>

              {/* Description — right below the title, explicitly right-aligned. */}
              {course.description && (
                <p className="w-full text-right text-blue-100/80 text-[11px] leading-relaxed line-clamp-2 font-medium mb-1.5 px-1">
                  {course.description}
                </p>
              )}

              {/* Price + publication metadata on the SAME row now: dates on
                  the right (in a `flex-1` slot so it always reserves the
                  space and pushes price to the true edge even when empty),
                  price as a compact gradient pill pinned to the LEFT. */}
              <div className="w-full flex items-center gap-2 mt-1">
                <div className="flex-1 min-w-0 flex flex-col items-start gap-0.5 text-[10px] leading-4 font-semibold text-blue-200/80">
                  {course.created_at && (
                    <span className="inline-flex items-center gap-1 whitespace-nowrap">
                      <CalendarDays className="w-2.5 h-2.5 shrink-0 opacity-70" strokeWidth={2.5} />
                      <span>نُشر: {formatDate(course.created_at)}</span>
                    </span>
                  )}
                  {showUpdatedAt && (
                    <span className="inline-flex items-center gap-1 whitespace-nowrap">
                      <Clock className="w-2.5 h-2.5 shrink-0 opacity-70" strokeWidth={2.5} />
                      <span>آخر تحديث: {formatDate(course.updated_at!)}</span>
                    </span>
                  )}
                </div>

                <div className="shrink-0 relative rounded-xl overflow-hidden">
                  {hasDiscount ? (
                    <div className="relative bg-gradient-to-r from-rose-500 to-rose-600 px-3 py-1.5 shadow-lg shadow-rose-500/25">
                      <div className="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/25 to-transparent -translate-x-full group-hover:animate-shimmer" />
                      <div className="relative flex items-center gap-1.5">
                        <span className="text-lg font-black text-white leading-none tracking-tight tabular-nums">
                          {formatNumber(discountPrice, { maximumFractionDigits: 2 })}
                          <span className="text-[10px] font-extrabold text-white/75 mr-0.5">ج.م</span>
                        </span>
                        <span className="line-through decoration-white/50 text-white/60 text-xs font-bold tabular-nums">
                          {formatNumber(originalPrice, { maximumFractionDigits: 2 })}
                        </span>
                      </div>
                    </div>
                  ) : originalPrice > 0 ? (
                    <div className="relative bg-gradient-to-r from-blue-600 via-blue-500 to-blue-600 px-3 py-1.5 shadow-lg shadow-blue-500/25">
                      <div className="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/25 to-transparent -translate-x-full group-hover:animate-shimmer" />
                      <div className="relative flex items-center gap-1.5">
                        <Tag className="w-3.5 h-3.5 text-white/80 shrink-0" />
                        <span className="text-lg font-black text-white leading-none tracking-tight tabular-nums">
                          {formatNumber(originalPrice, { maximumFractionDigits: 2 })}
                          <span className="text-[10px] font-extrabold text-white/75 mr-0.5">ج.م</span>
                        </span>
                      </div>
                    </div>
                  ) : (
                    <div className="relative bg-gradient-to-r from-emerald-500 via-teal-500 to-emerald-600 px-3 py-1.5 shadow-lg shadow-emerald-500/25">
                      <div className="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/25 to-transparent -translate-x-full group-hover:animate-shimmer" />
                      <div className="relative flex items-center gap-1.5">
                        <Sparkles className="w-4 h-4 text-white shrink-0" />
                        <span className="text-sm font-black text-white tracking-tight whitespace-nowrap">
                          مجاني بالكامل
                        </span>
                      </div>
                    </div>
                  )}
                </div>
              </div>

            </div>

            {/* Action Buttons — side by side, to keep the card short */}
            <div className="w-full pt-2.5 mt-auto border-t border-white/15 flex flex-row items-stretch gap-2">
              <button
                onClick={handleViewCourse}
                title="عرض تفاصيل الكورس"
                className={`${actionButtonBase} text-white cursor-pointer`}
                style={{ backgroundColor: '#3544e1' }}
              >
                <span>عرض التفاصيل</span>
              </button>

              {renderSubscribeButton()}
            </div>
          </div>
        </div>
      </div>

      {/* Enrollment-code dialog — the shared flow, opened straight from the card */}
      <EnrollmentCodeModal
        open={modalOpen}
        onClose={closeModal}
        courseTitle={course.title}
        code={code}
        setCode={setCode}
        submitting={submitting}
        error={error}
        setError={setError}
        success={success}
        onRedeem={handleRedeem}
      />
    </motion.div>
  );
}

export default memo(CourseCard);
