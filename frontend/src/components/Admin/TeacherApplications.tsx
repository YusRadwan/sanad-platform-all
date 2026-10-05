import { useEffect, useState } from 'react';
import { motion } from 'motion/react';
import {
  Inbox, User, Phone, MapPin, Cake, Briefcase, GraduationCap, Layers,
  Link2, Calendar, Eye, ExternalLink,
} from 'lucide-react';
import { teacherApplicationService } from '../../services/teacherApplicationService';
import { TeacherApplication } from '../../types/index';
import {
  PageHeader,
  Card,
  SearchBox,
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

const PAGE_SIZE = 10;

/** Label/value pair inside the details modal — same convention as LoginActivity.tsx. */
function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <p className="text-xs font-bold text-slate-500 dark:text-slate-400 mb-1">{label}</p>
      <div className="text-sm font-medium text-slate-700 dark:text-slate-300">{children}</div>
    </div>
  );
}

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

const formatDate = (dateString: string | null | undefined) => {
  if (!dateString) return '-';
  const date = new Date(dateString.replace(' ', 'T'));
  return new Intl.DateTimeFormat('ar-EG-u-nu-latn', {
    year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  }).format(date);
};

/**
 * "طلب مدرس جديد" — read-only admin review list. Open decision 10.23.
 *
 * No approve/reject action anywhere on this page — the request explicitly
 * asked only for the submitted data to be shown ("وبس"). Purely a list +
 * a details modal, same shape as LoginActivity.tsx's own review screen.
 */
