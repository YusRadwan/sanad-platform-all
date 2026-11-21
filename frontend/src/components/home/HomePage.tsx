import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router';
import { ArrowLeft, BookOpen, ClipboardCheck, UserRound, Smartphone } from 'lucide-react';
import { courseService } from '../../services/courseService';
import { catalogService } from '../../services/catalogService';
import type { Course } from '../../types/course';
import type { Teacher } from '../../types/teacher';
import {
  FALLBACK_GRADES,
  GRADE_STORAGE_KEY,
  countCourses,
  formatPrice,
  hasRealImage,
  initialOf,
  shortGrade,
  sortGrades,
  subjectColor,
} from './homeData';

/**
 * Public homepage — "كراسة سند" redesign (approved canvas, Nov 2026).
 *
 * The page is a CATALOGUE first: a visitor picks their grade in the hero and
 * immediately sees that grade's courses (with prices) and teachers, before any
 * signup. Sections, in order: hero + grade picker, courses by grade, teachers,
 * how to subscribe, why Sanad, join as a teacher.
 *
 * Data: teachers come from App (the same list the /teachers page uses);
 * courses are fetched here with one request of up to 100, so filtering by
 * grade happens on the whole published catalogue rather than on the first
 * page of 12. The grade list is the admin-managed catalogue.
 */

interface HomePageProps {
  teachers: Teacher[];
  isLoggedIn: boolean;
  onViewCourse: (id: string | number) => void;
  onViewProfile: (id: string) => void;
  onNavigate: (view: string) => void;
}

type HomeCourse = Course & { grade?: string; subject?: string; teacher?: { name: string; image: string } };

export default function HomePage({ teachers, isLoggedIn, onViewCourse, onViewProfile, onNavigate }: HomePageProps) {
  const [grades, setGrades] = useState<string[]>(FALLBACK_GRADES);
  const [courses, setCourses] = useState<HomeCourse[] | null>(null);
  const [picked, setPicked] = useState<string | null>(() => {
    try {
      return localStorage.getItem(GRADE_STORAGE_KEY);
    } catch {
      return null;
    }
  });

  useEffect(() => {
    let alive = true;
    catalogService
      .list('grades')
      .then((items) => {
        const names = items.map((i) => i.name).filter(Boolean);
        if (alive && names.length > 0) setGrades(sortGrades(names));
      })
      .catch(() => {});
    courseService.getAllCourses(1, 100).then((res) => {
      if (alive) setCourses(res.courses as HomeCourse[]);
    });
    return () => {
      alive = false;
    };
  }, []);

  // The grade shown: the visitor's own pick if it still exists, else the
  // grade with the most published courses (ties keep catalogue order), so a
  // first visit opens on the fullest shelf rather than an empty one.
  const grade = useMemo(() => {
    if (picked && grades.includes(picked)) return picked;
    let best = grades[0];
    let bestCount = 0;
    for (const g of grades) {
      const n = (courses ?? []).filter((c) => c.grade === g).length;
      if (n > bestCount) {
        best = g;
        bestCount = n;
      }
    }
    return best;
  }, [picked, grades, courses]);

  const pick = (g: string) => {
    setPicked(g);
    try {
      localStorage.setItem(GRADE_STORAGE_KEY, g);
    } catch {
      /* private mode: the pick still works for this visit */
    }
  };

  // Header links point at "/#courses" etc. React Router changes the URL but
  // never scrolls, so do it here (also covers arriving from another page).
  const { hash } = useLocation();
  useEffect(() => {
    if (!hash) return;
    const el = document.getElementById(decodeURIComponent(hash.slice(1)));
    if (el) el.scrollIntoView({ block: 'start' });
  }, [hash]);

  const gradeCourses = useMemo(() => (courses ?? []).filter((c) => c.grade === grade), [courses, grade]);
  const activeTeachers = teachers.filter((t) => t.isActive !== false);

  return (
    <div className="sanad-home">
      <Hero
        grades={grades}
        grade={grade}
        onPick={pick}
        courses={gradeCourses}
        loading={courses === null}
        isLoggedIn={isLoggedIn}
        onViewCourse={onViewCourse}
      />
      <CoursesByGrade
        grades={grades}
        grade={grade}
        onPick={pick}
        courses={gradeCourses}
        loading={courses === null}
        onViewCourse={onViewCourse}
      />
      <Teachers teachers={activeTeachers} onViewProfile={onViewProfile} />
      <HowToSubscribe />
      <WhySanad />
      <JoinAsTeacher onNavigate={onNavigate} />
    </div>
  );
}

/* ------------------------------------------------------------------------ */

