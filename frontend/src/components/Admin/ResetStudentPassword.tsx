import { useState } from 'react';
import { motion } from 'motion/react';
import {
  Search, KeyRound, Phone, XCircle,
  BadgeCheck, Ban, Hash, Eye, EyeOff, Wand2, ArrowRight,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  PageHeader, Card, CardHeader, Button, Input, FormField, Badge, EmptyState,
} from '../UI';

/**
 * صفحة إعادة تعيين كلمة سر الطالب (admin only).
 *
 * A narrow, single-purpose tool: find a student by phone number alone (no
 * code, no name — matching the request) and set a new password for their
 * account. Structurally mirrors StudentCodeDetails.tsx (search card, local
 * toast, identity banner) so the admin section stays visually consistent,
 * but the lookup key is `phone` and the payload is `updateStudent(id, {
 * password })` (PUT /admin/students/{id}) instead of a full profile fetch.
 */

interface StudentMatch {
  id: string;
  name: string | null;
  username: string | null;
  phone: string | null;
  studentCode: string | null;
  status: string;
}

/** 8 mixed letters+digits — comfortably above the 8-char floor this page enforces. */
function generatePassword(): string {
  const letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
  const digits = '23456789';
  const all = letters + digits;
  let pw = '';
  for (let i = 0; i < 8; i++) pw += all[Math.floor(Math.random() * all.length)];
  return pw;
}

