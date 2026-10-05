import React, { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { LoginSession } from '../../types/index';
import { adminService } from '../../services/adminService';

import { Activity, Calendar, CheckCircle2, Clock, Ban, Eye, ShieldAlert, Monitor, Smartphone, Globe, User, LogOut, Laptop } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import {
  PageHeader,
  Card,
  Toolbar,
  SearchBox,
  Select,
  Input,
  Badge,
  Button,
  Dialog,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  TablePagination,
} from '../UI';
import StatCard from '../Layout/StatCard';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;
import type { BadgeTone } from '../UI';

/**
 * Purely visual redesign onto the SANAD design system.
 *
 * Nothing behavioural moved: the same eight filter state hooks, the same
 * `adminService.getLoginActivity()` effect with its `[]` dependency array, the
 * same `uniq`/`stats`/`filteredSessions` derivations (including the
 * case-insensitive `userType` compare and the Arabic `deviceType` labels), and
 * the same `selectedSession` modal payload.
 *
 * `statusTone()` from the design system is deliberately NOT used here: it maps
 * every "expired"-like status to `danger` (red), whereas this page's original
 * design rendered `Session Expired` in AMBER — an expired session is a normal
 * lifecycle end, not a security event, and only `Blocked` /
 * `Suspicious Activity` are meant to read as red. The per-status `tone` below
 * preserves that original colour intent exactly.
 */
const getStatusDetails = (status: LoginSession['status']): {
  label: string;
  color: string;
  bg: string;
  border: string;
  icon: LucideIcon;
  tone: BadgeTone;
} => {
  switch (status) {
    case 'Active':
      return { label: 'نشط', color: 'text-emerald-600 dark:text-emerald-400', bg: 'bg-emerald-100 dark:bg-emerald-900/30', border: 'border-emerald-200 dark:border-emerald-800', icon: CheckCircle2, tone: 'success' };
    case 'Logged Out':
      return { label: 'تم تسجيل الخروج', color: 'text-slate-600 dark:text-slate-400', bg: 'bg-slate-100 dark:bg-slate-800', border: 'border-slate-200 dark:border-slate-700', icon: LogOut, tone: 'neutral' };
    case 'Session Expired':
      return { label: 'انتهت الجلسة', color: 'text-amber-600 dark:text-amber-400', bg: 'bg-amber-100 dark:bg-amber-900/30', border: 'border-amber-200 dark:border-amber-800', icon: Clock, tone: 'warning' };
    case 'Blocked':
      return { label: 'محظور', color: 'text-red-600 dark:text-red-400', bg: 'bg-red-100 dark:bg-red-900/30', border: 'border-red-200 dark:border-red-800', icon: Ban, tone: 'danger' };
    case 'Suspicious Activity':
      return { label: 'نشاط مشبوه', color: 'text-rose-600 dark:text-rose-400', bg: 'bg-rose-100 dark:bg-rose-900/30', border: 'border-rose-200 dark:border-rose-800', icon: ShieldAlert, tone: 'danger' };
    default:
      return { label: status as string, color: 'text-slate-600', bg: 'bg-slate-100', border: 'border-slate-200', icon: Activity, tone: 'neutral' };
  }
};

const formatDate = (dateString: string | null | undefined) => {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('ar-EG-u-nu-latn', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date);
};

/** Label/value pair inside the details modal. */
function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">{label}</p>
      <div className="text-sm font-medium text-slate-700 dark:text-slate-300">{children}</div>
    </div>
  );
}

/** Grouped block of DetailRows inside the details modal. */
function DetailGroup({
  title,
  dotClass,
  children,
}: {
  title: string;
  dotClass: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <h4 className="text-sm font-black text-slate-900 dark:text-white mb-3 flex items-center gap-2">
        <span className={`w-2 h-2 rounded-full ${dotClass}`} />
        {title}
      </h4>
      <div className="space-y-3.5 bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
        {children}
      </div>
    </div>
  );
}

