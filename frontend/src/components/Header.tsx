import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Menu, X, ChevronDown, LayoutDashboard, LogOut, TrendingUp, Building2 } from 'lucide-react';
import PlatformLogo from './PlatformLogo';
import NotificationDropdown from './NotificationDropdown';
import Avatar from './Layout/Avatar';
import DarkModeToggle from './Layout/DarkModeToggle';
import AccountMenuPanel, {
  accountMenuItemClass as menuItemClass,
  accountMenuIconClass as menuIconClass,
} from './Layout/AccountMenuPanel';
import { settingsService } from '../services/settingsService';

/**
 * Public marketing-site navbar.
 *
 * Intentionally NOT built from the shared `UI/` primitives (Button, Card, ...).
 * Those are tuned for the dense admin/instructor dashboards — the design
 * tokens file calls their elevation ramp "deliberately shallow: this is a
 * dense data dashboard, not a marketing page". The public site keeps its own,
 * softer language: pill controls, gradient CTA, generous height, deeper
 * overlay shadows. Only the shared *tokens* (brand ramp, shadow-e2/e3) are
 * reused, so the two surfaces stay colour-identical without the marketing
 * navbar starting to look like a control panel.
 *
 * The blue -> indigo gradient used by the primary CTA and the wordmark is the
 * same ramp the marketing sections already use (HeroSection's "سند" headline,
 * JoinUsSection's main CTA), so the bar reads as part of the landing page.
 *
 * Overlays (account dropdown, mobile sheet) stay MOUNTED and are animated with
 * plain CSS transitions instead of being conditionally rendered: the project
 * has no `tailwindcss-animate` plugin, so the previous `animate-in
 * slide-in-from-top-*` classes were inert, and conditional mounting can never
 * animate the *closing* half anyway.
 */

interface HeaderProps {
  currentView?: string;
  onNavigate: (page: string) => void;
  isLoggedIn?: boolean;
  username?: string;
  userRole?: string;
  onLogout?: () => void;
}

const PUBLIC_LINKS = [
  { to: '/#courses', label: 'الكورسات' },
  { to: '/#teachers', label: 'المدرسين' },
  { to: '/#how', label: 'إزاي تشترك' },
  { to: '/teacher-application', label: 'انضم كمدرس' },
];

