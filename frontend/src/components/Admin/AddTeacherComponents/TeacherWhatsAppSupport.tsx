import { MessageCircle, Plus, Trash2 } from 'lucide-react';
import { Card, CardHeader, FormField, Input, Button, EmptyState } from '../../UI';

interface TeacherWhatsAppSupportProps {
  newWhatsappInput: string;
  setNewWhatsappInput: (val: string) => void;
  whatsappNumbers: string[];
  handleAddWhatsapp: () => void;
  handleRemoveWhatsapp: (idx: number) => void;
}

/**
 * Visual-only migration. Same five props, same `handleAddWhatsapp` /
 * `handleRemoveWhatsapp(idx)` call sites, same `whatsappNumbers.length === 0`
 * branch. Two dead Tailwind classes were fixed on the way:
 * `border-slate-200` and `dark:text-slate-300` are not real Tailwind shades and
 * emitted no CSS at all.
 */
export default function TeacherWhatsAppSupport({
  newWhatsappInput,
  setNewWhatsappInput,
  whatsappNumbers,
  handleAddWhatsapp,
  handleRemoveWhatsapp
}: TeacherWhatsAppSupportProps) {
  return (
    <Card>
      <CardHeader
        icon={MessageCircle}
        title="أرقام الدعم الفني الخاصة بالمعلم للمنصة"
        description="يمكنك إضافة وإدارة خيارات اتصال واتساب مخصصة لدعم الطلاب بكفاءة"
      />

      <div className="grid grid-cols-1 md:grid-cols-2 gap-5 items-start">
        {/* Entry input */}
        <div className="space-y-3">
          <FormField
            label="إضافة رقم واتساب مخصص"
            helperText='أدخل تسمية متبوعة بالرقم (مثال: "مسؤول المادة: 01012345678"). تتيح لك هذه المنظومة تخصيص عدة مشرفين ومساعدين يدعمون الطلاب مع المدرس.'
          >
            <div className="flex gap-2">
              <Input
                type="text"
                value={newWhatsappInput}
                onChange={(e) => setNewWhatsappInput(e.target.value)}
                placeholder="مثال: مسؤول المادة - 01012345678"
              />
              <Button type="button" variant="success" icon={Plus} onClick={handleAddWhatsapp} className="shrink-0">
                إدراج
              </Button>
            </div>
          </FormField>
        </div>

        {/* Display grid */}
        <div className="space-y-2 bg-slate-50/60 dark:bg-slate-950/40 p-4 border border-dashed border-slate-200 dark:border-slate-800 rounded-2xl min-h-[120px]">
          <span className="text-[11px] font-black text-slate-500 dark:text-slate-400 block mb-2">
            قائمة أرقام الدعم الملحقة الحالية ({whatsappNumbers.length}):
          </span>

          {whatsappNumbers.length === 0 ? (
            <EmptyState
              size="sm"
              icon={MessageCircle}
              title="لا يوجد أرقام ملحقة بالمعلم بعد"
              description="سيكون تواصله مباشراً فقط."
            />
          ) : (
            <ul className="space-y-2">
              {whatsappNumbers.map((num, idx) => (
                <li
                  key={idx}
                  className="flex items-center justify-between gap-2 text-xs bg-white dark:bg-slate-900 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm"
                >
                  <span className="font-black text-slate-700 dark:text-slate-300">{num}</span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    iconOnly
                    onClick={() => handleRemoveWhatsapp(idx)}
                    aria-label="حذف الرقم"
                    className="text-rose-500 hover:text-rose-600"
                  >
                    <Trash2 className="w-4 h-4" />
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </Card>
  );
}