export default function TeacherApplications() {
  const [applications, setApplications] = useState<TeacherApplication[]>([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<TeacherApplication | null>(null);

  useEffect(() => {
    (async () => {
      try {
        setApplications(await teacherApplicationService.listForAdmin());
      } catch (err) {
        console.error('Failed to fetch teacher applications:', err);
        setApplications([]);
      }
    })();
  }, []);

  const filtered = applications.filter((a) =>
    a.full_name.includes(searchTerm) ||
    a.phone.includes(searchTerm) ||
    a.whatsapp.includes(searchTerm) ||
    a.subject.includes(searchTerm) ||
    a.governorate.includes(searchTerm)
  );

  useEffect(() => {
    setPage(1);
  }, [searchTerm]);

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const paged = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  const stats = {
    total: applications.length,
    thisWeek: applications.filter((a) => {
      const created = new Date(a.created_at.replace(' ', 'T')).getTime();
      return Number.isFinite(created) && Date.now() - created <= 7 * 24 * 60 * 60 * 1000;
    }).length,
    uniqueSubjects: new Set(applications.map((a) => a.subject.trim())).size,
    uniqueGovernorates: new Set(applications.map((a) => a.governorate)).size,
  };

  return (
    <div className="relative space-y-6 lg:space-y-8">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/8 dark:bg-blue-600/12 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-indigo-500/8 dark:bg-indigo-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        className="mb-0"
        icon={Inbox}
        title="طلب مدرس جديد"
        description="طلبات التقديم للتدريس المُرسَلة من الصفحة الرئيسية"
      />

      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
        className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"
      >
        <StatCard index={0} label="إجمالي الطلبات" value={stats.total} icon={Inbox} color="blue" />
        <StatCard index={1} label="خلال آخر أسبوع" value={stats.thisWeek} icon={Calendar} color="emerald" />
        <StatCard index={2} label="مواد مختلفة" value={stats.uniqueSubjects} icon={GraduationCap} color="purple" />
        <StatCard index={3} label="محافظات مختلفة" value={stats.uniqueGovernorates} icon={MapPin} color="indigo" />
      </motion.div>

      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.08, duration: 0.35 }}
      >
        <Card className="space-y-4">
          <SearchBox
            className="w-full"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            onClear={() => setSearchTerm('')}
            placeholder="ابحث بالاسم، الهاتف، الواتساب، المادة، أو المحافظة..."
          />
        </Card>
      </motion.div>

      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.16, duration: 0.35 }}
      >
        <Card padding="none" className="overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow hoverable={false}>
                <TableHead>الاسم</TableHead>
                <TableHead>الهاتف / الواتساب</TableHead>
                <TableHead>المحافظة</TableHead>
                <TableHead>المادة</TableHead>
                <TableHead>المرحلة</TableHead>
                <TableHead>الخبرة</TableHead>
                <TableHead>تاريخ التقديم</TableHead>
                <TableHead align="center">التفاصيل</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {paged.map((application) => (
                <TableRow key={application.id}>
                  <TableCell className="whitespace-nowrap">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center shrink-0 bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                        <User className="w-4 h-4" />
                      </div>
                      <span className="text-sm font-black text-slate-900 dark:text-white">{application.full_name}</span>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400" dir="ltr">
                    <div className="flex flex-col text-right">
                      <span className="font-mono text-xs">{application.phone}</span>
                      <span className="font-mono text-xs">{application.whatsapp}</span>
                    </div>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">{application.governorate}</TableCell>
                  <TableCell className="whitespace-nowrap text-slate-700 dark:text-slate-300 font-bold">{application.subject}</TableCell>
                  <TableCell className="whitespace-nowrap">
                    <Badge tone="neutral">{application.section_name}</Badge>
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">{application.years_of_experience} سنة</TableCell>
                  <TableCell className="whitespace-nowrap text-slate-500 dark:text-slate-400">{formatDate(application.created_at)}</TableCell>
                  <TableCell align="center" className="whitespace-nowrap">
                    <Button
                      variant="ghost"
                      size="sm"
                      iconOnly
                      onClick={() => setSelected(application)}
                      title="عرض التفاصيل"
                      aria-label="عرض التفاصيل"
                    >
                      <Eye className="w-4 h-4" />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}

              {filtered.length === 0 && (
                <TableEmptyState
                  colSpan={8}
                  icon={Inbox}
                  title="لا توجد طلبات مطابقة"
                  description="لم يتقدّم أي مدرس بعد، أو لا توجد نتائج مطابقة للبحث"
                />
              )}
            </TableBody>
          </Table>

          {filtered.length > 0 && (
            <div className="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
              <TablePagination
                page={page}
                totalPages={totalPages}
                onPageChange={setPage}
                totalItems={filtered.length}
                pageSize={PAGE_SIZE}
              />
            </div>
          )}
        </Card>
      </motion.div>

      <Dialog
        open={selected !== null}
        onClose={() => setSelected(null)}
        title="تفاصيل طلب التدريس"
        icon={Inbox}
        size="lg"
      >
        {selected && (
          <div className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
              <DetailGroup title="البيانات الشخصية" dotClass="bg-blue-600">
                <DetailRow label="الاسم الرباعي">
                  <span className="font-black text-slate-900 dark:text-white">{selected.full_name}</span>
                </DetailRow>
                <DetailRow label="رقم الهاتف">
                  <span className="font-mono flex items-center gap-1.5" dir="ltr">
                    <Phone className="w-3.5 h-3.5 text-slate-400" />
                    {selected.phone}
                  </span>
                </DetailRow>
                <DetailRow label="رقم الواتساب">
                  <span className="font-mono flex items-center gap-1.5" dir="ltr">
                    <Phone className="w-3.5 h-3.5 text-slate-400" />
                    {selected.whatsapp}
                  </span>
                </DetailRow>
                <DetailRow label="المحافظة">
                  <span className="flex items-center gap-1.5">
                    <MapPin className="w-3.5 h-3.5 text-slate-400" />
                    {selected.governorate}
                  </span>
                </DetailRow>
                <DetailRow label="السن">
                  <span className="flex items-center gap-1.5">
                    <Cake className="w-3.5 h-3.5 text-slate-400" />
                    {selected.age} سنة
                  </span>
                </DetailRow>
              </DetailGroup>

              <DetailGroup title="بيانات التدريس" dotClass="bg-indigo-600">
                <DetailRow label="سنوات الخبرة">
                  <span className="flex items-center gap-1.5">
                    <Briefcase className="w-3.5 h-3.5 text-slate-400" />
                    {selected.years_of_experience} سنة
                  </span>
                </DetailRow>
                <DetailRow label="المادة">
                  <span className="flex items-center gap-1.5">
                    <GraduationCap className="w-3.5 h-3.5 text-slate-400" />
                    {selected.subject}
                  </span>
                </DetailRow>
                <DetailRow label="المرحلة الدراسية">
                  <Badge tone="neutral">{selected.section_name}</Badge>
                </DetailRow>
                <DetailRow label="الصفوف التي يستطيع تدريسها">
                  <div className="flex flex-wrap gap-1.5">
                    {selected.grades.map((grade) => (
                      <span
                        key={grade}
                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-indigo-50 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-300 text-xs font-bold border border-indigo-200/70 dark:border-indigo-500/25"
                      >
                        <Layers className="w-3 h-3" />
                        {grade}
                      </span>
                    ))}
                  </div>
                </DetailRow>
              </DetailGroup>
            </div>

            <DetailGroup title="روابط التواصل" dotClass="bg-emerald-600">
              <div className="space-y-2">
                {selected.social_links.map((link, idx) => (
                  <a
                    key={idx}
                    href={link.startsWith('http') ? link : `https://${link}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-2 text-sm font-bold text-blue-600 dark:text-blue-400 hover:underline break-all"
                    dir="ltr"
                  >
                    <Link2 className="w-3.5 h-3.5 shrink-0" />
                    <span className="text-right flex-1">{link}</span>
                    <ExternalLink className="w-3.5 h-3.5 shrink-0" />
                  </a>
                ))}
              </div>
            </DetailGroup>

            <div className="flex items-center gap-2 text-xs font-medium text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-800 p-3 rounded-xl">
              <Calendar className="w-3.5 h-3.5 shrink-0" />
              تاريخ التقديم: {formatDate(selected.created_at)}
            </div>
          </div>
        )}
      </Dialog>
    </div>
  );
}