export default function Header({
  currentView,
  onNavigate,
  isLoggedIn = false,
  username = 'مستخدم',
  userRole = 'instructor',
  onLogout
}: HeaderProps) {
  const [isDark, setIsDark] = useState(false);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  // Purely cosmetic: the bar tightens and gains a real edge once the page has
  // scrolled, so it reads as "floating over content" instead of a flat strip.
  const [isScrolled, setIsScrolled] = useState(false);
  // NOTE: a "student code" chip used to render here, fed by
  // studentService.getSubscriptions() — a no-op stub that always returned [].
  // The lookup therefore never matched and the chip never appeared, so both
  // the effect and the chip were removed. If the code is wanted in the header
  // again it must come from a real endpoint (the student's own profile),
  // not from client-side storage.

  useEffect(() => {
    const saved = settingsService.getTheme();
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

    if (saved === 'dark' || (!saved && prefersDark)) {
      setIsDark(true);
      document.documentElement.classList.add('dark');
    } else {
      setIsDark(false);
      document.documentElement.classList.remove('dark');
    }
  }, []);

  useEffect(() => {
    const onScroll = () => setIsScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

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

  const roleLabel =
    userRole === 'admin'
      ? 'مشرف المنصة • حساب مدير'
      : userRole === 'instructor'
        ? 'معلم معتمد • حساب إداري'
        : 'طالب مسجل';

  // The row/icon classes for this menu now live in Layout/AccountMenuPanel and
  // are imported above, so AdminSystem and InstructorSystem render identical
  // rows without a third and fourth copy of the same strings.

  return (
    <header
      className={`sanad-chrome sticky top-0 z-50 border-b bg-[var(--paper)] transition-[border-color,box-shadow] duration-200 ${
        isScrolled ? 'border-[var(--hair)] shadow-[0_1px_2px_rgb(29_35_64/0.04)]' : 'border-transparent'
      }`}
    >
      {/* Quiet paper bar (the "كراسة سند" redesign): no glow, glass or
          looping light sweeps — those were removed with the old landing
          page. A hairline appears once the page scrolls under it. */}
      <div className="mx-auto w-full max-w-[1200px] px-5 sm:px-8 flex items-center justify-between gap-3 sm:gap-6 h-16 sm:h-[72px]">

        {/* Logo and Platform Name */}
        <div
          onClick={() => onNavigate('home')}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onNavigate('home'); } }}
          aria-label="سند — الصفحة الرئيسية"
          className="flex items-center gap-2.5 cursor-pointer rounded-xl -m-1.5 p-1.5 select-none"
        >
          <PlatformLogo className="w-10 h-10 object-contain shrink-0" />
          <span className="font-kufi text-[28px] font-bold leading-none text-sanad">سند</span>
        </div>

        {/* Section links — guests and students only (instructors/admins
            never see the public header). */}
        {userRole !== 'admin' && (
          <nav aria-label="أقسام الموقع" className="hidden lg:flex items-center gap-7 text-[15px]">
            {PUBLIC_LINKS.map((l) => (
              <Link key={l.to} to={l.to} className="link-draw text-[color:var(--ink)]">
                {l.label}
              </Link>
            ))}
          </nav>
        )}

        {/* Actions - Left */}
        <div className="flex items-center gap-1.5 sm:gap-2.5">

          {/* Aesthetic Sliding Dark Mode Switcher */}
          <DarkModeToggle isDark={isDark} onToggle={toggleDarkMode} />

          {isLoggedIn && <NotificationDropdown />}

          {/* User Session Switch block vs default Auth buttons.
              BELOW `md` THIS BLOCK IS HIDDEN WHEN LOGGED IN — its identity/
              dashboard/logout functionality is already duplicated in the
              mobile drawer the hamburger button opens (below). Before this
              fix it stayed visible at every width while the guest-only
              login/signup buttons already hid themselves below `sm`
              (`hidden sm:flex`); logged in, the extra ~74px pill plus
              NotificationDropdown pushed the actions row past the viewport
              width on phones, and — because RTL flex places the LAST DOM
              child (the hamburger) as the visually LEFTMOST item — the
              hamburger was exactly what got shoved off-screen. `md:block`
              mirrors the hamburger's own `md:hidden` below, so the two never
              have to compete for the same space. */}
          {isLoggedIn ? (
            <div className="relative hidden md:block">
              <button
                onClick={() => setIsDropdownOpen(!isDropdownOpen)}
                className={`flex items-center gap-2.5 ps-1.5 pe-3 py-1.5 rounded-full ring-1 ring-inset transition-all duration-200 cursor-pointer select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40 ${
                  isDropdownOpen
                    ? 'bg-white dark:bg-slate-800 ring-blue-500/40 shadow-md shadow-blue-600/10'
                    : 'bg-slate-100/90 hover:bg-white dark:bg-slate-900/90 dark:hover:bg-slate-800 ring-slate-900/[0.06] dark:ring-white/10 hover:ring-blue-500/25 hover:shadow-sm'
                }`}
                aria-label="قائمة الحساب"
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
                      {roleLabel}
                    </span>
                  </div>
                </div>

                {userRole === 'admin' ? (
                  <>
                    <button
                      onClick={() => {
                        onNavigate('admin');
                        setIsDropdownOpen(false);
                      }}
                      className={`${menuItemClass} text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-700 dark:hover:text-blue-300`}
                    >
                      <span className={`${menuIconClass} bg-blue-50 text-blue-600 ring-blue-500/10 dark:bg-blue-950/50 dark:text-blue-400 dark:ring-white/10 group-hover/item:bg-gradient-to-br group-hover/item:from-blue-500 group-hover/item:to-indigo-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-blue-600/30`}>
                        <LayoutDashboard className="w-4 h-4" />
                      </span>
                      <span>إدارة المنصة</span>
                    </button>

                    <div className="h-px bg-slate-100 dark:bg-slate-800 mx-2 my-2" />

                    <button
                      onClick={() => {
                        setIsDropdownOpen(false);
                        if (onLogout) onLogout();
                        onNavigate('home');
                      }}
                      className={`${menuItemClass} text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30`}
                    >
                      <span className={`${menuIconClass} bg-rose-50 text-rose-600 ring-rose-500/10 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-white/10 group-hover/item:bg-rose-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-rose-600/30`}>
                        <LogOut className="w-4 h-4" />
                      </span>
                      <span>تسجيل الخروج</span>
                    </button>
                  </>
                ) : (
                  <>
                    {userRole === 'instructor' && (
                      <button
                        onClick={() => {
                          onNavigate('instructor');
                          setIsDropdownOpen(false);
                        }}
                        className={`${menuItemClass} text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-700 dark:hover:text-blue-300`}
                      >
                        <span className={`${menuIconClass} bg-blue-50 text-blue-600 ring-blue-500/10 dark:bg-blue-950/50 dark:text-blue-400 dark:ring-white/10 group-hover/item:bg-gradient-to-br group-hover/item:from-blue-500 group-hover/item:to-indigo-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-blue-600/30`}>
                          <LayoutDashboard className="w-4 h-4" />
                        </span>
                        <span>إدارة المنصة</span>
                      </button>
                    )}
                    {userRole === 'student' && (
                      <>
                        <button
                          onClick={() => {
                            onNavigate('student');
                            setIsDropdownOpen(false);
                          }}
                          className={`${menuItemClass} text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-700 dark:hover:text-blue-300`}
                        >
                          <span className={`${menuIconClass} bg-blue-50 text-blue-600 ring-blue-500/10 dark:bg-blue-950/50 dark:text-blue-400 dark:ring-white/10 group-hover/item:bg-gradient-to-br group-hover/item:from-blue-500 group-hover/item:to-indigo-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-blue-600/30`}>
                            <LayoutDashboard className="w-4 h-4" />
                          </span>
                          <span>كورساتي</span>
                        </button>

                        <button
                          onClick={() => {
                            onNavigate('student/statistics');
                            setIsDropdownOpen(false);
                          }}
                          className={`${menuItemClass} text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-700 dark:hover:text-blue-300`}
                        >
                          <span className={`${menuIconClass} bg-blue-50 text-blue-600 ring-blue-500/10 dark:bg-blue-950/50 dark:text-blue-400 dark:ring-white/10 group-hover/item:bg-gradient-to-br group-hover/item:from-blue-500 group-hover/item:to-indigo-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-blue-600/30`}>
                            <TrendingUp className="w-4 h-4" />
                          </span>
                          <span>إحصائياتي الدراسية</span>
                        </button>

                        <button
                          onClick={() => {
                            onNavigate('student/center');
                            setIsDropdownOpen(false);
                          }}
                          className={`${menuItemClass} text-slate-700 dark:text-slate-200 hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-700 dark:hover:text-blue-300`}
                        >
                          <span className={`${menuIconClass} bg-blue-50 text-blue-600 ring-blue-500/10 dark:bg-blue-950/50 dark:text-blue-400 dark:ring-white/10 group-hover/item:bg-gradient-to-br group-hover/item:from-blue-500 group-hover/item:to-indigo-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-blue-600/30`}>
                            <Building2 className="w-4 h-4" />
                          </span>
                          <span>طالب سنتر</span>
                        </button>
                      </>
                    )}

                    <div className="h-px bg-slate-100 dark:bg-slate-800 mx-2 my-2" />

                    <button
                      onClick={() => {
                        setIsDropdownOpen(false);
                        if (onLogout) onLogout();
                        onNavigate('home');
                      }}
                      className={`${menuItemClass} text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30`}
                    >
                      <span className={`${menuIconClass} bg-rose-50 text-rose-600 ring-rose-500/10 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-white/10 group-hover/item:bg-rose-600 group-hover/item:text-white group-hover/item:ring-transparent group-hover/item:shadow-sm group-hover/item:shadow-rose-600/30`}>
                        <LogOut className="w-4 h-4" />
                      </span>
                      <span>تسجيل الخروج</span>
                    </button>
                  </>
                )}
              </AccountMenuPanel>
            </div>
          ) : (
            <>
              <button
                type="button"
                onClick={() => onNavigate('login')}
                aria-current={currentView === 'login' ? 'page' : undefined}
                className="hidden sm:inline-flex link-draw text-[15px] font-medium text-[color:var(--ink)] cursor-pointer"
              >
                دخول
              </button>
              <button
                type="button"
                onClick={() => onNavigate('signup')}
                className="btn-primary hidden sm:inline-flex h-10 px-[18px] text-[15px]"
              >
                إنشاء حساب
              </button>
            </>
          )}

          {/* Mobile Menu Actuator */}
          <button
            onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
            className="lg:hidden inline-flex h-11 w-11 items-center justify-center rounded-[10px] border border-[var(--hair-strong)] bg-[var(--paper-card)] text-[color:var(--ink)] cursor-pointer transition-colors duration-150 hover:bg-[var(--paper-raised)]"
            aria-label={isMobileMenuOpen ? "إغلاق القائمة" : "فتح القائمة"}
            aria-expanded={isMobileMenuOpen}
          >
            {isMobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
        </div>
      </div>

      {/* Modern Backdrop Mobile Menu — kept mounted and collapsed with a
          grid-rows 0fr/1fr transition so height + fade animate both ways. */}
      <div
        className={`lg:hidden grid overflow-hidden transition-all duration-300 ease-out ${
          isMobileMenuOpen
            ? 'grid-rows-[1fr] opacity-100 border-t border-slate-200/70 dark:border-slate-800/70'
            : 'grid-rows-[0fr] opacity-0 border-t border-transparent pointer-events-none invisible'
        } bg-[var(--paper)] border-b-[var(--hair)]`}
        aria-hidden={!isMobileMenuOpen}
      >
        <div className="min-h-0">
          <nav
            className={`flex flex-col px-5 py-6 gap-3 text-center transition-transform duration-300 ease-out ${
              isMobileMenuOpen ? 'translate-y-0' : '-translate-y-2'
            }`}
          >

            {userRole !== 'admin' && (
              <ul className="m-0 mb-2 flex list-none flex-col p-0 text-start">
                {PUBLIC_LINKS.map((l) => (
                  <li key={l.to}>
                    <Link
                      to={l.to}
                      onClick={() => setIsMobileMenuOpen(false)}
                      className="flex h-12 items-center border-b border-[var(--hair)] text-base text-[color:var(--ink)]"
                    >
                      {l.label}
                    </Link>
                  </li>
                ))}
              </ul>
            )}

            {isLoggedIn ? (
              <div className="space-y-2.5">
                <div className="flex items-center gap-3 bg-gradient-to-l from-blue-50 to-indigo-50/60 dark:from-blue-950/40 dark:to-indigo-950/20 ring-1 ring-inset ring-blue-500/10 dark:ring-white/[0.06] p-3.5 rounded-2xl text-right mb-1">
                  <Avatar initial={username.trim().charAt(3) || 'م'} size="lg" />
                  <div className="min-w-0">
                    <div className="font-black text-sm text-slate-900 dark:text-white truncate">{username}</div>
                    <div className="text-[10px] text-blue-600 dark:text-blue-400 font-extrabold font-sans mt-0.5">
                      {roleLabel}
                    </div>
                  </div>
                </div>

                {userRole === 'admin' && (
                  <button
                    onClick={() => { onNavigate('admin'); setIsMobileMenuOpen(false); }}
                    className="w-full text-white py-3.5 rounded-2xl font-black text-xs md:text-sm flex items-center justify-center gap-2 cursor-pointer"
                    style={{ backgroundColor: '#3544e1' }}
                  >
                    <LayoutDashboard className="w-4 h-4" />
                    <span>إدارة المنصة</span>
                  </button>
                )}

                {userRole === 'instructor' && (
                  <button
                    onClick={() => { onNavigate('instructor'); setIsMobileMenuOpen(false); }}
                    className="w-full text-white py-3.5 rounded-2xl font-black text-xs md:text-sm flex items-center justify-center gap-2 cursor-pointer"
                    style={{ backgroundColor: '#3544e1' }}
                  >
                    <LayoutDashboard className="w-4 h-4" />
                    <span>إدارة المنصة العامة</span>
                  </button>
                )}

                {/* Same two links the desktop account dropdown offers a
                    student — now the ONLY way to reach them on mobile, since
                    that dropdown is hidden below `md`. */}
                {userRole === 'student' && (
                  <>
                    <button
                      onClick={() => { onNavigate('student'); setIsMobileMenuOpen(false); }}
                      className="w-full text-white py-3.5 rounded-2xl font-black text-xs md:text-sm flex items-center justify-center gap-2 cursor-pointer"
                      style={{ backgroundColor: '#3544e1' }}
                    >
                      <LayoutDashboard className="w-4 h-4" />
                      <span>كورساتي</span>
                    </button>

                    <button
                      onClick={() => { onNavigate('student/statistics'); setIsMobileMenuOpen(false); }}
                      className="w-full text-slate-700 dark:text-slate-200 ring-1 ring-inset ring-slate-900/[0.08] dark:ring-white/10 py-3.5 rounded-2xl font-bold text-xs md:text-sm flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <TrendingUp className="w-4 h-4" />
                      <span>إحصائياتي الدراسية</span>
                    </button>

                    <button
                      onClick={() => { onNavigate('student/center'); setIsMobileMenuOpen(false); }}
                      className="w-full text-slate-700 dark:text-slate-200 ring-1 ring-inset ring-slate-900/[0.08] dark:ring-white/10 py-3.5 rounded-2xl font-bold text-xs md:text-sm flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <Building2 className="w-4 h-4" />
                      <span>طالب سنتر</span>
                    </button>
                  </>
                )}

                <button
                  onClick={() => { if (onLogout) onLogout(); setIsMobileMenuOpen(false); onNavigate('home'); }}
                  className="w-full text-rose-600 dark:text-rose-400 ring-1 ring-inset ring-rose-200 dark:ring-rose-900/50 hover:bg-rose-50 dark:hover:bg-rose-950/30 py-3.5 rounded-2xl font-bold text-xs md:text-sm transition-colors duration-200 flex items-center justify-center gap-2 cursor-pointer active:scale-[0.99]"
                >
                  <LogOut className="w-4.5 h-4.5" />
                  <span>تسجيل الخروج</span>
                </button>
              </div>
            ) : (
              <div className="flex flex-col gap-2.5">
                <button
                  type="button"
                  onClick={() => { onNavigate('signup'); setIsMobileMenuOpen(false); }}
                  className="btn-primary h-12 w-full text-base"
                >
                  إنشاء حساب
                </button>
                <button
                  type="button"
                  onClick={() => { onNavigate('login'); setIsMobileMenuOpen(false); }}
                  className="btn-quiet h-12 w-full text-base"
                >
                  دخول
                </button>
              </div>
            )}
          </nav>
        </div>
      </div>
    </header>
  );
}
