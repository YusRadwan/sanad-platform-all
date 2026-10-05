import React, { useState } from 'react';
import { AnimatePresence } from 'motion/react';
import { Outlet, useNavigate, useLocation } from 'react-router';
import PlatformLogo from './PlatformLogo';
import {
  ArrowLeft, Menu, X, ChevronDown, LogOut,
  LayoutDashboard, BarChart3, Megaphone, UserPlus, Users, KeyRound,
  Table2, TableProperties, GraduationCap, Coins, Wallet, FileText,
  Undo2, Banknote, Activity, Settings, ScanSearch, UserSearch, ListFilter,
  PanelRightClose, PanelRightOpen, Inbox, Building2, UserCog,
} from 'lucide-react';

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
import { AdminSystemProps } from '../types/index';
import { settingsService } from '../services/settingsService';
import { authService } from '../services/authService';
import { ADMIN_EMPLOYEE_PERMISSIONS } from '../constants/employeePermissions';

/**
 * Admin sidebar navigation — single source of truth for order, labels, icons
 * and routes.
 *
 * This replaces sixteen hand-copied <button> blocks that had drifted apart:
 * the five finance items were rendered in a different style from the other
 * eleven (bare icon, no icon chip, no active dot, different inactive text
 * colour) and sat at the TOP of the menu, above الرئيسية. Driving the list
 * from an array keeps every item visually identical and makes the order
 * verifiable at a glance.
 *
 * The three groups are presentational only — the sequence reading top to
 * bottom is exactly the requested 1..16 order, so grouping adds hierarchy
 * without reordering anything. Group headers reuse the existing sidebar
 * convention (also used by InstructorSystem.tsx).
 *
 * Routes are unchanged; each `path` below is the same one the corresponding
 * button navigated to before.
 *
 * The per-row rendering now lives in Layout/SidebarNavItem, shared with
 * InstructorSystem.tsx so the two sidebars cannot drift apart again. The two
 * item lists stay separate — only the pixels are shared, not the permissions.
 */
const ADMIN_NAV: SidebarNavGroup[] = [
  {
    heading: 'القائمة الرئيسية',
    items: [
      // 1
      { path: '/admin', label: 'الرئيسية', icon: LayoutDashboard, exact: true },
      // 2 — chart icon for the statistics dashboard
      { path: '/admin/statistics', label: 'إحصائيات المنصة', icon: BarChart3 },
      // 3 — megaphone: an outbound broadcast, not a personal bell
      { path: '/admin/broadcast-notifications', label: 'بث الإشعارات العامة', icon: Megaphone },
    ],
  },
  {
    heading: 'المستخدمون والاشتراكات',
    items: [
      // 4
      { path: '/admin/add-teacher', label: 'إضافة مدرس', icon: UserPlus },
      // NEW — public "طلب مدرس جديد" submissions (open decision 10.23), read-only
      // review list. Sits right after إضافة مدرس since both concern bringing a
      // new teacher onto the platform.
      { path: '/admin/teacher-applications', label: 'طلب مدرس جديد', icon: Inbox },
      // 5
      { path: '/admin/manage-teachers', label: 'إدارة المدرسين', icon: Users },
      // NEW — decision 10.40. A SEPARATE concept from the instructor's own
      // "إدارة الأدمنز" (decision 10.37): the admin's OWN Employee/Amin
      // sub-accounts, isolated per admin — never a teacher's. Sits right
      // after إدارة المدرسين only for menu proximity, not because the two
      // features share any data.
      { path: '/admin/manage-employees', label: 'إدارة الأدمنز', icon: UserCog },
      // 6 — a code/key is issued here
      { path: '/admin/enrollment-codes', label: 'إنشاء أكواد الاشتراك', icon: KeyRound },
      // NEW — the read-only inspector for a code minted above. Placed directly
      // after its generator so the mint/inspect pair reads as one unit.
      { path: '/admin/enrollment-code-details', label: 'تفاصيل كود الاشتراك', icon: ScanSearch },
      // NEW — review/filter across ALL codes (by code, teacher, student,
      // phones), with usage stat cards. Sits right after the single-code
      // lookup since both read the same underlying data at different scopes.
      { path: '/admin/filter-enrollment-codes', label: 'فلتر أكواد الاشتراك', icon: ListFilter },
      // 7 & 8 — the two flat-table screens share a table icon family
      { path: '/admin/users', label: 'جدول المستخدمين', icon: Table2 },
      { path: '/admin/manage-subscriptions', label: 'جداول الاشتراكات', icon: TableProperties },
      // NEW — single-student lookup. Sits right after the subscriptions table
      // because it answers the same question for ONE student that the table
      // answers for all of them, and immediately before إضافة طالب.
      { path: '/admin/student-code-details', label: 'تفاصيل كود الطالب', icon: UserSearch },
      // NEW — a narrow, single-purpose tool: find a student by phone number
      // alone and reset their password. Sits right after the student lookup
      // screens since it answers a related, but distinct, support request.
      { path: '/admin/reset-student-password', label: 'تعيين كلمة سر الطالب', icon: KeyRound },
      // 9 — graduation cap distinguishes "add student" from "add teacher"
      { path: '/admin/add-student', label: 'إضافة طالب', icon: GraduationCap },
    ],
  },
  {
    // Center System (decision 10.24) — admin's two center-code screens.
    heading: 'نظام السنتر',
    items: [
      { path: '/admin/generate-center-codes', label: 'توليد أكواد السنتر', icon: KeyRound },
      { path: '/admin/center-code-details', label: 'تفاصيل كود سنتر', icon: Building2 },
    ],
  },
  {
    // Pure money movement — everything a finance-minded admin checks in one
    // sitting. Split out of the old "المالية والمراقبة" catch-all, which mixed
    // revenue screens with login monitoring and platform configuration under
    // one label. This is a GROUPING change only — the underlying 1..16 item
    // sequence is untouched, items 10-14 just gained their own heading.
    heading: 'المالية',
    items: [
      // 10
      { path: '/admin/profit-engine', label: 'محرك الأرباح', icon: Coins },
      // 11 — money leaving the platform to a teacher
      { path: '/admin/teacher-payouts', label: 'دفعات المدرسين', icon: Wallet },
      // 12 — a document/statement
      { path: '/admin/instructor-statement', label: 'كشف حساب مدرس', icon: FileText },
      // 13 — money going back
      { path: '/admin/refund-management', label: 'إدارة الاسترجاع', icon: Undo2 },
      // 14 — banknote for incoming platform revenue (was a generic Activity
      // icon, which duplicated مراقبة الدخول below)
      { path: '/admin/revenue', label: 'إيرادات المنصة', icon: Banknote },
    ],
  },
  {
    // Everything else that isn't money: security monitoring and platform-wide
    // configuration. Items 15-16 in the same order as before.
    heading: 'المراقبة والإعدادات',
    items: [
      // 15
      { path: '/admin/login-activity', label: 'مراقبة الدخول', icon: Activity },
      // 16
      { path: '/admin/platform-settings', label: 'إعدادات المنصة', icon: Settings },
    ],
  },
];






