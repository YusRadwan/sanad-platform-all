import { authService } from '../../services/authService';
import React, { useState, useEffect, useMemo } from 'react';
import { motion } from 'motion/react';
import { Search, Users, Phone, MapPin, GraduationCap, BookOpen, Sparkles, CheckCircle2, AlertTriangle } from 'lucide-react';
import { SubscriptionsManagementProps, Student } from '../../types/index';
import { enrollmentApiService } from '../../services/enrollmentApiService';
import StatCard from '../Layout/StatCard';
import {
  PageHeader,
  Card,
  CardHeader,
  Badge,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  SearchBox,
  Select,
  FormField,
  EmptyState,
  TablePagination,
  cn,
} from '../UI';

/** Rows per page — keeps one screen readable instead of stacking everything vertically. */
const PAGE_SIZE = 10;

/**
 * The account-status pill. Deliberately NOT `statusTone(student.status)`:
 * this cell is BINARY — anything that is not `'active'` is labelled "موقوف"
 * and was rose before. `statusTone` returns `neutral` for any unrecognised
 * status string, which would silently render a suspended account as a grey
 * "normal" chip and lose the warning. Pinned explicitly instead.
 */
function StatusBadge({ status, label }: { status: string | undefined; label: string }) {
  const isActive = status === 'active';
  return (
    <Badge size="sm" tone={isActive ? 'success' : 'danger'} icon={isActive ? CheckCircle2 : AlertTriangle}>
      {label}
    </Badge>
  );
}

/** One label/value pair in the mobile card's info grid. */
function InfoItem({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <span className="block text-[11px] font-bold text-slate-500 dark:text-slate-400">{label}</span>
      {children}
    </div>
  );
}









const EGYPT_GOVERNORATES = [
  'القاهرة', 'الجيزة', 'الإسكندرية', 'الدقهلية', 'البحر الأحمر', 
  'البحيرة', 'الفيوم', 'الغربية', 'الإسماعيلية', 'المنوفية', 
  'المنيا', 'القليوبية', 'الوادي الجديد', 'السويس', 'اسوان', 
  'اسيوط', 'بني سويف', 'بورسعيد', 'دمياط', 'الشرقية', 
  'جنوب سيناء', 'كفر الشيخ', 'مطروح', 'الأقصر', 'قنا', 
  'شمال سيناء', 'سوهاج'
];

