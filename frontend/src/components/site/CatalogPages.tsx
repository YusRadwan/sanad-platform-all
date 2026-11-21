import { useEffect, useMemo, useState } from 'react';
import { courseService } from '../../services/courseService';
import { catalogService } from '../../services/catalogService';
import type { Teacher } from '../../types/teacher';
import { FALLBACK_GRADES, countCourses, sortGrades } from '../home/homeData';
import { GradePicker, SiteCourseCard, SiteTeacherCard, Wrap, type SiteCourse } from './catalog';

/**
 * /courses and /teachers in the "كراسة سند" system. Same cards and grade
 * picker as the homepage; here the picker also has "كل الصفوف" and the lists
 * are complete instead of a preview.
 */

function useGrades() {
  const [grades, setGrades] = useState<string[]>(FALLBACK_GRADES);
  useEffect(() => {
    let alive = true;
    catalogService
      .list('grades')
      .then((items) => {
        const names = items.map((i) => i.name).filter(Boolean);
        if (alive && names.length > 0) setGrades(sortGrades(names));
      })
      .catch(() => {});
    return () => {
      alive = false;
    };
  }, []);
  return grades;
}

function PageHead({ title, lead }: { title: string; lead: string }) {
  return (
    <div className="flex flex-col gap-2">
      <h1 className="m-0 font-kufi text-[34px] font-semibold leading-[1.35] sm:text-[44px]">{title}</h1>
      <p className="m-0 max-w-[60ch] text-base text-[color:var(--ink-2)] sm:text-[17px]">{lead}</p>
    </div>
  );
}

export function CoursesCatalogPage({ onViewCourse }: { onViewCourse: (id: string | number) => void }) {
  const grades = useGrades();
  const [grade, setGrade] = useState('');
  const [courses, setCourses] = useState<SiteCourse[] | null>(null);

  useEffect(() => {
    let alive = true;
    courseService.getAllCourses(1, 100).then((res) => {
      if (alive) setCourses(res.courses as SiteCourse[]);
    });
    return () => {
      alive = false;
    };
  }, []);

  const shown = useMemo(
    () => (courses ?? []).filter((c) => grade === '' || c.grade === grade),
    [courses, grade]
  );

  return (
    <div className="sanad-home">
      <Wrap className="flex flex-col gap-8 py-12 sm:py-16">
        <PageHead title="كل الكورسات" lead="اختار صفك عشان تشوف كورساته، وكل كورس قدامك بسعره واسم مدرسه" />
        <div className="flex flex-wrap items-center justify-between gap-4">
          <span id="catalog-grade-label" className="sr-only">اختار الصف</span>
          <GradePicker
            grades={grades}
            grade={grade}
            onPick={setGrade}
            variant="tabs"
            labelledBy="catalog-grade-label"
            allLabel="كل الصفوف"
          />
          {courses && <span className="text-sm text-[color:var(--ink-3)]" aria-live="polite">{countCourses(shown.length)}</span>}
        </div>
        {courses === null ? (
          <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
            {[0, 1, 2, 3, 4, 5].map((i) => (
              <div key={i} className="h-[360px] rounded-2xl bg-[var(--paper-raised)]" />
            ))}
          </div>
        ) : shown.length === 0 ? (
          <p className="m-0 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-14 text-center text-base text-[color:var(--ink-2)]">
            {grade ? `لسه مافيش كورسات لـ${grade}. جرّب صف تاني أو ارجع قريب` : 'لسه مافيش كورسات منشورة. ارجع قريب'}
          </p>
        ) : (
          <ul className="m-0 grid list-none gap-6 p-0 md:grid-cols-2 lg:grid-cols-3">
            {shown.map((c) => (
              <li key={c.id}>
                <SiteCourseCard course={c} onOpen={() => onViewCourse(c.id)} />
              </li>
            ))}
          </ul>
        )}
      </Wrap>
    </div>
  );
}

export function TeachersCatalogPage({
  teachers,
  onViewProfile,
}: {
  teachers: Teacher[];
  onViewProfile: (id: string) => void;
}) {
  const grades = useGrades();
  const [grade, setGrade] = useState('');
  const active = teachers.filter((t) => t.isActive !== false);
  const shown = active.filter((t) => grade === '' || (t.grades ?? []).includes(grade));

  return (
    <div className="sanad-home">
      <Wrap className="flex flex-col gap-8 py-12 sm:py-16">
        <PageHead title="كل المدرسين" lead="كل مدرس بمادته والصفوف اللي بيدرّسها. افتح صفحته عشان تشوف كورساته" />
        <div className="flex flex-wrap items-center justify-between gap-4">
          <span id="teachers-grade-label" className="sr-only">اختار الصف</span>
          <GradePicker
            grades={grades}
            grade={grade}
            onPick={setGrade}
            variant="tabs"
            labelledBy="teachers-grade-label"
            allLabel="كل الصفوف"
          />
        </div>
        {shown.length === 0 ? (
          <p className="m-0 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-14 text-center text-base text-[color:var(--ink-2)]">
            {active.length === 0 ? 'المدرسين هيظهروا هنا أول ما ينضموا للمنصة' : `مفيش مدرسين لـ${grade} لسه`}
          </p>
        ) : (
          <ul className="m-0 grid list-none grid-cols-1 gap-6 p-0 sm:grid-cols-2 lg:grid-cols-4">
            {shown.map((t) => (
              <li key={t.id}>
                <SiteTeacherCard teacher={t} onOpen={() => onViewProfile(String(t.id))} />
              </li>
            ))}
          </ul>
        )}
      </Wrap>
    </div>
  );
}
