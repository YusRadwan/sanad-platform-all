import { useEffect, useState } from 'react';
import { ArrowRight } from 'lucide-react';
import { TeacherProfilePageProps } from '../types/index';
import { safeUrl } from '../utils/urlValidation';
import { AVATAR_PLACEHOLDER } from '../utils/imagePlaceholders';
import { getSocialPlatformIcon } from '../utils/socialPlatformIcon';
import { courseService } from '../services/courseService';
import TeacherWhatsAppSupportButton from './TeacherWhatsAppSupportButton';
import { SiteCourseCard, Wrap, type SiteCourse } from './site/catalog';
import { countCourses, initialOf, shortGrade } from './home/homeData';

const SOCIAL_LABELS: Record<string, string> = {
  facebook: 'فيسبوك',
  youtube: 'يوتيوب',
  whatsapp: 'واتساب',
  telegram: 'تليجرام',
  tiktok: 'تيك توك',
  instagram: 'انستجرام',
  twitter: 'تويتر',
  'x (twitter)': 'تويتر',
  linkedin: 'لينكدإن',
  snapchat: 'سناب شات',
};

/**
 * Public teacher page — "كراسة سند" system.
 *
 * Who the teacher is (photo or initial, name, subjects, grades, bio, social
 * and WhatsApp support), then every published course of theirs with the same
 * card the catalogue uses.
 *
 * Courses: the page used to filter App's first page of 12 courses by teacher
 * NAME (or by the name appearing in a course title), so a teacher with
 * courses beyond that page, or two teachers with one name, showed the wrong
 * list. It now loads the published catalogue (up to 100) and matches on
 * teacher_id. `courses` from props is the instant first paint.
 */
export default function TeacherProfilePage({ teacher, courses, onBack, onViewCourse }: TeacherProfilePageProps) {
  const [allCourses, setAllCourses] = useState<SiteCourse[] | null>(null);
  const [photoFailed, setPhotoFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    courseService.getAllCourses(1, 100).then((res) => {
      if (alive) setAllCourses(res.courses as SiteCourse[]);
    });
    return () => {
      alive = false;
    };
  }, []);

  if (!teacher) return null;

  const teacherId = String(teacher.id);
  const name = teacher.full_name || teacher.name || '';
  const photo = (teacher as typeof teacher & { profile_photo_url?: string }).profile_photo_url || teacher.avatar_url;
  const hasPhoto = Boolean(photo) && photo !== AVATAR_PLACEHOLDER && !photoFailed;
  const source = (allCourses ?? (courses as SiteCourse[]) ?? []) as SiteCourse[];
  const teacherCourses = source.filter((c) => String(c.teacher_id ?? '') === teacherId);
  const socials = (teacher.socialLinks || []).filter(
    (l) => l.enabled !== false && typeof l.url === 'string' && l.url.trim() !== ''
  );
  const whatsapp = teacher.whatsappNumbers || [];

  return (
    <div className="sanad-home">
      <Wrap className="flex flex-col gap-12 py-10 sm:py-14">
        {onBack && (
          <button
            type="button"
            onClick={onBack}
            className="link-draw inline-flex items-center gap-1.5 self-start text-[15px] text-[color:var(--ink-2)] cursor-pointer"
          >
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
            رجوع
          </button>
        )}

        <section aria-labelledby="teacher-name" className="grid items-start gap-8 md:grid-cols-[auto_minmax(0,1fr)] md:gap-10">
          {hasPhoto ? (
            <img
              src={photo}
              alt=""
              referrerPolicy="no-referrer"
              onError={() => setPhotoFailed(true)}
              className="h-36 w-36 rounded-full object-cover sm:h-44 sm:w-44"
            />
          ) : (
            <span
              aria-hidden="true"
              className="inline-flex h-36 w-36 items-center justify-center rounded-full bg-[var(--paper-raised)] font-kufi text-6xl font-semibold sm:h-44 sm:w-44"
            >
              {initialOf(name)}
            </span>
          )}

          <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
              {teacher.subjects?.length > 0 && (
                <span className="text-[15px] text-[color:var(--sanad-ink)] dark:text-[color:var(--sanad-ink-on-dark)]">
                  {teacher.subjects.join('، ')}
                </span>
              )}
              <h1 id="teacher-name" className="m-0 font-kufi text-[34px] font-semibold leading-[1.35] sm:text-[44px]">
                {name}
              </h1>
              {teacher.grades?.length > 0 && (
                <span className="text-[15px] text-[color:var(--ink-2)]">{teacher.grades.map(shortGrade).join('، ')}</span>
              )}
            </div>

            {teacher.bio && (
              <p className="m-0 max-w-[62ch] whitespace-pre-line text-[17px] leading-[1.8] text-[color:var(--ink-2)]">{teacher.bio}</p>
            )}

            {(socials.length > 0 || whatsapp.length > 0) && (
              <div className="flex flex-wrap items-center gap-2">
                {whatsapp.length > 0 && <TeacherWhatsAppSupportButton numbers={whatsapp} />}
                {socials.map((l) => {
                  const Icon = getSocialPlatformIcon(l.platform);
                  const label = SOCIAL_LABELS[(l.platform || '').toLowerCase().trim()] || l.platform;
                  return (
                    <a
                      key={`${l.platform}-${l.url}`}
                      href={safeUrl(l.url)}
                      target="_blank"
                      rel="noopener noreferrer"
                      aria-label={label}
                      title={label}
                      className="inline-flex h-11 w-11 items-center justify-center rounded-[10px] bg-[var(--paper-raised)] text-[color:var(--ink-2)] transition-colors hover:text-[color:var(--ink)]"
                    >
                      <Icon className="h-[18px] w-[18px]" strokeWidth={1.6} aria-hidden="true" />
                    </a>
                  );
                })}
              </div>
            )}
          </div>
        </section>

        <section aria-labelledby="teacher-courses" className="flex flex-col gap-6 border-t border-[var(--hair)] pt-10">
          <div className="flex items-baseline justify-between gap-4">
            <h2 id="teacher-courses" className="m-0 text-[28px] font-semibold leading-[1.35]">
              كورسات <span className="marker">{name}</span>
            </h2>
            {allCourses && <span className="text-sm text-[color:var(--ink-3)]">{countCourses(teacherCourses.length)}</span>}
          </div>
          {allCourses === null && teacherCourses.length === 0 ? (
            <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
              {[0, 1, 2].map((i) => (
                <div key={i} className="h-[360px] rounded-2xl bg-[var(--paper-raised)]" />
              ))}
            </div>
          ) : teacherCourses.length === 0 ? (
            <p className="m-0 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-14 text-center text-base text-[color:var(--ink-2)]">
              {name} لسه منزّلش كورسات على المنصة
            </p>
          ) : (
            <ul className="m-0 grid list-none gap-6 p-0 md:grid-cols-2 lg:grid-cols-3">
              {teacherCourses.map((c) => (
                <li key={c.id}>
                  <SiteCourseCard course={c} onOpen={() => onViewCourse?.(c.id)} />
                </li>
              ))}
            </ul>
          )}
        </section>
      </Wrap>
    </div>
  );
}