function Wrap({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return <div className={`mx-auto w-full max-w-[1200px] px-5 sm:px-8 ${className}`}>{children}</div>;
}

function SectionTitle({ id, children, lead }: { id: string; children: React.ReactNode; lead?: string }) {
  return (
    <div className="flex flex-col gap-2">
      <h2 id={id} className="m-0 text-[28px] sm:text-[36px] font-semibold leading-[1.35]">
        {children}
      </h2>
      {lead && <p className="m-0 text-base sm:text-[17px] text-[color:var(--ink-2)] max-w-[60ch]">{lead}</p>}
    </div>
  );
}

function GradePicker({
  grades,
  grade,
  onPick,
  variant,
  labelledBy,
}: {
  grades: string[];
  grade: string;
  onPick: (g: string) => void;
  variant: 'hero' | 'tabs';
  labelledBy: string;
}) {
  return (
    <div
      role="group"
      aria-labelledby={labelledBy}
      className={variant === 'hero' ? 'grid grid-cols-2 min-[380px]:grid-cols-3 gap-2' : 'flex flex-wrap gap-2'}
    >
      {grades.map((g) => {
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
            key={g}
            type="button"
            aria-pressed={on}
            onClick={() => onPick(g)}
            className={`${base} ${tone} border cursor-pointer transition-colors duration-150`}
          >
            {shortGrade(g)}
          </button>
        );
      })}
    </div>
  );
}

