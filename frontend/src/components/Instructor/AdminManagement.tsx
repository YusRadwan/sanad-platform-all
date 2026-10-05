import { useEffect, useMemo, useState } from 'react';
import { motion } from 'motion/react';
import { UserCog, Plus, Search, Pencil, Trash2, Users, Eye, EyeOff, ShieldCheck, AlertTriangle } from 'lucide-react';
import { employeeService, EmployeeRow, EmployeePayload } from '../../services/employeeService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { INSTRUCTOR_EMPLOYEE_PERMISSIONS } from '../../constants/employeePermissions';
import {
  PageHeader,
  Card,
  Button,
  Input,
  FormField,
  Checkbox,
  Skeleton,
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
  TableEmptyState,
  Badge,
  Dialog,
  ConfirmDialog,
} from '../UI';

/**
 * "إدارة الأدمنز" (decision 10.37) — the teacher's own Employee/Amin
 * sub-accounts. Table-based, matching ManageTeachers.tsx's visual language
 * rather than a card grid — "لا أريد UI معقد" from the request.
 *
 * ALL enforcement is server-side (EmployeeController + the
 * `employee.permission:*` middleware + App\Support\EmployeePrivilegeCeiling)
 * — this page's own conditionals (hiding the "أمين" option from a limited
 * employee's own form, say) are convenience only, exactly like every other
 * "UI convenience, not the security boundary" field this session has added
 * (`centerEnabled`, `employeePermissions`).
 */

type AccountType = 'amin' | 'employee';

interface FormState {
  name: string;
  phone: string;
  password: string;
  accountType: AccountType;
  permissions: string[];
}

const EMPTY_FORM: FormState = {
  name: '',
  phone: '',
  password: '',
  accountType: 'employee',
  permissions: [],
};

