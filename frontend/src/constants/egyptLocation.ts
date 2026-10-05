/**
 * Egypt-only static location constants.
 *
 * The platform used to let a visitor pick a "curriculum" (really a country)
 * that scoped the governorate list and phone digit-count. That system is
 * gone; the platform now serves Egypt only, so this list is hard-coded here
 * and shared by every form that used to fetch it.
 *
 * The grade list used to live here too (`PREP_GRADES`/`SEC_GRADES`), but it
 * is now the SAME admin-managed `grades` catalog teacher classification uses
 * (`catalogService.list('grades')`) — a single source an admin can edit,
 * reorder and delete from, instead of a hard-coded list only a code change
 * could touch. See `useSignupForm.ts` and `Admin/AddStudent.tsx`.
 */
export const EGYPT_GOVERNORATES: string[] = [
  'أسوان', 'أسيوط', 'الأقصر', 'الإسكندرية', 'الإسماعيلية', 'البحر الأحمر',
  'البحيرة', 'الجيزة', 'الدقهلية', 'السويس', 'الشرقية', 'الغربية', 'الفيوم',
  'القاهرة', 'القليوبية', 'المنوفية', 'المنيا', 'الوادي الجديد', 'بني سويف',
  'بورسعيد', 'جنوب سيناء', 'دمياط', 'سوهاج', 'شمال سيناء', 'قنا', 'كفر الشيخ',
  'مطروح',
];

export const EGYPT_PHONE_DIGITS = 11;