export default function ResetStudentPassword() {
  const [phone, setPhone] = useState('');
  const [searching, setSearching] = useState(false);
  const [matches, setMatches] = useState<StudentMatch[]>([]);
  const [searched, setSearched] = useState(false);
  const [selected, setSelected] = useState<StudentMatch | null>(null);

  const [newPassword, setNewPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [saving, setSaving] = useState(false);

  // Every user-facing result on this page — including the search lookup —
  // now goes through the shared centered popup.
  const { notify } = useCenteredNotification();

  const isPhoneValid = phone.trim().length >= 5;
  // Same policy the student themselves would meet at signup (StudentPasswordPolicy
  // on the backend, min 6) — enforced here at a slightly friendlier floor of 8, with
  // no letter+digit composition requirement, matching the student registration UX.
  const isPasswordValid = newPassword.length >= 8;

  const handleSearch = async () => {
    const value = phone.trim();
    if (!isPhoneValid) {
      notify('من فضلك أدخل رقم هاتف الطالب أولاً', 'error');
      return;
    }

    setSearching(true);
    setMatches([]);
    setSelected(null);
    setSearched(false);
    try {
      const result = await adminService.getStudents({ phone: value, per_page: 20 });
      setMatches(result.items as StudentMatch[]);
    } catch {
      notify('تعذر الاتصال بالخادم، برجاء المحاولة مرة أخرى', 'error');
    } finally {
      setSearching(false);
      setSearched(true);
    }
  };

  const handleReset = async () => {
    if (!selected) return;
    if (!isPasswordValid) {
      notify('كلمة السر يجب ألا تقل عن 8 أحرف', 'error');
      return;
    }

    setSaving(true);
    try {
      await adminService.updateStudent(selected.id, { password: newPassword });
      notify(`تم إعادة تعيين كلمة سر الطالب "${selected.name || selected.username}" بنجاح`, 'success');
      setNewPassword('');
      setSelected(null);
      setMatches([]);
      setPhone('');
      setSearched(false);
    } catch (err: any) {
      notify(err?.response?.data?.message || 'حدث خطأ أثناء إعادة تعيين كلمة السر', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">

      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-rose-500/8 dark:bg-rose-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-indigo-500/6 dark:bg-indigo-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <PageHeader
        icon={KeyRound}
        title="إعادة تعيين كلمة سر الطالب"
        breadcrumbs={[{ label: 'منظومة تنظّيم وضبط المنصة العُليا' }, { label: 'إعادة تعيين كلمة سر الطالب' }]}
        description="ابحث عن الطالب برقم الهاتف فقط، ثم عيّن له كلمة سر جديدة"
        className="mb-0"
      />

      {/* SEARCH */}
      <motion.div initial={{ opacity: 0, y: 16 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}>
        <Card className="space-y-5">
          <CardHeader title="البحث عن طالب برقم الهاتف" icon={Search} />

          <div className="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
            <div className="md:col-span-9">
              <FormField label={<span className="inline-flex items-center gap-1.5"><Phone className="w-3 h-3" /> رقم هاتف الطالب:</span>}>
                {({ id }) => (
                  <Input
                    id={id}
                    size="lg"
                    type="tel"
                    inputMode="numeric"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value.replace(/[^0-9]/g, ''))}
                    onKeyDown={(e) => { if (e.key === 'Enter' && isPhoneValid) void handleSearch(); }}
                    placeholder="مثال: 01012345678"
                    className="text-lg font-black font-mono tracking-wide placeholder:text-base placeholder:tracking-normal placeholder:font-bold"
                  />
                )}
              </FormField>
            </div>

            <div className="md:col-span-3 md:mt-6">
              <Button
                size="lg"
                fullWidth
                icon={Search}
                isLoading={searching}
                onClick={() => void handleSearch()}
                disabled={!isPhoneValid || searching}
                className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
              >
                بحث عن الطالب
              </Button>
            </div>
          </div>
        </Card>
      </motion.div>

      {/* NO RESULTS */}
      {searched && !searching && matches.length === 0 && (
        <Card className="border-amber-200 dark:border-amber-900/50">
          <EmptyState
            icon={XCircle}
            title="لم يتم العثور على طالب بهذا الرقم"
            description="تأكد من كتابة رقم الهاتف بشكل صحيح كما هو مسجّل في بيانات الطالب."
          />
        </Card>
      )}

      {/* MATCH LIST — shown until one is picked */}
      {matches.length > 0 && !selected && (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }}>
          <Card padding="none" className="overflow-hidden">
            <CardHeader title={`نتائج البحث (${matches.length})`} icon={Hash} className="p-5 pb-4 mb-0" />
            <div className="divide-y divide-slate-100 dark:divide-slate-800/70">
              {matches.map((s) => (
                <button
                  type="button"
                  key={s.id}
                  onClick={() => setSelected(s)}
                  className="w-full flex items-center justify-between gap-4 p-4 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors text-right"
                >
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="w-11 h-11 rounded-xl bg-blue-600 text-white flex items-center justify-center font-black text-lg shrink-0">
                      {(s.name ?? '؟').trim().charAt(0)}
                    </div>
                    <div className="min-w-0">
                      <strong className="text-sm font-black text-slate-900 dark:text-white block truncate">{s.name ?? s.username ?? '—'}</strong>
                      <span className="text-xs font-bold font-mono text-slate-500 dark:text-slate-400">{s.phone ?? '—'} · {s.studentCode ?? '—'}</span>
                    </div>
                  </div>
                  <Badge tone={s.status === 'active' ? 'success' : 'danger'} icon={s.status === 'active' ? BadgeCheck : Ban}>
                    {s.status === 'active' ? 'نشط' : 'موقوف'}
                  </Badge>
                </button>
              ))}
            </div>
          </Card>
        </motion.div>
      )}

      {/* RESET FORM — the selected student */}
      {selected && (
        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} className="space-y-6">
          <Card className="flex flex-col md:flex-row items-center justify-between gap-5">
            <div className="flex items-center gap-4">
              <div className="w-14 h-14 rounded-2xl bg-blue-600 text-white flex items-center justify-center font-black text-xl shrink-0">
                {(selected.name ?? '؟').trim().charAt(0)}
              </div>
              <div className="space-y-1 text-right">
                <strong className="text-base font-black text-slate-900 dark:text-white block">{selected.name ?? selected.username ?? '—'}</strong>
                <span className="text-xs font-bold font-mono text-slate-500 dark:text-slate-400">{selected.phone ?? '—'} · {selected.studentCode ?? '—'}</span>
              </div>
            </div>
            <Button variant="ghost" size="sm" icon={ArrowRight} onClick={() => { setSelected(null); setNewPassword(''); }}>
              اختيار طالب آخر
            </Button>
          </Card>

          <Card className="space-y-5">
            <CardHeader title="كلمة السر الجديدة" icon={KeyRound} />

            <FormField
              label="كلمة السر الجديدة (8 أحرف على الأقل، أرقام أو حروف أو الاثنان معاً):"
              error={newPassword.length > 0 && !isPasswordValid
                ? 'كلمة السر يجب ألا تقل عن 8 أحرف'
                : null}
            >
              {({ id, hasError }) => (
                <div className="relative">
                  <Input
                    id={id}
                    size="lg"
                    hasError={hasError}
                    type={showPassword ? 'text' : 'password'}
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                    placeholder="اكتب كلمة سر جديدة أو ولّدها تلقائياً"
                    className="pl-24 font-mono"
                  />
                  <div className="absolute inset-y-0 left-2 flex items-center gap-1">
                    <button
                      type="button"
                      title="توليد كلمة سر عشوائية"
                      onClick={() => { setNewPassword(generatePassword()); setShowPassword(true); }}
                      className="p-2 rounded-lg text-slate-400 hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-500/10 dark:hover:text-blue-400 transition-colors cursor-pointer"
                    >
                      <Wand2 className="w-4 h-4" />
                    </button>
                    <button
                      type="button"
                      title={showPassword ? 'إخفاء كلمة السر' : 'إظهار كلمة السر'}
                      onClick={() => setShowPassword((v) => !v)}
                      className="p-2 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors cursor-pointer"
                    >
                      {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                    </button>
                  </div>
                </div>
              )}
            </FormField>

            <Button
              size="lg"
              fullWidth
              icon={KeyRound}
              isLoading={saving}
              onClick={() => void handleReset()}
              disabled={!isPasswordValid || saving}
              className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
            >
              حفظ كلمة السر الجديدة
            </Button>
          </Card>
        </motion.div>
      )}
    </div>
  );
}
