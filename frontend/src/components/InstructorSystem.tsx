import React, { useState } from 'react';
import { AnimatePresence } from 'motion/react';
import { Outlet, useNavigate, useLocation } from 'react-router';
import PlatformLogo from './PlatformLogo';
import {
  BookOpen, Users, FileText, Link as LinkIcon, ListTodo, Library, Database,
  LayoutDashboard, Menu, X, ChevronDown, LogOut, GraduationCap, CreditCard,
  Video, BarChart3, DollarSign, PanelRightClose, PanelRightOpen,
  Building2, ClipboardCheck, ClipboardList, Unlock, Wallet, PieChart, MonitorPlay, UserCog,
} from 'lucide-react';
import { INSTRUCTOR_EMPLOYEE_PERMISSIONS } from '../constants/employeePermissions';

import Sidebar from './Sidebar';
import Avatar from './Layout/Avatar';
import DarkModeToggle from './Layout/DarkModeToggle';
import AccountMenuPanel, {
  accountMenuItemClass as menuItemClass,
  accountMenuIconClass as menuIconClass,
} from './Layout/AccountMenuPanel';
import SidebarNavItem, {
  SidebarNavHeading,
  SidebarNavRailDivider,
  type SidebarNavEntry,
  type SidebarNavGroup,
} from './Layout/SidebarNavItem';
import { InstructorSystemProps } from '../types/index';
import { settingsService } from '../services/settingsService';
import { authService } from '../services/authService';

/**
 * Instructor sidebar navigation — single source of truth for order, labels,
 * icons and routes.
 *
 * This replaces fourteen hand-copied <button> blocks, each carrying its own
 * `isXActive` const and its own inline copy of the active/inactive classes.
 * They had begun to drift from the equivalent admin rows, so both sidebars now
 * render through the shared Layout/SidebarNavItem component. The two ITEM
 * LISTS stay entirely separate — this is a rendering change, not a merge of
 * instructor and admin permissions.
 *
 * Every `path` below is the same route the corresponding button navigated to
 * before, in the same order.
 *
 * TWO items that used to be here are deliberately absent:
 *   - الواجبات — the assignments feature is being removed platform-wide.
 *   - الإشعار الرئيسي (/instructor/announcements) — announcement AUTHORING is
 *     no longer an instructor capability. Platform-wide broadcasting remains an
 *     admin-only screen (/admin/broadcast-notifications), which is a different
 *     feature backed by a different service.
 * Note that إدارة الامتحانات والتعيينات below is a DIFFERENT screen
 * (/instructor/exams-assignments-management, exam scheduling/assignment to
 * groups — not the removed homework feature) and is untouched.
 */
/**
 * decision 10.39 — split into categories, matching AdminSystem.tsx's own
 * grouped sidebar (which already reads as five clear sections). This used
 * to be ONE flat 16-item "القائمة الرئيسية" list; the items/order/paths/
 * icons below are all unchanged, only the grouping is new.
 *
 * The "نظام السنتر" group is still built separately so it can be dropped
 * entirely for a teacher whose `centerEnabled` is off — see its use below.
 */
