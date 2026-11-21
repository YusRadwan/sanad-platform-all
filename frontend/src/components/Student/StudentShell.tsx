import type { ReactNode } from 'react';
import { NavLink } from 'react-router';

/**
 * Shared frame for the student area ("كراسة سند" system): page title, a
 * short lead, and the student's sections as tabs. Those sections used to be
 * reachable only from the account dropdown in the header.
 */
const TABS = [
  { to: '/student', label: 'كورساتي', end: true },
  { to: '/student/statistics', label: 'مستوايا' },
  { to: '/student/watched-videos', label: 'الفيديوهات' },
  { to: '/student/completed-exams', label: 'الامتحانات' },
  { to: '/student/center', label: 'طالب سنتر' },
];

export default function StudentShell({
  title,
  lead,
  aside,
  children,
}: {
  title: string;
  lead?: string;
  aside?: ReactNode;
  children: ReactNode;
}) {
  return (
    <div className="sanad-home min-h-[calc(100vh-72px)]">
      <div className="mx-auto w-full max-w-[1200px] px-5 py-10 sm:px-8 sm:py-14">
        <nav aria-label="حسابي" className="-mx-1 mb-8 overflow-x-auto">
          <ul className="m-0 flex list-none gap-1 p-1">
            {TABS.map((t) => (
              <li key={t.to}>
                <NavLink
                  to={t.to}
                  end={t.end}
                  className={({ isActive }) =>
                    `inline-flex h-10 items-center whitespace-nowrap rounded-full px-4 text-sm transition-colors ${
                      isActive
                        ? 'bg-[var(--ink)] text-[color:var(--paper)] font-medium'
                        : 'text-[color:var(--ink-2)] hover:bg-[var(--paper-raised)] hover:text-[color:var(--ink)]'
                    }`
                  }
                >
                  {t.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
          <div className="flex flex-col gap-1">
            <h1 className="m-0 font-kufi text-[32px] font-semibold leading-[1.35] sm:text-[40px]">{title}</h1>
            {lead && <p className="m-0 text-base text-[color:var(--ink-2)] sm:text-[17px]">{lead}</p>}
          </div>
          {aside}
        </div>
        {children}
      </div>
    </div>
  );
}