export default function SubscriptionsManagement({ username: _username }: SubscriptionsManagementProps) {
  const [students, setStudents] = useState<Student[]>([]);
  const [loadError, setLoadError] = useState<string | null>(null);

  useEffect(() => {
    const fetchStudents = async () => {
      try {
        const data = await enrollmentApiService.getInstructorStudents();
        setStudents(data);
        setLoadError(null);
      } catch (e) {
        console.error('Failed to load students:', e);
        setLoadError('حدث خطأ أثناء تحميل بيانات الطلاب، يرجى إعادة المحاولة لاحقاً.');
      } finally {
      }
    };
    fetchStudents();
  }, []);

  // Instructor identity comes from the authenticated session (authService.getUser()).
  // The student list itself is fetched from the real API above
  // (enrollmentApiService.getInstructorStudents -> GET /enrollments/instructor-students).
    const getInstructorInfo = () => {
    try {
      const currentUser = authService.getUser();
      if (currentUser && currentUser.role === 'instructor') {
        return {
          name: currentUser.fullName || currentUser.username || '',
          grades: Array.isArray(currentUser.grades) ? currentUser.grades : [],
          subjects: Array.isArray(currentUser.subjects) ? currentUser.subjects : [],
          id: currentUser.id
        };
      }
      return null;
    } catch {
      return null;
    }
  };
  const currentTeacher = getInstructorInfo();
  const assignedGrades = currentTeacher?.grades || [];


  // 2. Search & filter states
  const [searchQuery, setSearchQuery] = useState('');
  const [governorateFilter, setGovernorateFilter] = useState('all');
  const [gradeFilter, setGradeFilter] = useState(() => {
    if (assignedGrades.length === 1) {
      return assignedGrades[0];
    }
    return 'all';
  });
  const [page, setPage] = useState(1);

  // Sync selection with assigned grades when they change
  React.useEffect(() => {
    if (assignedGrades.length === 1) {
      setGradeFilter(assignedGrades[0]);
    } else {
      setGradeFilter('all');
    }
  }, [assignedGrades]);

  // 3. Filter students: the API returns only instructor's students, so we can just use the state directly
  const instructorStudents = students;

  // 5. Apply search and filters
  const filteredStudents = useMemo(() => {
    return instructorStudents.filter(student => {
      // Search matches Student Name, Student Code, Student Phone, or Parent Phone
      const query = searchQuery.trim().toLowerCase();
      const matchesSearch = !query || 
        student.name.toLowerCase().includes(query) ||
        student.studentCode.toLowerCase().includes(query) ||
        (student.phone || '').includes(query) ||
        (student.parentPhone || '').includes(query);

      // Governorate match
      const matchesGov = governorateFilter === 'all' || student.governorate === governorateFilter;

      // Grade match
      const matchesGrade = gradeFilter === 'all' || student.gradeLevel === gradeFilter;

      return matchesSearch && matchesGov && matchesGrade;
    });
  }, [instructorStudents, searchQuery, governorateFilter, gradeFilter]);

  // A filter/search change can shrink the result set below the current page
  // number (e.g. going from page 3 to a 1-page result) — reset to page 1
  // whenever the filtered set changes so the table never renders empty while
  // real matches exist on an earlier page.
  useEffect(() => {
    setPage(1);
  }, [searchQuery, governorateFilter, gradeFilter]);

  // Calculations for stats widgets
  const stats = useMemo(() => {
    const activeCount = filteredStudents.filter(s => s.status === 'active').length;
    const suspendedCount = filteredStudents.filter(s => s.status === 'inactive').length;
    return {
      total: filteredStudents.length,
      active: activeCount,
      suspended: suspendedCount
    };
  }, [filteredStudents]);

  const totalPages = Math.max(1, Math.ceil(filteredStudents.length / PAGE_SIZE));
  const pagedStudents = filteredStudents.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      {/* Decorative background glows */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Page Title & Breadcrumbs */}
      <Badge tone="brand" icon={Sparkles} className="mb-1">
        بوابة المعلم الذكية
      </Badge>
      <PageHeader
        icon={Users}
        title="جدول الاشتراكات"
        description="متابعة طلابك المشتركين بكورساتك"
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'جدول الاشتراكات' }]}
      />

      {/* Fetch Error Banner */}
      {loadError && (
        <Card radius="panel" className="flex items-center gap-3 border border-rose-200 dark:border-rose-500/30 bg-rose-50/60 dark:bg-rose-500/5">
          <div className="p-3 rounded-2xl shrink-0 bg-rose-500/10 text-rose-600 dark:text-rose-400">
            <AlertTriangle className="w-5 h-5" />
          </div>
          <span className="text-sm font-bold text-rose-700 dark:text-rose-400">{loadError}</span>
        </Card>
      )}

      {/* STATS HIGHLIGHT WIDGETS */}
      {instructorStudents.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <StatCard
            index={0}
            icon={Users}
            label="إجمالي المشتركين"
            value={`${stats.total} طالباً`}
            color="blue"
          />
          <StatCard
            index={1}
            icon={CheckCircle2}
            label="اشتراكات مفعلة"
            value={`${stats.active} طالباً`}
            color="emerald"
          />
          <StatCard
            index={2}
            icon={AlertTriangle}
            label="حسابات موقوفة"
            value={`${stats.suspended} طالباً`}
            color="rose"
          />
        </div>
      )}

      {/* FILTER & SEARCH CONTROL BLOCK */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.08, duration: 0.35 }}>
      <Card radius="panel">
        <CardHeader
          className="mb-4"
          divider
          icon={Search}
          title="أدوات البحث والفرز المتقدم"
        />

        <div className="grid grid-cols-1 md:grid-cols-12 gap-4">
          {/* Intelligent Search Input */}
          <FormField label="البحث السريع الذكي:" className="md:col-span-6">
            {({ id }) => (
              <SearchBox
                id={id}
                size="lg"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                onClear={() => setSearchQuery('')}
                placeholder="ابحث باسم الطالب، كود الطالب، أو رقم الهاتف..."
              />
            )}
          </FormField>

          {/* Governorate Filter */}
          <FormField label="فرز حسب المحافظة:" className="md:col-span-3">
            {({ id }) => (
              <Select
                id={id}
                size="lg"
                value={governorateFilter}
                onChange={(e) => setGovernorateFilter(e.target.value)}
              >
                <option value="all">كافة المحافظات ({EGYPT_GOVERNORATES.length})</option>
                {EGYPT_GOVERNORATES.map(gov => (
                  <option key={gov} value={gov}>{gov}</option>
                ))}
              </Select>
            )}
          </FormField>

          {/* Grade Level Filter */}
          <FormField label="فرز حسب الصف الدراسي:" className="md:col-span-3">
            {({ id }) => (
              /* `disabled` is unchanged — still driven by `assignedGrades.length <= 1`.
                 The bespoke disabled palette is gone because `controlBase` already
                 ships the platform's disabled skin. */
              <Select
                id={id}
                size="lg"
                value={gradeFilter}
                onChange={(e) => setGradeFilter(e.target.value)}
                disabled={assignedGrades.length <= 1}
              >
                {assignedGrades.length > 1 && (
                  <option value="all">كافة الصفوف المتاحة ({assignedGrades.length})</option>
                )}
                {assignedGrades.map((grade: any) => (
                  <option key={grade} value={grade}>{grade}</option>
                ))}
              </Select>
            )}
          </FormField>
        </div>
      </Card>
      </motion.div>

      {/* SUBSCRIPTIONS TABLE VIEW & CARDS */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.16, duration: 0.35 }} className="space-y-4">
        {filteredStudents.length > 0 ? (
          <Card radius="panel" padding="none" className="overflow-hidden">

            {/* Desktop View Table */}
            <div className="hidden lg:block">
              <Table>
                <TableHeader>
                  <tr>
                    <TableHead>اسم الطالب والرمز</TableHead>
                    <TableHead>رقم هاتف الطالب</TableHead>
                    <TableHead>رقم هاتف ولي الأمر</TableHead>
                    <TableHead>المحافظة</TableHead>
                    <TableHead>الصف الدراسي</TableHead>
                    <TableHead align="center">المساقات المشترك بها</TableHead>
                    <TableHead align="center">حالة الحساب</TableHead>
                  </tr>
                </TableHeader>
                <TableBody>
                  {pagedStudents.map((student) => {
                    // Course titles the student is subscribed to with this instructor,
                    // already scoped server-side to this instructor's own courses.
                    const courseNames = student.course_titles?.split(', ').filter(Boolean) || [];

                    return (
                      <TableRow
                        key={student.id}
                        /* The suspended tint was `bg-rose-50/5 dark:bg-rose-950/2` —
                           5% and 2% opacity, i.e. invisible. Raised to a tint that
                           actually reads while staying subtle. */
                        className={cn(student.status === 'inactive' && 'bg-rose-50/60 dark:bg-rose-500/5')}
                      >
                        {/* Student Name & Code */}
                        <TableCell className="whitespace-nowrap">
                          <div className="space-y-1">
                            <span className="font-black text-slate-900 dark:text-white block">
                              {student.name}
                            </span>
                            <Badge size="sm" tone="brand" className="font-mono">
                              كود: {student.studentCode}
                            </Badge>
                          </div>
                        </TableCell>

                        {/* Student Phone */}
                        <TableCell className="whitespace-nowrap">
                          <div className="flex items-center gap-1.5 font-mono font-bold text-slate-700 dark:text-slate-300">
                            <Phone className="w-3.5 h-3.5 text-blue-500 shrink-0" />
                            <span>{student.phone}</span>
                          </div>
                        </TableCell>

                        {/* Parent Phone */}
                        <TableCell className="whitespace-nowrap">
                          <div className="flex items-center gap-1.5 font-mono text-slate-500 dark:text-slate-400">
                            <Users className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                            <span>{student.parentPhone}</span>
                          </div>
                        </TableCell>

                        {/* Governorate */}
                        <TableCell className="whitespace-nowrap">
                          <div className="flex items-center gap-1.5 font-bold text-slate-700 dark:text-slate-300">
                            <MapPin className="w-3.5 h-3.5 text-slate-400 dark:text-slate-500 shrink-0" />
                            <span>{student.governorate}</span>
                          </div>
                        </TableCell>

                        {/* Grade Level */}
                        <TableCell className="whitespace-nowrap">
                          <div className="flex items-center gap-1.5 font-bold text-slate-800 dark:text-slate-200">
                            <GraduationCap className="w-3.5 h-3.5 text-blue-600 dark:text-blue-400 shrink-0" />
                            <span>{student.gradeLevel || student.educationalStage}</span>
                          </div>
                        </TableCell>

                        {/* Instructor's Courses */}
                        <TableCell align="center" className="whitespace-nowrap">
                          <div className="flex flex-col items-center gap-1">
                            {courseNames.length > 0 ? (
                              courseNames.map((name, idx) => (
                                <Badge key={idx} size="sm" tone="brand" icon={BookOpen}>
                                  {name}
                                </Badge>
                              ))
                            ) : (
                              <span className="text-xs font-bold text-slate-400 dark:text-slate-500">لا يوجد</span>
                            )}
                          </div>
                        </TableCell>

                        {/* Status */}
                        <TableCell align="center" className="whitespace-nowrap">
                          <StatusBadge
                            status={student.status}
                            label={student.status === 'active' ? 'مفعّل ونشط' : 'موقف الحساب'}
                          />
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>

            {/* Mobile / Tablet Cards View */}
            <div className="block lg:hidden p-4 space-y-4">
              {pagedStudents.map((student) => {
                const courseNames = student.course_titles?.split(', ').filter(Boolean) || [];

                return (
                  <Card
                    key={student.id}
                    className={cn(
                      'space-y-4',
                      student.status === 'inactive' && 'border-rose-200 dark:border-rose-500/30 bg-rose-50/40 dark:bg-rose-500/5',
                    )}
                  >
                    {/* Top Segment: Student name and status */}
                    <div className="flex justify-between items-start gap-2 border-b border-slate-100 dark:border-slate-800/60 pb-3">
                      <div className="space-y-1 min-w-0">
                        <h4 className="font-black text-base text-slate-900 dark:text-white">{student.name}</h4>
                        <div className="flex items-center gap-1.5 flex-wrap">
                          <span className="block text-[11px] font-bold text-slate-500 dark:text-slate-400">
                            {student.gradeLevel || student.educationalStage}
                          </span>
                          <Badge size="sm" tone="brand" className="font-mono">
                            كود: {student.studentCode}
                          </Badge>
                        </div>
                      </div>

                      <StatusBadge
                        status={student.status}
                        label={student.status === 'active' ? 'نشط' : 'معلق'}
                      />
                    </div>

                    {/* Info Grid */}
                    <div className="grid grid-cols-2 gap-y-3 gap-x-2 text-sm">
                      <InfoItem label="المحافظة والجغرافيا:">
                        <span className="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1 select-text">
                          <MapPin className="w-3 h-3 text-slate-400 shrink-0" />
                          <span>{student.governorate}</span>
                        </span>
                      </InfoItem>

                      <InfoItem label="رقم الطالب:">
                        <span className="font-mono font-bold text-slate-700 dark:text-slate-300 block select-text">{student.phone}</span>
                      </InfoItem>

                      <InfoItem label="رقم ولي الأمر:">
                        <span className="font-mono font-bold text-slate-700 dark:text-slate-300 block select-text">{student.parentPhone}</span>
                      </InfoItem>

                      <InfoItem label="الكورسات المشتركة:">
                        <div className="flex flex-col gap-1 items-start mt-1">
                          {courseNames.length > 0 ? (
                            courseNames.map((name, idx) => (
                              <Badge key={idx} size="sm" tone="brand">
                                {name}
                              </Badge>
                            ))
                          ) : (
                            <span className="text-xs font-bold text-slate-400 dark:text-slate-500">لا يوجد</span>
                          )}
                        </div>
                      </InfoItem>
                    </div>
                  </Card>
                );
              })}
            </div>

            {filteredStudents.length > 0 && (
              <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
                <TablePagination
                  page={page}
                  totalPages={totalPages}
                  onPageChange={setPage}
                  totalItems={filteredStudents.length}
                  pageSize={PAGE_SIZE}
                />
              </div>
            )}

          </Card>
        ) : (
          /* Elegant Empty State Card */
          <motion.div
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
          >
            <Card radius="panel">
              <EmptyState
                icon={Users}
                title="لم يتم العثور على أي مشتركين يطابقون خيارات البحث"
                description={
                  instructorStudents.length === 0
                    ? "لا يوجد أي طلاب مسجلين حالياً في كورساتك التعليمية المتاحة على المنصة."
                    : "يرجى التحقق من صياغة كلمات البحث أو جرب تغيير معايير الفلترة والمحافظات."
                }
              />
            </Card>
          </motion.div>
        )}
      </motion.div>

    </div>
  );
}