const INSTRUCTOR_NAV_GROUPS: SidebarNavGroup[] = [
  {
    heading: 'القائمة الرئيسية',
    items: [
      { path: '/instructor', label: 'الصفحة الرئيسية', icon: LayoutDashboard, exact: true },
      { path: '/instructor/statistics', label: 'الإحصائيات', icon: BarChart3 },
    ],
  },
  {
    heading: 'إدارة المحتوى',
    items: [
      { path: '/instructor/course-structure', label: 'إدارة الكورسات', icon: Library },
      { path: '/instructor/course-management', label: 'إضافة وتعديل الكورسات', icon: BookOpen },
      { path: '/instructor/groups-management', label: 'إضافة وتعديل المجموعات', icon: Users },
      { path: '/instructor/video-management', label: 'إضافة وتعديل الفيديوهات', icon: Video },
      { path: '/instructor/notes-management', label: 'إضافة وتعديل المذكرات', icon: FileText },
      { path: '/instructor/links-management', label: 'إضافة وتعديل اللينكات', icon: LinkIcon },
    ],
  },
  {
    heading: 'الامتحانات والأسئلة',
    items: [
      { path: '/instructor/exams-assignments-management', label: 'إضافة وتعديل الامتحانات', icon: GraduationCap },
      // decision 10.36 — per-exam/per-video reports, distinct from the
      // whole-account "الإحصائيات" entry above.
      { path: '/instructor/exam-statistics', label: 'إحصائيات الامتحانات', icon: PieChart },
      { path: '/instructor/watch-statistics', label: 'إحصائيات المشاهدات', icon: MonitorPlay },
      { path: '/instructor/question-groups-management', label: 'إضافة وتعديل مجاميع الأسئلة', icon: ListTodo },
      { path: '/instructor/question-bank', label: 'إضافة وتعديل الأسئلة', icon: Database },
    ],
  },
  {
    heading: 'الاشتراكات والمالية',
    items: [
      { path: '/instructor/subscriptions', label: 'جدول الاشتراكات', icon: CreditCard },
      { path: '/instructor/revenue', label: 'إيراداتي', icon: DollarSign },
    ],
  },
  {
    heading: 'الإدارة',
    items: [
      // decision 10.37 — "إدارة الأدمنز". Filtered out entirely for a
      // limited Employee session that lacks admin_management.manage; see
      // `instructorNav` below.
      { path: '/instructor/admin-management', label: 'إدارة الأدمنز', icon: UserCog },
    ],
  },
];

// Center System (decision 10.24) — instructor's center-management screens.
// "زر تفعيل نظام السنتر" (decision 10.33): this whole group is UI convenience
// only — hiding it does not enforce anything by itself, the real gate is the
// server-side `center.enabled` middleware. See INSTRUCTOR_NAV below.
const INSTRUCTOR_NAV_CENTER: SidebarNavGroup = {
  heading: 'نظام السنتر',
  items: [
    { path: '/instructor/center-groups', label: 'إنشاء مجموعة', icon: Building2 },
    { path: '/instructor/center-attendance', label: 'حضور الطلاب', icon: ClipboardCheck },
    { path: '/instructor/center-reports', label: 'تقارير طلاب السنتر', icon: ClipboardList },
    { path: '/instructor/center-course-activation', label: 'تفعيل كورس سنتر', icon: Unlock },
    { path: '/instructor/center-payments', label: 'متابعة مدفوعات السنتر', icon: Wallet },
  ],
};

