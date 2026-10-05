import { useState, useEffect } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { motion } from 'motion/react';
import {
  Users, Trash2, Edit3, AlertTriangle, Power, Lock, KeyRound,
  LockKeyhole, Unlock,
} from 'lucide-react';
import { adminService } from '../../services/adminService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { AVATAR_PLACEHOLDER } from '../../utils/imagePlaceholders';
import {
  PageHeader, Card, Button, Input, FormField, Badge, EmptyState, Skeleton, Dialog,
  ConfirmDialog,
} from '../UI';

/**
 * Design-system pass — presentation only. The single `state` object,
 * `updateState`, `showToast`, `fetchTeachers`, `handleToggleStatus`,
 * `handleStartEdit` (and the exact `navigate('/admin/add-teacher', { state: … })`
 * payload it builds), `handleRequestDelete` and `handleConfirmDelete` are all
 * byte-identical, as are both `useEffect` dependency arrays (`[]`,
 * `[location.state]`). The security dialog still writes the same three state
 * keys and still calls the same `handleConfirmDelete`; only its shell is now
 * the shared Dialog primitive.
 *
 * A large number of NON-EXISTENT palette shades were used here and emitted no
 * CSS at all (worst in dark mode): `slate-205`, `slate-150`, `slate-250`,
 * `slate-550`, `slate-750`, `slate-805`, `slate-850`, `slate-350`,
 * `blue-650`, `blue-455`, `blue-805`, `rose-605`, `rose-550`, `rose-805`,
 * `emerald-805`, `emerald-850`, `emerald-955`, `amber-850`, `amber-955`.
 * All replaced with real Tailwind shades.
 */

