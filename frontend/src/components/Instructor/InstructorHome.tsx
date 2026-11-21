import { Link } from 'react-router';
import { BookOpen, Video, ClipboardList, Users, BarChart3, Wallet } from 'lucide-react';
import { authService } from '../../services/authService';
import { AVATAR_PLACEHOLDER } from '../../utils/imagePlaceholders';
import { initialOf, shortGrade } from '../home/homeData';
import { useState } from 'react';

/**
 * Instructor home — calm dashboard variant of the "كراسة سند" system.
 *
 * Was: a greeting headline ("مرحباً بك مجدداً") with gradient text beside a
 * 500px floating, glowing avatar on an infinite loop. Now: who you are on the
 * platform (name, subjects, grades) and the six tasks a teacher comes here
 * for, one click each. The sidebar keeps the full list.
 */
const TASKS = [
  { to: '/instructor/course-management', label: 'الكورسات', body: 'إضافة كورس وتعديل بياناته وسعره', icon: BookOpen },
  { to: '/instructor/video-management', label: 'الفيديوهات', body: 'رفع الفيديوهات وترتيبها في الدروس', icon: Video },
  { to: '/instructor/exams-assignments-management', label: 'الامتحانات والواجبات', body: 'إنشاء امتحان أو واجب ومتابعة نتايجه', icon: ClipboardList },
  { to: '/instructor/subscriptions', label: 'المشتركين', body: 'الطلاب المشتركين في كورساتك', icon: Users },
  { to: '/instructor/statistics', label: 'الإحصائيات', body: 'أداء كورساتك وتوزيع طلابك', icon: BarChart3 },
  { to: '/instructor/revenue', label: 'الأرباح', body: 'إيراداتك وكشف الحساب', icon: Wallet },
];

export default function InstructorHome() {
  const user = authService.getUser();
  const name: string = user?.fullName || authService.getUsername() || '';
  const photo: string = user?.avatar_url || '';
  const subjects: string[] = Array.isArray(user?.subjects) ? user.subjects : [];
  const grades: string[] = Array.isArray(user?.grades) ? user.grades : [];
  const [failed, setFailed] = useState(false);
  const hasPhoto = Boolean(photo) && photo !== AVATAR_PLACEHOLDER && !failed;

  return (
    <div className="flex w-full flex-col gap-10 py-2">
      <section className="flex flex-wrap items-center gap-5">
        {hasPhoto ? (
          <img src={photo} alt="" onError={() => setFailed(true)} className="h-20 w-20 rounded-full object-cover" />
        ) : (
          <span aria-hidden="true" className="inline-flex h-20 w-20 items-center justify-center rounded-full bg-slate-100 font-kufi text-3xl font-semibold dark:bg-slate-800">
            {initialOf(name)}
          </span>
        )}
        <div className="flex flex-col gap-1">
          <h1 className="m-0 font-kufi text-[30px] font-semibold leading-[1.35] sm:text-[36px]">{name}</h1>
          {(subjects.length > 0 || grades.length > 0) && (
            <p className="m-0 text-[15px] text-slate-500 dark:text-slate-400">
              {[subjects.join('، '), grades.map(shortGrade).join('، ')].filter(Boolean).join(' · ')}
            </p>
          )}
        </div>
      </section>

      <section aria-labelledby="tasks-title" className="flex flex-col gap-4">
        <h2 id="tasks-title" className="m-0 text-lg font-semibold">ابدأ من هنا</h2>
        <ul className="m-0 grid list-none gap-4 p-0 sm:grid-cols-2 xl:grid-cols-3">
          {TASKS.map((t) => (
            <li key={t.to}>
              <Link
                to={t.to}
                className="lift flex h-full items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 text-inherit no-underline dark:border-slate-800 dark:bg-slate-900"
              >
                <t.icon className="mt-0.5 h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" strokeWidth={1.6} aria-hidden="true" />
                <span className="flex flex-col gap-1">
                  <span className="text-base font-semibold">{t.label}</span>
                  <span className="text-sm text-slate-500 dark:text-slate-400">{t.body}</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}
