import { useState, type ReactNode } from 'react';
import type { Course } from '../../types/course';
import type { Teacher } from '../../types/teacher';
import { formatPrice, hasRealImage, initialOf, shortGrade, subjectColor } from '../home/homeData';

/**
 * Shared building blocks of the "كراسة سند" public site: page wrap, section
 * title, grade picker, price, notebook cover, course card and teacher card.
 * Used by the homepage, /courses and /teachers so a course or a teacher looks
 * the same everywhere.
 */

export type SiteCourse = Course & { grade?: string; subject?: string; teacher?: { name: string; image: string } };

export function Wrap({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`mx-auto w-full max-w-[1200px] px-5 sm:px-8 ${className}`}>{children}</div>;
}

export function SectionTitle({ id, children, lead }: { id: string; children: ReactNode; lead?: string }) {
  return (
    <div className="flex flex-col gap-2">
      <h2 id={id} className="m-0 text-[28px] sm:text-[36px] font-semibold leading-[1.35]">
        {children}
      </h2>
      {lead && <p className="m-0 text-base sm:text-[17px] text-[color:var(--ink-2)] max-w-[60ch]">{lead}</p>}
    </div>
  );
}

export function GradePicker({
  grades,
  grade,
  onPick,
  variant,
  labelledBy,
  allLabel,
}: {
  grades: string[];
  grade: string;
  onPick: (g: string) => void;
  variant: 'hero' | 'tabs';
  labelledBy: string;
  /** Adds a leading "all grades" option whose value is ''. */
  allLabel?: string;
}) {
  const options = allLabel ? ['', ...grades] : grades;
  return (
    <div
      role="group"
      aria-labelledby={labelledBy}
      className={variant === 'hero' ? 'grid grid-cols-2 min-[380px]:grid-cols-3 gap-2' : 'flex flex-wrap gap-2'}
    >
      {options.map((g) => {
        const on = g === grade;
        const base =
          variant === 'hero'
            ? 'h-12 rounded-[10px] px-2 text-[15px]'
            : 'h-10 rounded-full px-4 text-sm';
        const tone = on
          ? variant === 'hero'
            ? 'bg-[var(--sanad-ink)] text-white border-[var(--sanad-ink)] font-medium dark:bg-[var(--sanad-ink-on-dark)] dark:text-[#12142A] dark:border-[var(--sanad-ink-on-dark)]'
            : 'bg-[var(--ink)] text-[color:var(--paper)] border-[var(--ink)] font-medium'
          : 'bg-[var(--paper-card)] text-[color:var(--ink)] border-[var(--hair-strong)] hover:border-[rgb(53_68_225/0.45)]';
        return (
          <button
            key={g || '__all'}
            type="button"
            aria-pressed={on}
            onClick={() => onPick(g)}
            className={`${base} ${tone} border cursor-pointer transition-colors duration-150`}
          >
            {g === '' ? allLabel : shortGrade(g)}
          </button>
        );
      })}
    </div>
  );
}

export function Price({ course, size }: { course: SiteCourse; size: 'sm' | 'lg' }) {
  const price = Number(course.price ?? 0);
  const discountRaw = course.discountPrice ?? course.discount_price;
  const discount = discountRaw !== undefined && discountRaw !== null ? Number(discountRaw) : undefined;
  const hasDiscount = discount !== undefined && discount < price;
  const now = hasDiscount ? discount! : price;

  if (now === 0) {
    return <span className={size === 'lg' ? 'text-xl font-semibold' : 'text-base font-semibold'}>مجاني</span>;
  }

  return (
    <span className="inline-flex items-baseline gap-1.5 [font-variant-numeric:tabular-nums]">
      <span className={size === 'lg' ? 'text-2xl font-semibold' : 'text-base font-semibold'}>{formatPrice(now)}</span>
      <span className={size === 'lg' ? 'text-sm opacity-80' : 'text-[13px] opacity-90'}>ج.م</span>
      {hasDiscount && size === 'lg' && (
        <s className="text-sm text-[color:var(--ink-3)]" aria-label={`بدل ${formatPrice(price)} جنيه`}>
          {formatPrice(price)}
        </s>
      )}
    </span>
  );
}