export default function AdminManagement() {
  const { notify } = useCenteredNotification();

  const [rows, setRows] = useState<EmployeeRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');

  const load = async () => {
    try {
      setLoading(true);
      const data = await employeeService.list();
      setRows(data);
    } catch (err) {
      logger.error('AdminManagement: failed to load employees', err as Error);
      notify('تعذر تحميل قائمة الموظفين', 'error');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const filtered = useMemo(() => {
    const term = q.trim();
    if (!term) return rows;
    return rows.filter((r) => (r.name || '').includes(term) || (r.phone || '').includes(term));
  }, [rows, q]);

  // ── Add / Edit dialog ────────────────────────────────────────────────
  const [editing, setEditing] = useState<EmployeeRow | null>(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [saving, setSaving] = useState(false);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setDialogOpen(true);
  };

  const openEdit = (row: EmployeeRow) => {
    setEditing(row);
    setForm({
      name: row.name || '',
      phone: row.phone || '',
      password: '',
      accountType: row.accountType,
      permissions: row.permissions,
    });
    setDialogOpen(true);
  };

  const togglePermission = (key: string) => {
    setForm((prev) => ({
      ...prev,
      permissions: prev.permissions.includes(key)
        ? prev.permissions.filter((p) => p !== key)
        : [...prev.permissions, key],
    }));
  };

  const submit = async () => {
    if (!form.name.trim() || !form.phone.trim() || (!editing && !form.password.trim())) {
      notify('يرجى تعبئة كافة الحقول المطلوبة', 'error');
      return;
    }

    const payload: EmployeePayload = {
      name: form.name.trim(),
      phone: form.phone.trim(),
      accountType: form.accountType,
      permissions: form.accountType === 'employee' ? form.permissions : [],
    };
    if (form.password.trim()) {
      payload.password = form.password.trim();
    }

    setSaving(true);
    try {
      if (editing) {
        await employeeService.update(editing.id, payload);
        notify('تم تحديث بيانات الموظف بنجاح', 'success');
      } else {
        await employeeService.create(payload);
        notify('تم إضافة الموظف بنجاح', 'success');
      }
      setDialogOpen(false);
      await load();
    } catch (err: any) {
      notify(err.response?.data?.message || 'تعذر حفظ بيانات الموظف', 'error');
    } finally {
      setSaving(false);
    }
  };

  // ── Delete ────────────────────────────────────────────────────────────
  const [deleteTarget, setDeleteTarget] = useState<EmployeeRow | null>(null);
  const [deleting, setDeleting] = useState(false);

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await employeeService.remove(deleteTarget.id);
      notify('تم حذف الموظف بنجاح', 'success');
      setDeleteTarget(null);
      await load();
    } catch (err: any) {
      notify(err.response?.data?.message || 'تعذر حذف الموظف', 'error');
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div dir="rtl" className="relative space-y-6 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-indigo-600/10 dark:bg-indigo-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-blue-500/8 dark:bg-blue-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />

      <PageHeader
        icon={UserCog}
        title="إدارة الأدمنز"
        description="إنشاء وإدارة الموظفين التابعين لك وتحديد صلاحياتهم"
        actions={
          <Button icon={Plus} onClick={openCreate}>
            إضافة موظف
          </Button>
        }
      />

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Card radius="panel" className="flex items-center gap-4">
          <div className="w-11 h-11 rounded-2xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center shrink-0">
            <Users className="w-5 h-5" />
          </div>
          <div>
            <div className="text-xs font-bold text-slate-400">عدد الموظفين</div>
            <div className="text-xl font-black text-slate-800 dark:text-slate-100">{rows.length}</div>
          </div>
        </Card>

        <Card radius="panel">
          <FormField label="البحث عن موظف">
            {({ id }) => (
              <Input id={id} value={q} onChange={(e) => setQ(e.target.value)} placeholder="ابحث بالاسم أو رقم الهاتف..." icon={Search} />
            )}
          </FormField>
        </Card>
      </div>

      <Card radius="panel" padding="none" className="overflow-hidden">
        <Table zebra>
          <TableHeader>
            <tr>
              <TableHead>الموظف</TableHead>
              <TableHead>رقم الهاتف</TableHead>
              <TableHead align="center">نوع الحساب</TableHead>
              <TableHead>الصلاحيات</TableHead>
              <TableHead align="center">الحالة</TableHead>
              <TableHead align="center">الإجراءات</TableHead>
            </tr>
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: 4 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={6}><Skeleton variant="text" width="100%" /></TableCell>
                </TableRow>
              ))
            ) : filtered.length === 0 ? (
              <TableEmptyState colSpan={6} icon={UserCog} title="لا يوجد موظفون" description="اضغط (+ إضافة موظف) لإنشاء أول حساب" />
            ) : (
              filtered.map((row) => (
                <TableRow key={row.id}>
                  <TableCell emphasis>{row.name || '—'}</TableCell>
                  <TableCell className="font-mono" dir="ltr">{row.phone || '—'}</TableCell>
                  <TableCell align="center">
                    {row.accountType === 'amin' ? (
                      <Badge tone="brand" icon={ShieldCheck}>أمين</Badge>
                    ) : (
                      <Badge tone="neutral">موظف</Badge>
                    )}
                  </TableCell>
                  <TableCell>
                    {row.accountType === 'amin' ? (
                      <span className="text-xs font-bold text-slate-500 dark:text-slate-400">جميع الصلاحيات</span>
                    ) : (
                      <span className="text-xs font-bold text-slate-500 dark:text-slate-400">{row.permissions.length} صفحات</span>
                    )}
                  </TableCell>
                  <TableCell align="center">
                    {row.isActive ? (
                      <Badge tone="success" icon={Eye}>نشط</Badge>
                    ) : (
                      <Badge tone="danger" icon={EyeOff}>معطّل</Badge>
                    )}
                  </TableCell>
                  <TableCell align="center">
                    <div className="flex items-center justify-center gap-2">
                      <Button variant="ghost" size="sm" icon={Pencil} onClick={() => openEdit(row)}>تعديل</Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        icon={Trash2}
                        className="text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10"
                        onClick={() => setDeleteTarget(row)}
                      >
                        حذف
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </Card>

      <Dialog
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        icon={UserCog}
        title={editing ? 'تعديل بيانات الموظف' : 'إضافة موظف جديد'}
        size="lg"
        footer={
          <div className="flex items-center justify-end gap-3">
            <Button variant="outline" onClick={() => setDialogOpen(false)}>إلغاء</Button>
            <Button isLoading={saving} onClick={submit}>حفظ</Button>
          </div>
        }
      >
        <motion.div initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FormField label="اسم الموظف" required>
              {({ id }) => (
                <Input id={id} value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))} placeholder="اسم الموظف الكامل" />
              )}
            </FormField>

            <FormField label="رقم الهاتف" required helperText="يُستخدَم كاسم المستخدم لتسجيل الدخول">
              {({ id }) => (
                <Input id={id} value={form.phone} onChange={(e) => setForm((p) => ({ ...p, phone: e.target.value }))} placeholder="01xxxxxxxxx" className="font-mono" dir="ltr" />
              )}
            </FormField>
          </div>

          <FormField label="كلمة المرور" required={!editing} helperText={editing ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية' : undefined}>
            {({ id }) => (
              <Input id={id} type="password" value={form.password} onChange={(e) => setForm((p) => ({ ...p, password: e.target.value }))} placeholder="كلمة مرور آمنة" dir="ltr" />
            )}
          </FormField>

          <FormField label="نوع الحساب" required>
            {() => (
              <div className="grid grid-cols-2 gap-3">
                {(['employee', 'amin'] as AccountType[]).map((type) => (
                  <button
                    key={type}
                    type="button"
                    onClick={() => setForm((p) => ({ ...p, accountType: type }))}
                    className={`px-4 py-3 rounded-2xl border-2 text-sm font-black transition-all cursor-pointer ${
                      form.accountType === type
                        ? 'border-indigo-500 bg-indigo-50/70 dark:bg-indigo-500/10 text-indigo-700 dark:text-indigo-400 shadow-sm'
                        : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 hover:border-slate-300 dark:hover:border-slate-700'
                    }`}
                  >
                    {type === 'amin' ? 'أمين' : 'موظف'}
                  </button>
                ))}
              </div>
            )}
          </FormField>

          {form.accountType === 'amin' ? (
            <div className="flex items-center gap-3 p-4 rounded-2xl bg-indigo-50/60 dark:bg-indigo-500/5 border border-indigo-100 dark:border-indigo-500/20">
              <ShieldCheck className="w-5 h-5 text-indigo-500 shrink-0" />
              <p className="text-xs font-bold text-indigo-700 dark:text-indigo-400">يمتلك هذا الحساب جميع صلاحيات المدرّس، ضمن نطاقك فقط — بلا وصول لقسم إدارة المنصة.</p>
            </div>
          ) : (
            <FormField label="الصلاحيات">
              {() => (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 max-h-64 overflow-y-auto">
                  {INSTRUCTOR_EMPLOYEE_PERMISSIONS.filter((p) => p.sidebarPath !== null).map((perm) => (
                    <Checkbox
                      key={perm.key}
                      label={perm.label}
                      checked={form.permissions.includes(perm.key)}
                      onChange={() => togglePermission(perm.key)}
                    />
                  ))}
                </div>
              )}
            </FormField>
          )}
        </motion.div>
      </Dialog>

      <ConfirmDialog
        open={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        onConfirm={confirmDelete}
        icon={AlertTriangle}
        tone="danger"
        title="هل أنت متأكد من حذف الموظف؟"
        message={`سيتم تعطيل حساب "${deleteTarget?.name || ''}" ولن يتمكن من تسجيل الدخول أو الوصول لأي بيانات بعد ذلك.`}
        confirmLabel="تأكيد الحذف"
        isLoading={deleting}
      />
    </div>
  );
}