export default function LoginActivity() {
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [countryFilter, setCountryFilter] = useState<string>('all');
  const [osFilter, setOsFilter] = useState<string>('all');
  const [browserFilter, setBrowserFilter] = useState<string>('all');
  const [deviceFilter, setDeviceFilter] = useState<string>('all');
  const [dateFilter, setDateFilter] = useState<string>('');

  const [selectedSession, setSelectedSession] = useState<LoginSession | null>(null);
  const [sessionsList, setSessionsList] = useState<LoginSession[]>([]);
  const [page, setPage] = useState(1);

  useEffect(() => {
    (async () => {
      try {
        setSessionsList(await adminService.getLoginActivity());
      } catch (err) {
        console.error('Failed to fetch login activity:', err);
        setSessionsList([]);
      }
    })();
  }, []);

  // Falsy values are dropped so the dropdowns never render a blank option.
  // `country`/`city` are always absent: there is no geo-IP lookup in this
  // system, so that filter simply has nothing to offer rather than showing
  // a made-up location.
  const uniq = (values: (string | undefined)[]) => Array.from(new Set(values.filter(Boolean) as string[]));
  const uniqueCountries = uniq(sessionsList.map(s => s.country));
  const uniqueOs = uniq(sessionsList.map(s => s.os));
  const uniqueBrowsers = uniq(sessionsList.map(s => s.browser));
  const uniqueDevices = uniq(sessionsList.map(s => s.deviceType));

  const stats = {
    total: sessionsList.length,
    active: sessionsList.filter(s => s.status === 'Active').length,
    today: sessionsList.filter(s => {
      try {
        return s.loginDate ? new Date(s.loginDate).toDateString() === new Date().toDateString() : false;
      } catch {
        return false;
      }
    }).length,
    uniqueDevices: uniqueDevices.length,
    suspicious: sessionsList.filter(s => s.status === 'Suspicious Activity').length
  };

  const filteredSessions = sessionsList.filter(s => {
    const matchesSearch =
      (s.userName || '').includes(searchTerm) ||
      String(s.userId || '').includes(searchTerm) ||
      (s.userPhone || '').includes(searchTerm) ||
      (s.userEmail || '').includes(searchTerm) ||
      (s.ipAddress || '').includes(searchTerm);

    const matchesStatus = statusFilter === 'all' || s.status === statusFilter;
    // Case-insensitive: the <option> values are "Student"/"Instructor"/"Admin"
    // but AdminDashboardService::loginActivity emits `userType` as the raw
    // users.role — lowercase "student"/"instructor"/"admin". The strict compare
    // this replaces therefore never matched, so picking ANY user type in the
    // نوع المستخدم dropdown emptied the table.
    const matchesType =
      typeFilter === 'all' ||
      (s.userType || '').toLowerCase() === typeFilter.toLowerCase();
    const matchesCountry = countryFilter === 'all' || s.country === countryFilter;
    const matchesOs = osFilter === 'all' || s.os === osFilter;
    const matchesBrowser = browserFilter === 'all' || s.browser === browserFilter;
    const matchesDevice = deviceFilter === 'all' || s.deviceType === deviceFilter;
    const matchesDate = !dateFilter || (s.loginDate || '').startsWith(dateFilter);

    return matchesSearch && matchesStatus && matchesType && matchesCountry && matchesOs && matchesBrowser && matchesDevice && matchesDate;
  });

  // A filter/search change can shrink the result set below the current page
  // number (e.g. going from page 3 to a 1-page result) — reset to page 1
  // whenever the filtered set changes so the table never renders empty while
  // real matches exist on an earlier page.
  useEffect(() => {
    setPage(1);
  }, [searchTerm, statusFilter, typeFilter, countryFilter, osFilter, browserFilter, deviceFilter, dateFilter]);

  const totalPages = Math.max(1, Math.ceil(filteredSessions.length / PAGE_SIZE));
  const pagedSessions = filteredSessions.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div className="relative space-y-6 lg:space-y-8">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-rose-500/8 dark:bg-rose-500/12 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-600/8 dark:bg-blue-600/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-violet-500/6 dark:bg-violet-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        className="mb-0"
        icon={Activity}
        title="مراقبة نشاط تسجيل الدخول"
        description="مراجعة جلسات تسجيل الدخول"
      />

      {/* Stats Cards */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4"
      >
        <StatCard index={0} label="إجمالي الجلسات" value={stats.total} icon={Activity} color="blue" />
        <StatCard index={1} label="الجلسات النشطة" value={stats.active} icon={CheckCircle2} color="emerald" />
        <StatCard index={2} label="جلسات اليوم" value={stats.today} icon={Calendar} color="indigo" />
        <StatCard index={3} label="أجهزة فريدة" value={stats.uniqueDevices} icon={Monitor} color="purple" />

        {/* The one deliberately saturated tile — it carries the security headline. */}
        <Card className="flex flex-col justify-between bg-rose-600 dark:bg-rose-600 border-rose-600 dark:border-rose-600 text-white shadow-md shadow-rose-600/20">
          <div className="flex items-center justify-between gap-3 mb-4">
            <h3 className="text-sm font-bold text-rose-100">محاولات مشبوهة</h3>
            <div className="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center shrink-0">
              <ShieldAlert className="w-4.5 h-4.5" />
            </div>
          </div>
          <p className="text-xl md:text-2xl font-black font-mono tracking-tight">{stats.suspicious}</p>
        </Card>
      </motion.div>

      {/* Filters & Search */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.08, duration: 0.35 }}
      >
      <Card className="space-y-4">
        <Toolbar>
          <SearchBox
            className="w-full"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            onClear={() => setSearchTerm('')}
            placeholder="ابحث بالاسم، الكود، رقم الهاتف، البريد الإلكتروني، او IP..."
          />
        </Toolbar>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <Select
            aria-label="نوع المستخدم"
            value={typeFilter}
            onChange={(e) => setTypeFilter(e.target.value)}
          >
            <option value="all">جميع أنواع المستخدمين</option>
            <option value="Student">طالب</option>
            <option value="Instructor">مدرس</option>
            <option value="Admin">مشرف</option>
          </Select>

          <Select
            aria-label="حالة الجلسة"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            <option value="all">جميع الحالات</option>
            <option value="Active">نشط</option>
            <option value="Logged Out">تم تسجيل الخروج</option>
            <option value="Session Expired">انتهت الجلسة</option>
            <option value="Blocked">محظور</option>
            <option value="Suspicious Activity">نشاط مشبوه</option>
          </Select>

          <Select
            aria-label="الدولة"
            value={countryFilter}
            onChange={(e) => setCountryFilter(e.target.value)}
          >
            <option value="all">جميع الدول</option>
            {uniqueCountries.map(c => <option key={c} value={c}>{c}</option>)}
          </Select>

          <Input
            type="date"
            aria-label="تاريخ الدخول"
            value={dateFilter}
            onChange={(e) => setDateFilter(e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-4 border-t border-slate-100 dark:border-slate-800">
          <Select
            aria-label="نظام التشغيل"
            value={osFilter}
            onChange={(e) => setOsFilter(e.target.value)}
          >
            <option value="all">نظام التشغيل (الكل)</option>
            {uniqueOs.map(o => <option key={o} value={o}>{o}</option>)}
          </Select>

          <Select
            aria-label="المتصفح"
            value={browserFilter}
            onChange={(e) => setBrowserFilter(e.target.value)}
          >
            <option value="all">المتصفح (الكل)</option>
            {uniqueBrowsers.map(b => <option key={b} value={b}>{b}</option>)}
          </Select>

          <Select
            aria-label="نوع الجهاز"
            value={deviceFilter}
            onChange={(e) => setDeviceFilter(e.target.value)}
          >
            <option value="all">نوع الجهاز (الكل)</option>
            {uniqueDevices.map(d => <option key={d} value={d}>{d}</option>)}
          </Select>
        </div>
      </Card>
      </motion.div>

      {/* Sessions Table */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.16, duration: 0.35 }}
      >
      <Card padding="none" className="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow hoverable={false}>
              <TableHead>المستخدم</TableHead>
              <TableHead>تاريخ الدخول</TableHead>
              <TableHead>آخر نشاط</TableHead>
              <TableHead>الجهاز/النظام</TableHead>
              <TableHead>البلد/IP</TableHead>
              <TableHead>الحالة</TableHead>
              <TableHead align="center">التفاصيل</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {pagedSessions.map((session) => {
              const statusInfo = getStatusDetails(session.status);
              const StatusIcon = statusInfo.icon;
              const isSuspicious = session.status === 'Suspicious Activity';

              return (
                <TableRow
                  key={session.id}
                  className={isSuspicious ? 'bg-rose-50/60 dark:bg-rose-500/5 hover:bg-rose-100/60! dark:hover:bg-rose-500/10!' : undefined}
                >
                  <TableCell className="whitespace-nowrap">
                    <div className="flex items-center gap-3">
                      <div className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${
                        /* lowercased for the same reason as matchesType above:
                           `userType` is the raw users.role value. */
                        (session.userType || '').toLowerCase() === 'admin' ? 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400' :
                        (session.userType || '').toLowerCase() === 'instructor' ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' :
                        'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'
                      }`}>
                        <User className="w-4 h-4" />
                      </div>
                      <div className="flex flex-col">
                        <span className="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                          {session.userName}
                          {isSuspicious && <ShieldAlert className="w-3.5 h-3.5 text-rose-500" />}
                        </span>
                        <span className="text-xs font-medium text-slate-500 dark:text-slate-400">{session.userId} ({session.userType})</span>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">
                    {formatDate(session.loginDate)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">
                    {formatDate(session.lastActivity)}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <div className="flex flex-col">
                      <span className="text-sm font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        {/* AdminDashboardService::loginActivity classifies the
                            user-agent into Arabic labels — 'هاتف محمول',
                            'جهاز لوحي', 'حاسوب', 'غير معروف'. The previous
                            'Mobile'/'Laptop' comparisons matched none of
                            them, so every row got the desktop icon. */}
                        {session.deviceType === 'هاتف محمول' ? <Smartphone className="w-3.5 h-3.5" /> :
                         session.deviceType === 'جهاز لوحي' ? <Laptop className="w-3.5 h-3.5" /> :
                         <Monitor className="w-3.5 h-3.5" />}
                        {session.os}
                      </span>
                      <span className="text-xs font-medium text-slate-500 dark:text-slate-400">{session.browser}</span>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <div className="flex flex-col">
                      <span className="text-sm font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                        <Globe className="w-3.5 h-3.5" />
                        {session.country}
                      </span>
                      <span className="text-xs font-mono font-medium text-slate-500 dark:text-slate-400">{session.ipAddress}</span>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Badge tone={statusInfo.tone} icon={StatusIcon}>
                      {statusInfo.label}
                    </Badge>
                  </TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    <Button
                      variant="ghost"
                      size="sm"
                      iconOnly
                      onClick={() => setSelectedSession(session)}
                      title="عرض التفاصيل"
                      aria-label="عرض التفاصيل"
                    >
                      <Eye className="w-4 h-4" />
                    </Button>
                  </TableCell>
                </TableRow>
              );
            })}

            {filteredSessions.length === 0 && (
              <TableEmptyState
                colSpan={7}
                icon={Activity}
                title="لا توجد سجلات مطابقة للبحث"
                description="حاول تغيير كلمات البحث أو المرشحات"
              />
            )}
          </TableBody>
        </Table>

        {filteredSessions.length > 0 && (
          <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
            <TablePagination
              page={page}
              totalPages={totalPages}
              onPageChange={setPage}
              totalItems={filteredSessions.length}
              pageSize={PAGE_SIZE}
            />
          </div>
        )}
      </Card>
      </motion.div>

      {/* Session Details Modal */}
      <Dialog
        open={selectedSession !== null}
        onClose={() => setSelectedSession(null)}
        title="تفاصيل جلسة الدخول"
        icon={Activity}
        size="lg"
      >
        {selectedSession && (
          <div className="space-y-6">
            {/* Status Banner */}
            <div className={`p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border ${getStatusDetails(selectedSession.status).bg} ${getStatusDetails(selectedSession.status).border}`}>
              <div className="flex items-center gap-3">
                <div className={`w-12 h-12 rounded-xl flex items-center justify-center bg-white/50 dark:bg-black/20 ${getStatusDetails(selectedSession.status).color}`}>
                  {React.createElement(getStatusDetails(selectedSession.status).icon, { className: "w-6 h-6" })}
                </div>
                <div>
                  <p className="text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">حالة الجلسة</p>
                  <p className={`text-lg font-black ${getStatusDetails(selectedSession.status).color}`}>
                    {getStatusDetails(selectedSession.status).label}
                  </p>
                </div>
              </div>
              {selectedSession.status === 'Suspicious Activity' && (
                <div className="text-sm font-bold text-rose-600 dark:text-rose-400 flex items-center gap-2">
                  <ShieldAlert className="w-4 h-4" />
                  تنبيه أمني محتمل
                </div>
              )}
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
              <DetailGroup title="بيانات المستخدم" dotClass="bg-blue-600">
                <DetailRow label="الاسم">
                  <span className="font-black text-slate-900 dark:text-white">{selectedSession.userName}</span>
                </DetailRow>
                <DetailRow label="معرف المستخدم / النوع">
                  <span className="font-mono">
                    {selectedSession.userId} <span className="text-xs text-slate-500">({selectedSession.userType})</span>
                  </span>
                </DetailRow>
                <DetailRow label="البريد الإلكتروني">
                  <span className="font-mono">{selectedSession.userEmail}</span>
                </DetailRow>
                <DetailRow label="رقم الهاتف">
                  <span className="font-mono" dir="ltr">{selectedSession.userPhone}</span>
                </DetailRow>
              </DetailGroup>

              <DetailGroup title="الشبكة والموقع" dotClass="bg-indigo-600">
                <DetailRow label="الدولة">
                  <span className="font-black text-slate-900 dark:text-white flex items-center gap-1.5">
                    <Globe className="w-3.5 h-3.5 text-slate-400" />
                    {selectedSession.country}
                  </span>
                </DetailRow>
                <DetailRow label="المدينة">{selectedSession.city}</DetailRow>
                <DetailRow label="عنوان IP">
                  <span className="font-mono">{selectedSession.ipAddress}</span>
                </DetailRow>
              </DetailGroup>

              <DetailGroup title="معلومات الجهاز" dotClass="bg-purple-600">
                <DetailRow label="نوع الجهاز">
                  <span className="font-black text-slate-900 dark:text-white flex items-center gap-1.5">
                    {selectedSession.deviceType === 'Mobile' ? <Smartphone className="w-3.5 h-3.5 text-slate-400" /> :
                     selectedSession.deviceType === 'Laptop' ? <Laptop className="w-3.5 h-3.5 text-slate-400" /> :
                     <Monitor className="w-3.5 h-3.5 text-slate-400" />}
                    {selectedSession.deviceType}
                  </span>
                </DetailRow>
                <DetailRow label="نظام التشغيل">{selectedSession.os}</DetailRow>
                <DetailRow label="المتصفح">{selectedSession.browser}</DetailRow>
              </DetailGroup>

              <DetailGroup title="وقت الجلسة" dotClass="bg-emerald-600">
                <DetailRow label="تاريخ ووقت تسجيل الدخول">
                  <span className="text-slate-900 dark:text-white">{formatDate(selectedSession.loginDate)}</span>
                </DetailRow>
                <DetailRow label="آخر نشاط مسجل">
                  <span className="text-slate-900 dark:text-white">{formatDate(selectedSession.lastActivity)}</span>
                </DetailRow>
                <DetailRow label="مدة الجلسة">
                  <span className="flex items-center gap-1.5">
                    <Clock className="w-3.5 h-3.5 text-slate-400" />
                    {selectedSession.sessionDuration}
                  </span>
                </DetailRow>
              </DetailGroup>
            </div>

            <div className="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800 p-3 rounded-xl">
              <span className="w-1.5 h-1.5 rounded-full bg-slate-400 dark:bg-slate-500 shrink-0" />
              هذه الصفحة مخصصة للمراقبة الأمنية فقط ولا تسمح بتعديل السجلات يدوياً لضمان سلامة التدقيق والمراجعة.
            </div>
          </div>
        )}
      </Dialog>
    </div>
  );
}