export default function ManageTeachers() {
  const navigate = useNavigate();
  const location = useLocation();

  const [state, setState] = useState({
    teachers: [] as any[],
    loading: true,
    deleteTargetId: null as any,
    adminPassword: '',
    passwordError: '',
    showPasswordInput: false,
    // The teacher awaiting confirmation of a LOGIN BLOCK (never of an unblock,
    // which is the safe direction and applies immediately). Holds the row, not
    // just the id, so the dialog can name the teacher it is about.
    loginBlockTarget: null as any,
    loginBlockPending: false,
  });

  const updateState = (updates: Partial<typeof state>) => {
    setState(prev => ({ ...prev, ...updates }));
  };

  const { notify } = useCenteredNotification();
  const showToast = (text: string, type: string = 'success') => {
    notify(text, type === 'danger' ? 'error' : 'success');
  };

  const fetchTeachers = async () => {
    try {
      updateState({ loading: true });
      const data = await adminService.getAllTeachers();
      updateState({ teachers: data });
    } catch (err) {
      console.error('Failed to fetch teachers:', err);
      showToast('فشل في تحميل قائمة المدرسين', 'danger');
    } finally {
      updateState({ loading: false });
    }
  };

  useEffect(() => {
    fetchTeachers();
  }, []);

  useEffect(() => {
    if (location.state?.toast) {
      showToast(location.state.toast.text, location.state.toast.type);
      window.history.replaceState({}, document.title);
    }
  }, [location.state]);

  const handleToggleStatus = async (id: any) => {
    const matchedTeacher = state.teachers.find(t => t.user_id === id);
    if (!matchedTeacher) return;
    
    const newStatus = !matchedTeacher.is_active;

    try {
      await adminService.updateTeacher(id, { isActive: newStatus });
      updateState({
        teachers: state.teachers.map(t => {
          if (t.user_id === id) {
            return { ...t, is_active: newStatus };
          }
          return t;
        })
      });
      
      showToast(
        newStatus 
          ? `تم تفعيل المدرس "${matchedTeacher.full_name}" بنجاح` 
          : `تم إيقاف المدرس "${matchedTeacher.full_name}" مؤقتاً`,
        newStatus ? 'success' : 'info'
      );
    } catch (err) {
      console.error('Failed to toggle status:', err);
      showToast('فشل في تحديث حالة المدرس', 'danger');
    }
  };

  /**
   * ┌──────────────────────────────────────────────────────────────────────┐
   * │ THE SECOND SWITCH — `loginBlocked`. NOT A VARIANT OF إيقاف ABOVE.    │
   * │                                                                      │
   * │ handleToggleStatus writes users.is_active: suspension. That removes  │
   * │ the teacher from the public directory AND takes their published      │
   * │ courses off the catalogue AND locks them out — three consequences.   │
   * │                                                                      │
   * │ This one writes teachers.login_blocked, which has exactly ONE        │
   * │ consequence: the teacher cannot sign in. Their profile page, their   │
   * │ card in the directory and every course they published stay exactly   │
   * │ as visible to students as they were a second earlier.                │
   * │                                                                      │
   * │ The two are deliberately different colours and different icons, and  │
   * │ the block direction is confirmed in a dialog that SPELLS OUT the     │
   * │ difference, because the failure mode is an admin reaching for the    │
   * │ wrong one and taking a whole catalogue offline over a password       │
   * │ dispute.                                                             │
   * └──────────────────────────────────────────────────────────────────────┘
   */
  const applyLoginBlock = async (teacher: any, newValue: boolean) => {
    const id = teacher.user_id;

    try {
      updateState({ loginBlockPending: true });
      await adminService.updateTeacher(id, { loginBlocked: newValue });

      updateState({
        teachers: state.teachers.map(t => (
          t.user_id === id ? { ...t, loginBlocked: newValue } : t
        )),
        loginBlockTarget: null,
        loginBlockPending: false,
      });

      showToast(
        newValue
          ? `تم منع المدرس "${teacher.full_name}" من تسجيل الدخول — بياناته وكورساته ما زالت ظاهرة على الموقع`
          : `تم السماح للمدرس "${teacher.full_name}" بتسجيل الدخول مجدداً`,
        newValue ? 'info' : 'success'
      );
    } catch (err) {
      console.error('Failed to toggle login block:', err);
      updateState({ loginBlockPending: false, loginBlockTarget: null });
      showToast('فشل في تحديث صلاحية تسجيل الدخول', 'danger');
    }
  };

  /**
   * Blocking asks first; UNBLOCKING does not. Restoring an instructor's access
   * is the recoverable direction and gating it behind a modal only trains
   * admins to click through modals.
   */
  const handleToggleLoginBlock = (teacher: any) => {
    if (teacher.loginBlocked) {
      applyLoginBlock(teacher, false);
      return;
    }

    updateState({ loginBlockTarget: teacher });
  };

  /**
   * Hands the rendered row to AddTeacher.tsx as `location.state.editTeacher`.
   *
   * THE KEYS HERE MUST MATCH WHAT THE ENDPOINT ACTUALLY EMITS. GET
   * /admin/teachers is presented by TeacherService::present(), which emits
   * camelCase `socialLinks` — it never emits `social_links` or
   * `whatsapp_numbers`. adminService.getAllTeachers() back-fills
   * `user_id`/`full_name`/`avatar_url`/`banner_url`, but nothing back-fills
   * those, so reading `teacher.social_links` silently handed AddTeacher.tsx
   * `undefined`. That was not a cosmetic pre-fill gap but DATA LOSS on save:
   * the form then submits `socialLinks: []` (every toggle off) and
   * TeacherRepository::replaceSocialLinks is delete-and-reinsert — so simply
   * opening a teacher for edit and pressing save wiped every social link.
   */
  const handleStartEdit = (teacher: any) => {
    navigate('/admin/add-teacher', { state: { editTeacher: {
      id: teacher.user_id,
      name: teacher.full_name,
      username: teacher.username,
      email: teacher.email,
      phone: teacher.phone,
      bio: teacher.bio,
      avatar_url: teacher.avatar_url,
      banner_url: teacher.banner_url,
      subjects: teacher.subjects,
      grades: teacher.grades,
      socialLinks: teacher.socialLinks ?? teacher.social_links,
      // Not persisted under this request key, hence never returned as-is —
      // see StoreTeacherRequest's docblock (whatsapp_support_numbers is the
      // real column).
      whatsappNumbers: teacher.whatsappNumbers ?? teacher.whatsapp_numbers,
      // TeacherResource emits this camelCase already — "زر تفعيل نظام السنتر".
      centerEnabled: teacher.centerEnabled
    } } });
  };

  const handleRequestDelete = (id: any) => {
    updateState({
      deleteTargetId: id,
      adminPassword: '',
      passwordError: '',
      showPasswordInput: true
    });
  };

  const handleConfirmDelete = async () => {
    if (!state.adminPassword.trim()) {
      updateState({ passwordError: 'يرجى إدخال كلمة مرور الإدارة العامة لتأكيد الحذف!' });
      return;
    }

    if (!state.deleteTargetId) return;

    try {
      updateState({ passwordError: '' });
      // Send deletion request with target ID and confirmation password
      await adminService.deleteTeacher(state.deleteTargetId, state.adminPassword);
      
      // Update local state
      updateState({
        teachers: state.teachers.filter(t => t.user_id !== state.deleteTargetId),
        showPasswordInput: false,
        adminPassword: ''
      });
      showToast(`تم حذف المدرس وجميع بياناته من المنصة بنجاح`, 'success');
    } catch (err: any) {
      console.error('Failed to delete teacher:', err);
      const backendMessage = err.response?.data?.message || err.message || '';
      
      if (backendMessage.includes('مرور') || backendMessage.includes('password') || err.response?.status === 401) {
        updateState({ passwordError: 'كلمة مرور الإدارة العامة غير صحيحة! يرجى إعادة التحقق وكتابة الكلمة الصحيحة.' });
      } else {
        // This displays blockages like active student enrollments
        updateState({ passwordError: backendMessage || 'حدث خطأ أثناء محاولة حذف المدرس. يرجى التأكد من عدم وجود اشتراكات فعالة.' });
      }
    }
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right">

      {/* Decorative background glows */}
      <div className="absolute -top-6 left-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-52 right-1/4 w-[380px] h-[380px] bg-amber-500/8 dark:bg-amber-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 left-1/3 w-[300px] h-[300px] bg-sky-500/6 dark:bg-sky-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* 1. TOP HEADER & META BANNER */}
      <PageHeader
        icon={Users}
        title="إدارة شؤون المدرسين"
        breadcrumbs={[{ label: 'لوحة التحكم الإدارية' }, { label: 'حساب الإدارة العليا' }]}
        className="mb-0"
        actions={
          <div className="text-center bg-slate-50 dark:bg-slate-900 px-5 py-2.5 rounded-xl border border-slate-200/70 dark:border-slate-800">
            <span className="block text-[11px] text-slate-400 font-bold">إجمالي المدرسين</span>
            <span className="text-lg font-black text-blue-600 dark:text-blue-400 font-mono">{state.teachers.length}</span>
          </div>
        }
      />

      {/* 2. MAIN TEACHERS BENTO GRID */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        {state.loading ? (
          Array.from({ length: 8 }).map((_, i) => (
            <Card key={i} padding="none" className="overflow-hidden">
              <Skeleton variant="block" className="h-16 rounded-none" />
              <div className="px-5 -mt-8 relative z-10">
                <Skeleton variant="block" className="w-16 h-16 rounded-2xl border-4 border-white dark:border-slate-900" />
              </div>
              <div className="p-5 pt-3 space-y-3">
                <Skeleton variant="text" width="60%" />
                <Skeleton variant="text" width="90%" />
                <Skeleton variant="block" className="h-9 mt-4" />
              </div>
            </Card>
          ))
        ) : state.teachers.map((teacher: any) => {
          const isActive = teacher.is_active !== false;
          const id = teacher.user_id;
          // Independent of `isActive` above — see handleToggleLoginBlock.
          const isLoginBlocked = teacher.loginBlocked === true;
          return (
            <motion.div
              layout
              id={`teacher_card_${id}`}
              key={id}
              className={isActive ? '' : 'opacity-75 grayscale-[25%]'}
            >
              <Card
                padding="none"
                className="relative h-full overflow-hidden flex flex-col justify-between group transition-shadow duration-300 hover:shadow-md"
              >
              {/* Header Info Area */}
              <div>
                {/* Banner Strip */}
                <div className="h-14 bg-slate-100 dark:bg-slate-800 relative">
                  {teacher.banner_url ? (
                    <img
                      src={teacher.banner_url}
                      alt=""
                      className="w-full h-full object-cover opacity-60 dark:opacity-40"
                      referrerPolicy="no-referrer"
                      // Inline loop, not its own component — no per-row state
                      // to swap to a placeholder, so a broken banner (file no
                      // longer on the server) just hides itself and leaves
                      // the plain slate strip behind it, same as the `: null`
                      // branch above for a teacher with no banner at all.
                      onError={(e) => {
                        e.currentTarget.style.display = 'none';
                      }}
                    />
                  ) : null}

                  {/* Absolute Top Badge */}
                  <div className="absolute top-2 left-2">
                    <Badge dot size="sm" tone={isActive ? 'success' : 'neutral'} className="shadow-sm">
                      {isActive ? 'نشط' : 'موقف'}
                    </Badge>
                  </div>

                  {/* A SECOND, separate state — shown alongside the activity
                      badge, never instead of it: a teacher can be نشط على
                      المنصة (fully visible) AND blocked from signing in. */}
                  {isLoginBlocked && (
                    <div className="absolute top-2 right-2">
                      <Badge size="sm" tone="info" icon={LockKeyhole} className="shadow-sm">
                        ممنوع
                      </Badge>
                    </div>
                  )}
                </div>

                {/* Avatar with offset */}
                <div className="px-5 -mt-8 relative z-10 flex justify-between items-end">
                  <div className="w-16 h-16 rounded-2xl overflow-hidden border-4 border-white dark:border-slate-900 shadow-md bg-slate-100">
                    <img
                      src={teacher.avatar_url || AVATAR_PLACEHOLDER}
                      alt={teacher.full_name}
                      className="w-full h-full object-cover"
                      referrerPolicy="no-referrer"
                      onError={(e) => {
                        e.currentTarget.onerror = null;
                        e.currentTarget.src = AVATAR_PLACEHOLDER;
                      }}
                    />
                  </div>
                </div>

                {/* Content */}
                <div className="p-5 pt-3 space-y-2.5">
                  <h3 className="font-black text-sm text-slate-900 dark:text-white leading-tight group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors truncate">
                    {teacher.full_name}
                  </h3>

                  <p className="text-xs font-normal text-slate-500 dark:text-slate-400 min-h-[28px] line-clamp-2 leading-relaxed">
                    {teacher.bio || 'لا يوجد وصف متاح'}
                  </p>

                  {/* Registered Stats / Meta details */}
                  <div className="pt-2 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-[10px] font-medium text-slate-400 dark:text-slate-500">
                    <span className="truncate">{teacher.username}</span>
                    <span className="truncate" dir="ltr">{teacher.phone}</span>
                  </div>
                </div>
              </div>

              {/* Action Buttons list (3 buttons as strictly requested) */}
              <div className="px-5 pb-5 pt-2 grid grid-cols-3 gap-1.5">
                {/* 1. edit info */}
                <Button
                  size="sm"
                  variant="outline"
                  icon={Edit3}
                  onClick={() => handleStartEdit(teacher)}
                  title="تعديل بيانات المدرس"
                  className="w-full"
                >
                  تعديل
                </Button>

                {/* 2. activate/deactivate */}
                <Button
                  size="sm"
                  variant={isActive ? 'warning' : 'success'}
                  icon={Power}
                  onClick={() => handleToggleStatus(id)}
                  title={isActive ? 'تعطيل المدرس' : 'تفعيل المدرس'}
                  className="w-full"
                >
                  {isActive ? 'إيقاف' : 'تفعيل'}
                </Button>

                {/* 3. delete teacher */}
                <Button
                  size="sm"
                  variant="danger"
                  icon={Trash2}
                  onClick={() => handleRequestDelete(id)}
                  title="حذف المدرس نهائياً"
                  className="w-full"
                >
                  حذف
                </Button>
              </div>

              {/* 4. LOGIN BLOCK — deliberately on its OWN full-width row and
                  not a fourth cell in the grid above, so it never reads as
                  another shade of the إيقاف button beside it. Different icon
                  (قفل, not زر الطاقة) and different colour (رمادي/أزرق, not
                  كهرماني/أخضر) for the same reason. See handleToggleLoginBlock. */}
              <div className="px-5 pb-5 -mt-1.5">
                <Button
                  size="sm"
                  variant={isLoginBlocked ? 'primary' : 'secondary'}
                  icon={isLoginBlocked ? Unlock : LockKeyhole}
                  onClick={() => handleToggleLoginBlock(teacher)}
                  title={
                    isLoginBlocked
                      ? 'إعادة السماح لهذا المدرس بتسجيل الدخول إلى حسابه'
                      : 'منع هذا المدرس من تسجيل الدخول فقط — بياناته وكورساته تبقى ظاهرة على الموقع'
                  }
                  className="w-full"
                >
                  {isLoginBlocked ? 'السماح بتسجيل الدخول' : 'منع تسجيل الدخول'}
                </Button>
              </div>
              </Card>
            </motion.div>
          );
        })}

        {!state.loading && state.teachers.length === 0 && (
          <div className="col-span-full">
            <Card>
              <EmptyState
                icon={Users}
                title="لا يوجد مدرسين مسجلين حالياً"
                description="قم بإضافة مدرس جديد للمنظومة ليظهر هنا."
              />
            </Card>
          </div>
        )}
      </div>
      </motion.div>

      {/* 3. LOGIN-BLOCK CONFIRMATION — the wording is the feature.
             It states the ONE thing that changes and, just as importantly,
             the things that do NOT, so an admin who actually wanted إيقاف
             notices before pressing rather than afterwards. */}
      <ConfirmDialog
        open={state.loginBlockTarget !== null}
        onClose={() => updateState({ loginBlockTarget: null })}
        onConfirm={() => {
          if (state.loginBlockTarget) applyLoginBlock(state.loginBlockTarget, true);
        }}
        isLoading={state.loginBlockPending}
        tone="warning"
        icon={LockKeyhole}
        title="منع هذا المدرّس من تسجيل الدخول؟"
        message={
          state.loginBlockTarget
            ? `لن يتمكن "${state.loginBlockTarget.full_name}" من الدخول إلى حسابه بعد الآن.`
            : undefined
        }
        confirmLabel="نعم، امنع الدخول"
        cancelLabel="تراجع"
      >
        <div className="bg-sky-50 dark:bg-sky-950/30 border border-sky-200/70 dark:border-sky-800 p-4 rounded-xl text-right">
          <p className="text-xs font-medium text-sky-800 dark:text-sky-300 leading-relaxed">
            بياناته تبقى ظاهرة على الموقع: ملفه الشخصي وكورساته المنشورة لن تتأثر
            إطلاقاً، وسيستمر الطلاب في رؤيتها والاشتراك فيها كالمعتاد.
            <br />
            هذا الإجراء يختلف عن زر «إيقاف»، الذي يخفي المدرّس وكورساته من الموقع
            بالكامل.
          </p>
        </div>
      </ConfirmDialog>

      {/* 4. SECURITY CONFIRMATION DIALOG (SUPER ADMIN CHECK ON DELETE) */}
      <Dialog
        open={state.showPasswordInput}
        onClose={() => {
          updateState({ showPasswordInput: false, deleteTargetId: null });
        }}
        title="تأكيد مصادقة الحذف الأمنية"
        description="عمليات الحذف تتطلب التحقق من مدير النظام الأعلى"
        icon={Lock}
        tone="danger"
        size="sm"
        footer={
          <>
            <Button
              variant="secondary"
              onClick={() => {
                updateState({ showPasswordInput: false, deleteTargetId: null });
              }}
            >
              إلغاء الأمر
            </Button>
            <Button variant="danger" onClick={handleConfirmDelete}>
              تأكيد الحذف نهائياً
            </Button>
          </>
        }
      >
        <div className="space-y-6">
          <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-800 p-4 rounded-xl flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
            <div className="space-y-1">
              <h4 className="text-sm font-black text-amber-800 dark:text-amber-300">تنبيه ذو حساسية عالية!</h4>
              <p className="text-xs font-medium text-amber-700/90 dark:text-amber-400/80 leading-relaxed">
                حذف المدرس سيؤدي إلى حجب حسابه وملفه تماماً. يرجى إدخال كلمة مرور حسابك للتأكيد.
              </p>
            </div>
          </div>

          <FormField
            label={
              <span className="inline-flex items-center gap-1.5">
                <KeyRound className="w-3.5 h-3.5 text-blue-500" />
                كلمة مرور الإشراف الفائق (Super Admin)
              </span>
            }
            error={state.passwordError || null}
          >
            {({ id, hasError }) => (
              <Input
                id={id}
                hasError={hasError}
                type="password"
                value={state.adminPassword}
                onChange={(e) => {
                  updateState({ adminPassword: e.target.value, passwordError: '' });
                }}
                placeholder="أدخل كلمة مرور الإدارة لتأكيد الحذف"
                className="text-center tracking-widest font-mono"
                autoFocus
                onKeyDown={(e) => {
                  if (e.key === 'Enter') handleConfirmDelete();
                }}
              />
            )}
          </FormField>
        </div>
      </Dialog>

    </div>
  );
}