function Price({ course, size }: { course: HomeCourse; size: 'sm' | 'lg' }) {
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
function Cover({ course, onOpen }: { course: HomeCourse; onOpen: () => void }) {
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

/* ------------------------------------------------------------------------ */

function Hero({
  grades,
  grade,
  onPick,
  courses,
  loading,
  isLoggedIn,
  onViewCourse,
}: {
  grades: string[];
  grade: string;
  onPick: (g: string) => void;
  courses: HomeCourse[];
  loading: boolean;
  isLoggedIn: boolean;
  onViewCourse: (id: string | number) => void;
}) {
  const shown = courses.slice(0, 3);
  return (
    <section aria-labelledby="hero-title" className="pt-8 pb-16 sm:pt-16 sm:pb-24">
      <Wrap className="grid items-center gap-10 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,1fr)] lg:gap-16">
        <div className="flex flex-col gap-6">
          <h1
            id="hero-title"
            className="enter m-0 font-kufi font-semibold text-[38px] leading-[1.4] sm:text-[48px] lg:text-[56px] lg:leading-[1.35]"
          >
            كورسات مدرسين الإعدادي والثانوي، في مكان <span className="marker">واحد</span>
          </h1>
          <p className="enter enter-2 m-0 text-[17px] sm:text-[19px] text-[color:var(--ink-2)] max-w-[34ch]">
            اختار صفك، وشوف مدرسينك وكورساتهم بأسعارها قبل ما تعمل حساب
          </p>
          <div className="enter enter-3 mt-1 flex flex-col gap-3">
            <span id="hero-grade-label" className="text-sm font-medium text-[color:var(--ink-2)]">
              إنت في سنة كام؟
            </span>
            <GradePicker grades={grades} grade={grade} onPick={onPick} variant="hero" labelledBy="hero-grade-label" />
          </div>
          {isLoggedIn ? (
            <Link to="/student" className="link-draw self-start text-[15px] text-[color:var(--ink-2)]">
              كورساتي
            </Link>
          ) : (
            <Link to="/login" className="link-draw self-start text-[15px] text-[color:var(--ink-2)]">
              عندك حساب؟ ادخل
            </Link>
          )}
        </div>

        <div
          className="enter enter-4 flex flex-col gap-5 rounded-[20px] border border-[var(--hair)] bg-[var(--paper-card)] p-5 sm:p-6"
          aria-live="polite"
        >
          <div className="flex items-baseline justify-between gap-3">
            <span className="text-[17px] font-medium">كورسات {grade}</span>
            <span className="text-sm text-[color:var(--ink-3)]">{loading ? '' : countCourses(courses.length)}</span>
          </div>
          {loading ? (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3" aria-hidden="true">
              {[0, 1, 2].map((i) => (
                <div key={i} className={`aspect-[3/4] rounded-[14px] bg-[var(--paper-raised)] ${i === 2 ? 'hidden sm:block lg:hidden xl:block' : ''}`} />
              ))}
            </div>
          ) : shown.length > 0 ? (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3">
              {shown.map((c, i) => (
                <div key={c.id} className={i === 2 ? 'hidden sm:block lg:hidden xl:block' : ''}>
                  <Cover course={c} onOpen={() => onViewCourse(c.id)} />
                </div>
              ))}
            </div>
          ) : (
            <p className="m-0 rounded-[14px] border border-dashed border-[var(--hair-strong)] px-6 py-10 text-center text-[15px] text-[color:var(--ink-2)]">
              لسه مافيش كورسات للصف ده. أول ما مدرس ينزّل كورس هيظهر هنا
            </p>
          )}
          <a href="#courses" className="link-draw inline-flex items-center gap-1.5 self-start text-[15px] font-medium text-[color:var(--sanad-ink)] dark:text-[color:var(--sanad-ink-on-dark)]">
            كل كورسات {shortGrade(grade)}
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          </a>
        </div>
      </Wrap>
    </section>
  );
}

function CoursesByGrade({
  grades,
  grade,
  onPick,
  courses,
  loading,
  onViewCourse,
}: {
  grades: string[];
  grade: string;
  onPick: (g: string) => void;
  courses: HomeCourse[];
  loading: boolean;
  onViewCourse: (id: string | number) => void;
}) {
  return (
    <section
      id="courses"
      aria-labelledby="courses-title"
      className="scroll-mt-24 border-y border-[var(--hair)] bg-[var(--paper-card)] py-16 sm:py-24"
    >
      <Wrap className="flex flex-col gap-8">
        <div className="flex flex-wrap items-end justify-between gap-5">
          <SectionTitle
            id="courses-title"
            lead="كل كورس قدامك بسعره واسم مدرسه، وتقدر تشوف تفاصيله قبل ما تشترك"
          >
            الكورسات <span className="marker">حسب صفك</span>
          </SectionTitle>
          <span id="courses-grade-label" className="sr-only">اختار الصف</span>
          <GradePicker grades={grades} grade={grade} onPick={onPick} variant="tabs" labelledBy="courses-grade-label" />
        </div>

        {loading ? (
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
            {[0, 1, 2].map((i) => (
              <div key={i} className="h-[360px] rounded-2xl bg-[var(--paper-raised)]" />
            ))}
          </div>
        ) : courses.length === 0 ? (
          <p className="m-0 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-14 text-center text-base text-[color:var(--ink-2)]">
            لسه مافيش كورسات لـ{grade}. جرّب صف تاني أو ارجع قريب
          </p>
        ) : (
          <ul className="m-0 grid list-none gap-6 p-0 md:grid-cols-2 lg:grid-cols-3">
            {courses.map((c) => (
              <li key={c.id}>
                <CourseCardNew course={c} onOpen={() => onViewCourse(c.id)} />
              </li>
            ))}
          </ul>
        )}

        <Link to="/courses" className="btn-quiet h-11 self-start px-5 text-[15px]">
          كل الكورسات
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        </Link>
      </Wrap>
    </section>
  );
}

function CourseCardNew({ course, onOpen }: { course: HomeCourse; onOpen: () => void }) {
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

function Teachers({ teachers, onViewProfile }: { teachers: Teacher[]; onViewProfile: (id: string) => void }) {
  // Full rows only on desktop (4 a row): 8, else 4, else everyone.
  const shown = teachers.length >= 8 ? teachers.slice(0, 8) : teachers.length >= 4 ? teachers.slice(0, 4) : teachers;
  return (
    <section id="teachers" aria-labelledby="teachers-title" className="scroll-mt-24 py-16 sm:py-24">
      <Wrap className="flex flex-col gap-8">
        <div className="flex flex-wrap items-end justify-between gap-5">
          <SectionTitle id="teachers-title" lead="كل مدرس بمادته والصفوف اللي بيدرّسها">
            اختار <span className="marker">مدرسك</span>
          </SectionTitle>
          {shown.length > 0 && (
            <Link to="/teachers" className="link-draw text-[15px] font-medium text-[color:var(--sanad-ink)] dark:text-[color:var(--sanad-ink-on-dark)]">
              كل المدرسين
            </Link>
          )}
        </div>
        {shown.length === 0 ? (
          <p className="m-0 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-14 text-center text-base text-[color:var(--ink-2)]">
            المدرسين هيظهروا هنا أول ما ينضموا للمنصة
          </p>
        ) : (
          <ul className="m-0 grid list-none grid-cols-1 gap-6 p-0 sm:grid-cols-2 lg:grid-cols-4">
            {shown.map((t) => (
              <li key={t.id}>
                <TeacherCardNew teacher={t} onOpen={() => onViewProfile(String(t.id))} />
              </li>
            ))}
          </ul>
        )}
      </Wrap>
    </section>
  );
}

function TeacherCardNew({ teacher, onOpen }: { teacher: Teacher; onOpen: () => void }) {
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

const STEPS = [
  { title: 'اختار الكورس', body: 'حدد صفك ومادتك، وشوف تفاصيل الكورس وسعره' },
  { title: 'اطلب كود الاشتراك', body: 'كل كورس ليه كود اشتراك، تطلبه من الدعم أو من مدرسك على واتساب' },
  { title: 'اكتب الكود في صفحة الكورس', body: 'من صفحة الكورس دوس «اشترك» واكتب الكود' },
  { title: 'ابدأ تذاكر', body: 'الشرح والمراجعات والامتحانات في مكان واحد، من الموبايل أو اللابتوب' },
];

function HowToSubscribe() {
  return (
    <section
      id="how"
      aria-labelledby="how-title"
      className="scroll-mt-24 border-y border-[var(--hair)] bg-[var(--paper-card)] py-16 sm:py-24"
    >
      <Wrap className="flex flex-col gap-10">
        <SectionTitle id="how-title" lead="أربع خطوات، ومن الموبايل أو اللابتوب">
          إزاي <span className="marker">تشترك</span>؟
        </SectionTitle>
        <ol className="m-0 grid list-none gap-8 p-0 sm:grid-cols-2 lg:grid-cols-4 lg:gap-6">
          {STEPS.map((s, i) => (
            <li key={s.title} className="flex flex-col gap-3">
              <span
                aria-hidden="true"
                className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-[var(--marker)] text-[17px] font-semibold text-[#1D2340]"
              >
                {i + 1}
              </span>
              <h3 className="m-0 text-lg font-medium">
                <span className="sr-only">الخطوة {i + 1}: </span>
                {s.title}
              </h3>
              <p className="m-0 text-[15px] text-[color:var(--ink-2)]">{s.body}</p>
            </li>
          ))}
        </ol>
      </Wrap>
    </section>
  );
}

const WHY = [
  { icon: BookOpen, title: 'المنهج كله', body: 'شرح وتدريبات بتغطي المنهج، مقسمة خطوة بخطوة' },
  { icon: ClipboardCheck, title: 'امتحانات وواجبات', body: 'تتابع بيها مستواك أول بأول' },
  { icon: UserRound, title: 'مدرسين بالاسم', body: 'بتختار مدرسك بنفسك وتشوف كورساته قبل ما تدفع' },
  { icon: Smartphone, title: 'من البيت وفي وقتك', body: 'على الموبايل أو اللابتوب، من غير مواعيد ولا مواصلات' },
];

function WhySanad() {
  return (
    <section aria-labelledby="why-title" className="py-16 sm:py-24">
      <Wrap className="flex flex-col gap-10">
        <SectionTitle id="why-title">
          ليه <span className="marker">سند</span>؟
        </SectionTitle>
        <ul className="m-0 grid list-none gap-x-12 gap-y-6 p-0 md:grid-cols-2">
          {WHY.map((w) => (
            <li key={w.title} className="flex gap-4 border-t border-[var(--hair-strong)] pt-5">
              <w.icon
                className="mt-1 h-[22px] w-[22px] shrink-0 text-[color:var(--sanad-ink)] dark:text-[color:var(--sanad-ink-on-dark)]"
                strokeWidth={1.6}
                aria-hidden="true"
              />
              <div>
                <h3 className="m-0 mb-1 text-lg font-medium">{w.title}</h3>
                <p className="m-0 text-[15px] text-[color:var(--ink-2)]">{w.body}</p>
              </div>
            </li>
          ))}
        </ul>
      </Wrap>
    </section>
  );
}

function JoinAsTeacher({ onNavigate }: { onNavigate: (view: string) => void }) {
  return (
    <section id="join" aria-labelledby="join-title" className="scroll-mt-24 bg-[#1D2340] text-white">
      <Wrap className="grid items-center gap-8 py-16 sm:py-[72px] lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div className="flex flex-col gap-3">
          <h2 id="join-title" className="m-0 text-[26px] sm:text-[32px] font-semibold leading-[1.4]">
            بتدرّس إعدادي أو ثانوي؟ انضم لمدرسين سند
          </h2>
          <p className="m-0 text-base sm:text-[17px] text-[#C9CCDA] max-w-[56ch]">
            ارفع كورساتك وفيديوهاتك وامتحاناتك، وتابع طلابك وأرباحك من لوحة تحكم واحدة
          </p>
        </div>
        <div className="flex lg:justify-end">
          <button type="button" onClick={() => onNavigate('teacher-application')} className="btn-on-ink h-12 px-6 text-base">
            قدّم كمدرس
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </Wrap>
    </section>
  );
}
