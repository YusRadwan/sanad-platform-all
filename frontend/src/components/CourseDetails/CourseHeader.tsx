import { ArrowRight } from 'lucide-react';
import { AVATAR_PLACEHOLDER } from '../../utils/imagePlaceholders';
import { Price } from '../site/catalog';
import { hasRealImage, initialOf, subjectColor } from '../home/homeData';
import { useState } from 'react';

/**
 * Course page header — "كراسة سند" system.
 *
 * The cover is the course's real upload when there is one, else the same
 * notebook cover the catalogue uses (subject colour, ruled lines, a label
 * with the subject), so a course looks identical from card to page. The
 * facts a buyer needs sit beside it: grade and subject, title, description,
 * teacher (links to their page) and price. Publish/update dates are quiet
 * meta at the end.
 */
export default function CourseHeader({
  course,
  courseDates,
  formatDateArabic,
  teacherId,
  teacherImage,
  navigate,
  onBack,
}: any) {
  const [imgFailed, setImgFailed] = useState(false);
  const [avatarFailed, setAvatarFailed] = useState(false);
  const image = course.image || course.thumbnail_url;
  const showImage = hasRealImage(image) && !imgFailed;
  const showUpdatedAt = !!courseDates.updatedAt && courseDates.updatedAt !== courseDates.createdAt;
  const teacherName: string = course.teacher?.name ?? '';
  const avatar = teacherImage || course.teacher?.image;
  const hasAvatar = Boolean(avatar) && avatar !== AVATAR_PLACEHOLDER && !avatarFailed;

  const openTeacher = () => {
    if (!teacherId) return;
    navigate(`/teacher/${teacherId}`);
    window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior });
  };

  return (
    <div className="flex flex-col gap-6">
      {onBack && (
        <button
          type="button"
          onClick={onBack}
          className="link-draw inline-flex items-center gap-1.5 self-start text-[15px] text-slate-500 dark:text-slate-400 cursor-pointer"
        >
          <ArrowRight className="h-4 w-4" aria-hidden="true" />
          رجوع
        </button>
      )}

      <div className="grid items-start gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:gap-12">
        <div
          className="relative flex aspect-[16/11] items-center justify-center overflow-hidden rounded-[20px] shadow-[0_1px_2px_rgb(29_35_64/0.12),0_16px_32px_-20px_rgb(29_35_64/0.45)]"
          style={{ backgroundColor: subjectColor(course.subject) }}
        >
          {showImage ? (
            <img
              src={image}
              alt=""
              className="h-full w-full object-cover"
              referrerPolicy="no-referrer"
              onError={() => setImgFailed(true)}
            />
          ) : (
            <>
              <span aria-hidden="true" className="ruled absolute inset-0" />
              <span className="relative rounded-xl bg-white px-6 py-4 text-center text-[#1D2340]">
                <span className="block font-kufi text-[32px] font-semibold leading-[1.35]">{course.subject || course.title}</span>
                {course.grade && <span className="block text-sm text-[#5A5F73]">{course.grade}</span>}
              </span>
            </>
          )}
        </div>

        <div className="flex flex-col gap-5">
          {(course.grade || course.subject) && (
            <span className="text-[15px] text-slate-500 dark:text-slate-400">
              {[course.grade, course.subject].filter(Boolean).join(' · ')}
            </span>
          )}
          <h1 className="m-0 font-kufi text-[32px] font-semibold leading-[1.35] sm:text-[40px]">
            <bdi>{course.title}</bdi>
          </h1>
          {course.description && (
            <p className="m-0 max-w-[60ch] text-[17px] leading-[1.8] text-slate-600 dark:text-slate-300">
              <bdi>{course.description}</bdi>
            </p>
          )}

          {teacherName && (
            <button
              type="button"
              onClick={openTeacher}
              disabled={!teacherId}
              className="group inline-flex items-center gap-3 self-start rounded-xl py-1 text-start cursor-pointer disabled:cursor-default"
            >
              {hasAvatar ? (
                <img
                  src={avatar}
                  alt=""
                  className="h-12 w-12 rounded-full object-cover"
                  onError={() => setAvatarFailed(true)}
                />
              ) : (
                <span
                  aria-hidden="true"
                  className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-[var(--paper-raised)] font-kufi text-xl font-semibold"
                >
                  {initialOf(teacherName)}
                </span>
              )}
              <span className="flex flex-col">
                <span className="text-[13px] text-slate-500 dark:text-slate-400">المدرس</span>
                <span className="text-[17px] font-medium group-enabled:group-hover:underline underline-offset-4">{teacherName}</span>
              </span>
            </button>
          )}

          <div className="flex flex-wrap items-baseline gap-x-6 gap-y-2 border-t border-[var(--hair)] pt-5">
            <Price course={course} size="lg" />
            <span className="text-sm text-slate-500 dark:text-slate-400">
              {courseDates.createdAt ? `اتنشر ${formatDateArabic(courseDates.createdAt)}` : ''}
              {showUpdatedAt ? ` · آخر تحديث ${formatDateArabic(courseDates.updatedAt)}` : ''}
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}
