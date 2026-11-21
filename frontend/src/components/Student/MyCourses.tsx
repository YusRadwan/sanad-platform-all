import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { MyCoursesProps } from '../../types/index';
import { studentService } from '../../services/studentService';
import StudentShell from './StudentShell';
import { SiteCourseCard, type SiteCourse } from '../site/catalog';
import { countCourses } from '../home/homeData';

/**
 * "كورساتي" — the student's enrolled courses, on the same card as the public
 * catalogue (no price, "افتح الكورس" instead).
 */
export default function MyCourses({ username }: MyCoursesProps) {
  const navigate = useNavigate();
  const [courses, setCourses] = useState<SiteCourse[] | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let alive = true;
    setFailed(false);
    studentService
      .getDashboard()
      .then((data) => {
        if (alive) setCourses(data.courses as SiteCourse[]);
      })
      .catch(() => {
        if (alive) {
          setFailed(true);
          setCourses([]);
        }
      });
    return () => {
      alive = false;
    };
  }, [username]);

  return (
    <StudentShell
      title="كورساتي"
      lead="الكورسات اللي إنت مشترك فيها"
      aside={courses && courses.length > 0 ? <span className="text-sm text-[color:var(--ink-3)]">{countCourses(courses.length)}</span> : undefined}
    >
      {courses === null ? (
        <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-[360px] rounded-2xl bg-[var(--paper-raised)]" />
          ))}
        </div>
      ) : failed ? (
        <p role="alert" className="m-0 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-14 text-center text-base text-[color:var(--ink-2)]">
          معرفناش نجيب كورساتك دلوقتي. حدّث الصفحة وجرّب تاني
        </p>
      ) : courses.length === 0 ? (
        <div className="flex flex-col items-start gap-4 rounded-2xl border border-dashed border-[var(--hair-strong)] px-6 py-12">
          <p className="m-0 text-[17px]">لسه مش مشترك في أي كورس</p>
          <p className="m-0 text-[15px] text-[color:var(--ink-2)]">اختار صفك وشوف الكورسات المتاحة، واشترك بالكود اللي هيوصلك بعد الدفع</p>
          <Link to="/courses" className="btn-primary h-11 px-5 text-[15px]">
            شوف الكورسات
          </Link>
        </div>
      ) : (
        <ul className="m-0 grid list-none gap-6 p-0 md:grid-cols-2 lg:grid-cols-3">
          {courses.map((c) => (
            <li key={c.id}>
              <SiteCourseCard course={c} owned actionLabel="افتح الكورس" onOpen={() => navigate(`/course/${c.id}`)} />
            </li>
          ))}
        </ul>
      )}
    </StudentShell>
  );
}
