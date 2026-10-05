import { Eye, EyeOff, UserPlus } from 'lucide-react';
import { Card, CardHeader, FormField, Input } from '../../UI';

interface TeacherGeneralInfoProps {
  teacherName: string;
  setTeacherName: (val: string) => void;
  teacherPhone: string;
  setTeacherPhone: (val: string) => void;
  teacherUsername: string;
  setTeacherUsername: (val: string) => void;
  teacherPassword: string;
  setTeacherPassword: (val: string) => void;
  showPassword: boolean;
  setShowPassword: (val: boolean) => void;
  isEditMode: boolean;
  centerEnabled: boolean;
  setCenterEnabled: (val: boolean) => void;
}

/**
 * Same self-contained switch markup as PlatformSettings.tsx's
 * `SettingsSwitch` (not duplicated as a shared primitive — that file's own
 * comment explains why this is a *switch*, not the design system's
 * `Checkbox`). Reproduced here rather than imported since it's a private,
 * unexported helper over there.
 */
function ToggleSwitch({
  checked,
  onChange,
  label,
}: {
  checked: boolean;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  label: string;
}) {
  return (
    <label className="relative inline-flex items-center cursor-pointer shrink-0">
      <input
        type="checkbox"
        className="sr-only peer"
        checked={checked}
        onChange={onChange}
        aria-label={label}
      />
      <div className="w-11 h-6 bg-slate-200 peer-focus-visible:outline-none peer-focus-visible:ring-4 peer-focus-visible:ring-blue-500/30 rounded-full peer dark:bg-slate-700 peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:right-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-emerald-600 transition-colors" />
    </label>
  );
}

/**
 * Visual-only migration to the SANAD design system. The prop contract is
 * unchanged (same eleven controlled props, same setters called with the same
 * values), and every `required` flag — including the `required={!isEditMode}`
 * on the password field — is preserved verbatim.
 *
 * The local hand-rolled `UserPlusIcon` SVG was replaced by lucide's `UserPlus`,
 * which is the same glyph and is already a dependency.
 */
export default function TeacherGeneralInfo({
  teacherName,
  setTeacherName,
  teacherPhone,
  setTeacherPhone,
  teacherUsername,
  setTeacherUsername,
  teacherPassword,
  setTeacherPassword,
  showPassword,
  setShowPassword,
  isEditMode,
  centerEnabled,
  setCenterEnabled,
}: TeacherGeneralInfoProps) {
  return (
    <Card>
      <CardHeader
        icon={UserPlus}
        title="البيانات الأساسية لحساب المعلم"
        description="أدخل البيانات الشخصية التي سيسجل بها المعلم الدخول للمنصة"
      />

      <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
        <FormField label="الاسم الثلاثي أو الشهرة" required>
          <Input
            type="text"
            required
            value={teacherName}
            onChange={(e) => setTeacherName(e.target.value)}
            placeholder="أدخل اسم المعلم كاملاً"
          />
        </FormField>

        <FormField label="رقم الهاتف الفعال" required>
          <Input
            type="tel"
            required
            value={teacherPhone}
            onChange={(e) => setTeacherPhone(e.target.value)}
            placeholder="مثال: 01012345678"
            className="text-left"
            dir="ltr"
          />
        </FormField>

        <FormField label="اسم المستخدم (بالأحرف الإنجليزية للمطابقة)" required>
          <Input
            type="text"
            required
            value={teacherUsername}
            onChange={(e) => setTeacherUsername(e.target.value)}
            placeholder="أدخل اسم مستخدم فريد (مثال: mohammad_teacher)"
            className="text-left"
            dir="ltr"
          />
        </FormField>

        <FormField
          label="كلمة مرور الحساب الشخصي"
          required={!isEditMode}
          helperText={isEditMode ? 'اتركه فارغاً للإبقاء على كلمة المرور الحالية' : undefined}
        >
          <div className="relative">
            <Input
              type={showPassword ? 'text' : 'password'}
              required={!isEditMode}
              value={teacherPassword}
              onChange={(e) => setTeacherPassword(e.target.value)}
              placeholder={isEditMode ? 'اتركه فارغاً للإبقاء على كلمة المرور الحالية' : 'تحديد كلمة مرور آمنة للمعلم'}
              className="text-left pe-11"
              dir="ltr"
            />
            <button
              type="button"
              onClick={() => setShowPassword(!showPassword)}
              aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
              className="absolute inset-y-0 end-3 flex items-center justify-center text-slate-400 hover:text-slate-700 dark:hover:text-slate-300 cursor-pointer bg-transparent border-0"
            >
              {showPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
            </button>
          </div>
        </FormField>
      </div>

      <div className="flex items-center justify-between gap-4 p-4 mt-5 rounded-2xl bg-emerald-50/60 dark:bg-emerald-500/5 border border-emerald-100 dark:border-emerald-500/20">
        <div className="min-w-0">
          <h4 className="text-sm sm:text-base font-bold text-emerald-900 dark:text-emerald-300 leading-tight">
            تفعيل نظام السنتر
          </h4>
          <p className="text-xs font-medium text-emerald-700/80 dark:text-emerald-400/80 mt-1 leading-relaxed">
            عند التفعيل يمكن لهذا المدرس استخدام نظام السنتر (المجموعات،
            الحضور، التقارير) وتظهر صفحاته في الشريط الجانبي الخاص به. عند
            الإغلاق لا يُحذف أي شيء من بيانات السنتر الخاصة به — تظهر كاملة
            بلا مشاكل فور إعادة التفعيل.
          </p>
        </div>
        <ToggleSwitch checked={centerEnabled} onChange={(e) => setCenterEnabled(e.target.checked)} label="تفعيل نظام السنتر" />
      </div>
    </Card>
  );
}