export default function AdminSystem({
  onBackToHome,
  username = 'مدير النظام',
  onLogout
}: AdminSystemProps) {
  const navigate = useNavigate();
  const location = useLocation();
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);

  // Replaces sixteen individual `isXActive` consts. `exact` is only set on
  // '/admin' itself, which would otherwise prefix-match every child route.
  const isNavItemActive = (item: SidebarNavEntry) =>
    item.exact
      ? location.pathname === '/admin' || location.pathname === '/admin/'
      : location.pathname === item.path;

  const user = authService.getUser();

  // "إدارة الأدمنز" — قسم الأدمن (decision 10.40) — UI CONVENIENCE ONLY,
  // same pattern as InstructorSystem.tsx's own `instructorNav` filter: the
  // real gate is the server-side `employee.permission:<key>` middleware.
  // `employeePermissions` is `null` for an ordinary admin session (show
  // everything, unfiltered), `'all'` for an admin-owned Amin (also
  // everything), or a string[] for a limited admin-owned Employee.
  const adminNav: SidebarNavGroup[] = (() => {
    const permissions = user?.employeePermissions;

    if (permissions == null || permissions === 'all') {
      return ADMIN_NAV;
    }

    const allowedPaths = new Set(
      ADMIN_EMPLOYEE_PERMISSIONS
        .filter((p) => p.sidebarPath !== null && permissions.includes(p.key))
        .map((p) => p.sidebarPath)
    );

    return ADMIN_NAV
      .map((group) => ({
        ...group,
        // The home page ('/admin') always stays — it carries no protected
        // data and every account needs a landing page.
        items: group.items.filter((item) => item.path === '/admin' || allowedPaths.has(item.path)),
      }))
      .filter((group) => group.items.length > 0);
  })();


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

  // `collapsed` is the desktop-only reading of the SAME state that drives the
  // mobile drawer: below lg, !isSidebarOpen means the drawer is off-screen and
  // none of these lg: classes apply; from lg up it means the icon rail. One
  // state, no resize listener — see Sidebar.tsx's railMode note. Starts open
  // (true) so the sidebar is visible-with-labels on first load instead of
  // defaulting to the collapsed rail.
  const collapsed = !isSidebarOpen;

  // User identity removed from the sidebar on request — it's already shown
  // in the topbar's own account menu. Sidebar.tsx renders a compact header
  // with no identity content when this is null.
  const headerContent = null;

  return (
    <div dir="rtl" className="min-h-screen bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-50 font-tajawal transition-colors duration-300 flex flex-col">

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
              id="admin_layout_sidebar_toggle"
            >
              {/* Two icon sets for two genuinely different behaviours: below xl
                  this opens/closes a drawer (Menu / X), from xl up it expands
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
              onClick={onBackToHome}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onBackToHome?.(); } }}
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
                    بوابة الإدارة
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
                <Avatar initial={username.trim().charAt(3) || 'م'} size="sm" />
                <span className="hidden select-none lg:inline text-xs md:text-sm font-black text-slate-800 dark:text-white max-w-[10rem] truncate">
                  {username}
                </span>
                <ChevronDown className={`w-3.5 h-3.5 text-slate-400 dark:text-slate-500 transition-transform duration-300 ${isDropdownOpen ? 'rotate-180' : ''}`} />
              </button>

              <AccountMenuPanel isOpen={isDropdownOpen} onClose={() => setIsDropdownOpen(false)}>
                {/* Identity block */}
                <div className="relative overflow-hidden flex items-center gap-3 px-3 py-3.5 mb-2 rounded-xl bg-gradient-to-l from-blue-50 to-indigo-50/60 dark:from-blue-950/40 dark:to-indigo-950/20 ring-1 ring-inset ring-blue-500/10 dark:ring-white/[0.06] text-right">
                  <Avatar
                    initial={username.trim().charAt(3) || 'م'}
                    size="md"
                    ringClassName="ring-2 ring-white/70 dark:ring-slate-900/60"
                  />
                  <div className="min-w-0">
                    <div className="font-black text-xs md:text-sm text-slate-900 dark:text-white leading-tight truncate">
                      {username}
                    </div>
                    <span className="text-[10px] text-blue-600 dark:text-blue-400 font-extrabold mt-1 inline-block leading-tight">
                      مدير عام • حساب إداري
                    </span>
                  </div>
                </div>

                <button
                  onClick={() => {
                    setIsDropdownOpen(false);
                    onBackToHome?.();
                  }}
                  className={`${menuItemClass} text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-700 dark:hover:text-blue-300`}
                >
                  <span className={`${menuIconClass} bg-blue-50 text-blue-600 ring-blue-500/10 dark:bg-blue-950/50 dark:text-blue-400 dark:ring-white/10 group-hover/item:bg-gradient-to-br group-hover/item:from-blue-500 group-hover/item:to-indigo-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-blue-600/30`}>
                    <ArrowLeft className="w-4 h-4" />
                  </span>
                  <span>الرجوع للبوابة الرئيسية</span>
                </button>

                <div className="h-px bg-slate-100 dark:bg-slate-800 mx-2 my-2" />

                <button
                  onClick={() => {
                    setIsDropdownOpen(false);
                    if (onLogout) onLogout();
                    onBackToHome?.();
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
          username={username}
          userRole="admin"
          // Desktop-only change: collapsing now shrinks this to an icon rail
          // instead of pushing it off-screen. Mobile stays a drawer.
          railMode
        >
          {adminNav.map((group, groupIndex) => (
            <div key={group.heading} className={groupIndex > 0 ? 'pt-2' : undefined}>
              {/* Group heading in expanded mode; in the rail it degrades to a
                  plain separator rule so the three groups stay legible as
                  groups even without their text. */}
              <SidebarNavHeading heading={group.heading} collapsed={collapsed} />
              {collapsed && groupIndex > 0 && <SidebarNavRailDivider />}

              {/* NOTE: this <nav> previously carried `animate-in fade-in
                  duration-300`. That is `tailwindcss-animate` syntax and the
                  plugin is not installed here (see frontend/package.json), so
                  the classes emitted nothing at all. Removed rather than
                  reimplemented — a sidebar that re-fades on every render is not
                  wanted. */}
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
                    // open/close button (onClose above) toggles isSidebarOpen.
                    onClick={() => navigate(item.path)}
                  />
                ))}
              </nav>
            </div>
          ))}
        </Sidebar>

        {/* 3. DYNAMIC CONTENT AREA */}
        {/* The sidebar is `lg:fixed` to the real viewport edge (see
            Sidebar.tsx), so it no longer occupies space in this flex row —
            this margin reserves exactly its current width so content never
            renders underneath it, and animates in step with its collapse. */}
        <main className={`flex-1 w-full min-w-0 space-y-6 transition-[margin] duration-300 ease-in-out ${
          collapsed ? 'lg:mr-16' : 'lg:mr-64'
        }`}>
          {/* "إدارة الأدمنز" — قسم الأدمن (decision 10.40) — identity
              disclosure only, not a security boundary: the session is
              authenticated as the OWNING ADMIN for every check (App\Actions\
              Auth\LoginUser's identity swap), so without this banner nothing
              on screen would tell an admin-owned employee which account they
              are actually acting as. */}
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