/** The notebook cover — the page's one rich moment. */
export function Cover({ course, onOpen }: { course: SiteCourse; onOpen: () => void }) {
  const subject = course.subject || course.title;
  return (
    <button
      type="button"
      onClick={onOpen}
      aria-label={`${course.title} — ${subject}، ${course.teacher?.name ?? ''}`}
      className="lift relative flex aspect-[3/4] w-full flex-col justify-between gap-3 overflow-hidden rounded-[14px] p-3.5 text-start text-white cursor-pointer shadow-[0_1px_2px_rgb(29_35_64/0.14),0_10px_24px_-14px_rgb(29_35_64/0.45)]"
      style={{ backgroundColor: subjectColor(course.subject) }}
    >
      <span aria-hidden="true" className="ruled absolute inset-0" />
      <span className="relative text-xs text-white/85">{course.grade ? shortGrade(course.grade) : ''}</span>
      <span className="relative flex flex-col gap-0.5 rounded-[10px] bg-white p-3 text-[#1D2340]">
        <span className="font-kufi text-[18px] sm:text-[20px] font-semibold leading-[1.35] line-clamp-2 [overflow-wrap:anywhere]">{subject}</span>
        <span className="text-[13px] leading-normal text-[#5A5F73] line-clamp-2">{course.title}</span>
      </span>
      <span className="relative flex items-baseline justify-between gap-2">
        <span className="text-[13px] truncate">{course.teacher?.name}</span>
        <Price course={course} size="sm" />
      </span>
    </button>
  );
}

export function SiteCourseCard({ course, onOpen }: { course: SiteCourse; onOpen: () => void }) {
  const [imgFailed, setImgFailed] = useState(false);
  const image = course.image || course.thumbnail_url;
  const showImage = hasRealImage(image) && !imgFailed;
  const subject = course.subject || course.title;
  const teacherName = course.teacher?.name ?? '';

  return (
    <article className="lift flex h-full flex-col overflow-hidden rounded-2xl border border-[var(--hair-strong)] bg-[var(--paper-card)]">
      <div
        className="relative flex h-[132px] items-center justify-center overflow-hidden"
        style={{ backgroundColor: subjectColor(course.subject) }}
      >
        {showImage ? (
          <img
            src={image}
            alt=""
            loading="lazy"
            decoding="async"
            referrerPolicy="no-referrer"
            onError={() => setImgFailed(true)}
            className="h-full w-full object-cover"
          />
        ) : (
          <>
            <span aria-hidden="true" className="ruled absolute inset-0" />
            <span className="relative rounded-[10px] bg-white px-4 py-2.5 text-center font-kufi text-2xl font-semibold leading-[1.35] text-[#1D2340]">
              {subject}
            </span>
          </>
        )}
      </div>
      <div className="flex flex-grow flex-col gap-3 p-5">
        {course.grade && <span className="text-[13px] text-[color:var(--ink-3)]">{course.grade}</span>}
        <h3 className="m-0 text-[19px] font-medium leading-[1.4]">
          <bdi>{course.title}</bdi>
        </h3>
        {teacherName && (
          <div className="flex items-center gap-2 text-sm text-[color:var(--ink-2)]">
            <span
              aria-hidden="true"
              className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[var(--paper-raised)] text-[13px] font-medium text-[color:var(--ink)]"
            >
              {initialOf(teacherName)}
            </span>
            <span>{teacherName}</span>
          </div>
        )}
        {course.description && (
          <p className="m-0 text-sm text-[color:var(--ink-2)] line-clamp-2">
            <bdi>{course.description}</bdi>
          </p>
        )}
        <div className="mt-auto flex items-center justify-between gap-3 border-t border-[var(--hair)] pt-4">
          <Price course={course} size="lg" />
          <button type="button" onClick={onOpen} className="btn-outline h-10 px-4 text-sm">
            تفاصيل الكورس
          </button>
        </div>
      </div>
    </article>
  );
}

export function SiteTeacherCard({ teacher, onOpen }: { teacher: Teacher; onOpen: () => void }) {
  const [failed, setFailed] = useState(false);
  const name = teacher.full_name || teacher.name || '';
  const photo = (teacher as Teacher & { profile_photo_url?: string }).profile_photo_url || teacher.avatar_url;
  const subjects = (teacher.subjects ?? []).join('، ');
  const grades = (teacher.grades ?? []).map(shortGrade).join('، ');

  return (
    <article className="lift flex h-full flex-col gap-4 rounded-2xl border border-[var(--hair-strong)] bg-[var(--paper-card)] p-6">
      <div className="flex items-center gap-3.5">
        {photo && !failed ? (
          <img
            src={photo}
            alt=""
            loading="lazy"
            decoding="async"
            referrerPolicy="no-referrer"
            onError={() => setFailed(true)}
            className="h-14 w-14 shrink-0 rounded-full object-cover"
          />
        ) : (
          <span
            aria-hidden="true"
            className="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[var(--paper-raised)] font-kufi text-2xl font-semibold text-[color:var(--ink)]"
          >
            {initialOf(name)}
          </span>
        )}
        <div className="flex min-w-0 flex-col">
          <h3 className="m-0 text-lg font-medium leading-[1.4]">{name}</h3>
          {subjects && <span className="text-sm text-[color:var(--sanad-ink)] dark:text-[color:var(--sanad-ink-on-dark)]">{subjects}</span>}
        </div>
      </div>
      {grades && <p className="m-0 text-sm text-[color:var(--ink-2)]">{grades}</p>}
      <button type="button" onClick={onOpen} className="btn-quiet mt-auto h-10 text-sm">
        صفحة المدرس
      </button>
    </article>
  );
}