export default function InstructorSystem({
  username,
  onLogout
}: InstructorSystemProps) {
  const navigate = useNavigate();
  const location = useLocation();
  // Starts open so the sidebar is visible-with-labels on first load rather
  // than defaulting to the collapsed rail — same as AdminSystem.
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);

  // Replaces fourteen individual `isXActive` consts. `exact` is only set on
  // '/instructor' itself, which would otherwise prefix-match every child route.
  const isNavItemActive = (item: SidebarNavEntry) =>
    item.exact
      ? location.pathname === '/instructor' || location.pathname === '/instructor/'
      : location.pathname === item.path;

  const [isDark, setIsDark] = React.useState(() => {
    return document.documentElement.classList.contains('dark');
  });

  const toggleDarkMode = () => {
    if (isDark) {
      document.documentElement.classList.remove('dark');
      settingsService.setTheme('light');
      setIsDark(false);
    } else {
      document.documentElement.classList.add('dark');
      settingsService.setTheme('dark');
      setIsDark(true);
    }
  };

  const user = authService.getUser();

  // "زر تفعيل نظام السنتر" (decision 10.33) — PublicUser only ever emits
  // `centerEnabled` for the `instructor` role, so `=== true` (not a plain
  // truthiness check) keeps a stale/foreign session payload from ever
  // showing the group by accident.
  const centerAwareNav: SidebarNavGroup[] = user?.centerEnabled === true
    ? [...INSTRUCTOR_NAV_GROUPS, INSTRUCTOR_NAV_CENTER]
    : INSTRUCTOR_NAV_GROUPS;

  // "إدارة الأدمنز" (decision 10.37) — UI CONVENIENCE ONLY, same caveat as
  // the `centerEnabled` filter above: hiding an item here does not enforce
  // anything by itself, the real gate is the server-side
  // `employee.permission:<key>` middleware. `employeePermissions` is `null`
  // for an ordinary teacher session (show everything, unfiltered — the
  // existing behaviour), `'all'` for an Amin (also everything), or a
  // string[] for a limited Employee.
  const instructorNav: SidebarNavGroup[] = (() => {
    const permissions = user?.employeePermissions;

    if (permissions == null || permissions === 'all') {
      return centerAwareNav;
    }

    const allowedPaths = new Set(
      INSTRUCTOR_EMPLOYEE_PERMISSIONS
        .filter((p) => p.sidebarPath !== null && permissions.includes(p.key))
        .map((p) => p.sidebarPath)
    );

    return centerAwareNav
      .map((group) => ({
        ...group,
        // The home page ('/instructor') always stays — it carries no
        // protected data (InstructorHome.tsx reads only the session
        // payload already in hand) and every account needs a landing page.
        items: group.items.filter((item) => item.path === '/instructor' || allowedPaths.has(item.path)),
      }))
      .filter((group) => group.items.length > 0);
  })();

  const currentTeacher = {
    name: user?.fullName || authService.getUsername() || 'مستخدم',
    // PublicUser (the shape /auth/login and /auth/me actually return) only
    // ever emits `avatar_url` (snake_case) — `avatarUrl` is not a key this
    // object has, so this always read undefined and the topbar avatar could
    // never show the photo the admin uploaded for this teacher. Same bug
    // already fixed in InstructorHome.tsx's hero avatar; see its docblock.
    image: user?.avatar_url || '',
    role: 'معلم معتمد بمنصة سند'
  };

  // `collapsed` is the desktop-only reading of the SAME state that drives the
  // mobile drawer: below lg, !isSidebarOpen means the drawer is off-screen and
  // none of these lg: classes apply; from lg up it means the icon rail. One
  // state, no resize listener — see Sidebar.tsx's railMode note.
  const collapsed = !isSidebarOpen;

  // User identity removed from the sidebar on request — it's already shown
  // in the topbar's own account menu. Sidebar.tsx renders a compact header
  // with no identity content when this is null.
  const headerContent = null;

  return (
    <div dir="rtl" className="min-h-screen bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-50 font-sans transition-colors duration-300 flex flex-col">

      {/* 1. FIXED PERSISTENT TOP BAR */}
      {/* SHAPE pass: the bar itself picked up a soft rounded-bottom silhouette
          and a blue-tinted shadow that follows that curve, instead of a flat
          straight-edged rectangle — a deliberate shape change, not a recolour
          of its contents (those keep whatever styling they already had).
          Position, height (h-20, still matching Sidebar.tsx's `lg:top-20`
          anchor) and every handler are unchanged. */}
      <header className="sticky top-0 z-40 w-full rounded-b-[28px] bg-white/80 dark:bg-slate-950/80 border-b border-x border-slate-200/70 dark:border-slate-800/70 backdrop-blur-xl backdrop-saturate-150 shadow-[0_1px_2px_0_rgb(15_23_42/0.04),0_16px_36px_-16px_rgba(37,99,235,0.28)] dark:shadow-[0_12px_32px_-18px_rgb(0_0_0/0.75)] transition-colors duration-300">
        <div className="absolute inset-x-6 top-0 h-px bg-gradient-to-l from-transparent via-blue-600 to-transparent opacity-70 pointer-events-none" />

        <div className="w-full px-6 md:px-12 lg:px-16 h-20 flex items-center justify-between">

          <div className="flex items-center gap-4">
            <button
              onClick={() => setIsSidebarOpen(!isSidebarOpen)}
              // SHAPE pass: round pill instead of a rounded-square tile, a
              // blue-tinted fill instead of flat slate, and a slight press/hover
              // scale so the toggle reads as its own distinct control.
              className="p-2.5 rounded-full text-slate-600 dark:text-slate-300 bg-blue-50 hover:bg-blue-100 dark:bg-slate-900 dark:hover:bg-slate-800 ring-1 ring-inset ring-blue-600/15 dark:ring-white/10 transition-all duration-200 hover:scale-105 active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 cursor-pointer flex items-center justify-center"
              aria-label="Toggle Navigation Panel"
              aria-expanded={isSidebarOpen}
              title={isSidebarOpen ? 'طي الشريط الجانبي' : 'توسيع الشريط الجانبي'}
              id="instructor_layout_sidebar_toggle"
            >
              {/* Two icon sets for two genuinely different behaviours: below lg
                  this opens/closes a drawer (Menu / X), from lg up it expands
                  or collapses a rail that never disappears (panel icons). */}
              <span className="lg:hidden">
                {isSidebarOpen ? (
                  <X className="w-5.5 h-5.5 text-blue-600 dark:text-blue-400" />
                ) : (
                  <Menu className="w-5.5 h-5.5 text-slate-700 dark:text-slate-300" />
                )}
              </span>
              <span className="hidden lg:block">
                {isSidebarOpen ? (
                  <PanelRightClose className="w-5.5 h-5.5 text-blue-600 dark:text-blue-400" />
                ) : (
                  <PanelRightOpen className="w-5.5 h-5.5 text-slate-700 dark:text-slate-300" />
                )}
              </span>
            </button>

            <div
              onClick={() => navigate('/instructor')}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); navigate('/instructor'); } }}
              className="flex items-center gap-3 group cursor-pointer rounded-2xl -m-1.5 p-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 select-none"
            >
              {/* Bare mark, no tile and no frame behind it — see Header.tsx. */}
              <PlatformLogo className="w-19 h-19 object-contain shrink-0 transition-transform duration-300 group-hover:scale-105 group-active:scale-95" />
              <div className="flex flex-col justify-center leading-none text-right">
                <span className="text-[1.55rem] font-black tracking-tight bg-gradient-to-l from-blue-700 via-blue-500 to-indigo-600 dark:from-blue-300 dark:via-blue-400 dark:to-indigo-400 bg-clip-text text-transparent font-sans">
                  سند
                </span>
                <span className="mt-1.5 flex items-center gap-1.5">
                  <span className="h-px w-3 rounded-full bg-gradient-to-l from-transparent to-blue-500/40 group-hover:to-blue-500/80 transition-colors duration-300" />
                  <span className="text-[9px] font-extrabold tracking-[0.3em] text-slate-400 dark:text-slate-500 group-hover:text-blue-500 dark:group-hover:text-blue-400 transition-colors duration-300">
                    بوابة المعلم
                  </span>
                </span>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2 sm:gap-2.5">
            <DarkModeToggle isDark={isDark} onToggle={toggleDarkMode} />

            <div className="relative">
              <button
                onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                className={`flex items-center gap-2.5 ps-1.5 pe-3 py-1.5 rounded-full ring-1 ring-inset transition-all duration-200 cursor-pointer select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 ${
                  isDropdownOpen
                    ? 'bg-white dark:bg-slate-800 ring-blue-500/40 shadow-md shadow-blue-600/10'
                    : 'bg-slate-100/90 hover:bg-white dark:bg-slate-900/90 dark:hover:bg-slate-800 ring-slate-900/[0.06] dark:ring-white/10 hover:ring-blue-500/25 hover:shadow-sm'
                }`}
                aria-label="User Dropdown"
                aria-expanded={isDropdownOpen}
                aria-haspopup="menu"
              >
                <Avatar
                  initial={currentTeacher.name.charAt(0)}
                  imageUrl={currentTeacher.image}
                  alt={currentTeacher.name}
                  size="sm"
                />
                <span className="hidden select-none lg:inline text-xs md:text-sm font-black text-slate-800 dark:text-white max-w-[10rem] truncate">
                  {currentTeacher.name}
                </span>
                <ChevronDown className={`w-3.5 h-3.5 text-slate-400 dark:text-slate-500 transition-transform duration-300 ${isDropdownOpen ? 'rotate-180' : ''}`} />
              </button>

              <AccountMenuPanel isOpen={isDropdownOpen} onClose={() => setIsDropdownOpen(false)}>
                {/* Identity block */}
                <div className="relative overflow-hidden flex items-center gap-3 px-3 py-3.5 mb-2 rounded-xl bg-gradient-to-l from-blue-50 to-indigo-50/60 dark:from-blue-950/40 dark:to-indigo-950/20 ring-1 ring-inset ring-blue-500/10 dark:ring-white/[0.06] text-right">
                  <Avatar
                    initial={currentTeacher.name.charAt(0)}
                    imageUrl={currentTeacher.image}
                    alt={currentTeacher.name}
                    size="md"
                    ringClassName="ring-2 ring-white/70 dark:ring-slate-900/60"
                  />
                  <div className="min-w-0">
                    <div className="font-black text-xs md:text-sm text-slate-900 dark:text-white leading-tight truncate">
                      {currentTeacher.name}
                    </div>
                    <span className="text-[10px] text-blue-600 dark:text-blue-400 font-extrabold mt-1 inline-block leading-tight">
                      حساب معلم معتمد
                    </span>
                  </div>
                </div>

                {/* The "الرئيسية" entry that used to sit here was removed on
                    the user's request. It only re-navigated to /instructor —
                    the page the instructor is already on — since the platform
                    logo to the left of this menu does the same thing. Logout
                    is now the menu's only action. */}
                <button
                  onClick={() => {
                    setIsDropdownOpen(false);
                    if (onLogout) {
                      onLogout();
                    }
                    navigate('/');
                  }}
                  className={`${menuItemClass} text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30`}
                >
                  <span className={`${menuIconClass} bg-rose-50 text-rose-600 ring-rose-500/10 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-white/10 group-hover/item:bg-rose-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-rose-600/30`}>
                    <LogOut className="w-4 h-4" />
                  </span>
                  <span>تسجيل الخروج</span>
                </button>
              </AccountMenuPanel>
            </div>
          </div>
        </div>
      </header>

      <div className="flex-1 w-full px-4 md:px-6 lg:px-8 py-8 flex gap-8 items-start relative">

        {/* 2. PERSISTENT SIDEBAR */}
        <Sidebar
          isOpen={isSidebarOpen}
          onClose={() => setIsSidebarOpen(false)}
          headerContent={headerContent}
          userRole="instructor"
          username={username}
          // Desktop-only change, adopted from AdminSystem: collapsing now
          // shrinks this to an icon rail instead of pushing it off-screen.
          // Mobile stays a drawer.
          railMode
        >
          {instructorNav.map((group, groupIndex) => (
            <div key={group.heading} className={groupIndex > 0 ? 'pt-2' : undefined}>
              <SidebarNavHeading heading={group.heading} collapsed={collapsed} />
              {collapsed && groupIndex > 0 && <SidebarNavRailDivider />}

              <nav className="space-y-1.5">
                {group.items.map((item, itemIndex) => (
                  <SidebarNavItem
                    key={item.path}
                    label={item.label}
                    icon={item.icon}
                    isActive={isNavItemActive(item)}
                    collapsed={collapsed}
                    index={itemIndex}
                    // Navigation no longer auto-closes the sidebar — only the
                    // open/close button (onClose below) toggles isSidebarOpen.
                    onClick={() => navigate(item.path)}
                  />
                ))}
              </nav>
            </div>
          ))}
        </Sidebar>

        {/* 3. DYNAMIC CONTENT AREA */}
        {/* The sidebar is `lg:fixed` to the real viewport edge (see
            Sidebar.tsx), so it does not occupy space in this flex row — this
            margin reserves exactly its current width so content never renders
            underneath it, and animates in step with its collapse. */}
        <main className={`flex-1 w-full min-w-0 space-y-6 transition-[margin] duration-300 ease-in-out ${
          collapsed ? 'lg:mr-16' : 'lg:mr-64'
        }`}>
          {/* "إدارة الأدمنز" (decision 10.37) — identity disclosure only, not
              a security boundary: the session is authenticated as the
              TEACHER for every ownership check (App\Actions\Auth\LoginUser's
              identity swap), so without this banner nothing on screen would
              tell an employee which account they are actually acting as. */}
          {user?.actingEmployee && (
            <div className="flex items-center gap-2.5 px-4 py-2.5 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-100 dark:border-indigo-500/20 text-xs font-bold text-indigo-700 dark:text-indigo-400">
              <UserCog className="w-4 h-4 shrink-0" />
              <span>تعمل بصلاحيات موظف: {user.actingEmployee.name || 'موظف'}</span>
            </div>
          )}
          <AnimatePresence mode="wait">
            <Outlet />
          </AnimatePresence>
        </main>
      </div>
    </div>
  );
}
