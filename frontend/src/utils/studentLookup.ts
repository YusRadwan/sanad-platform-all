/**
 * Pure helpers for the admin «إلغاء اشتراك» search (CancelSubscription.tsx).
 * Kept here, outside the component, so the phone/code rules can be unit-tested.
 *
 * ┌── STUDENT PHONE FORMATS. ───────────────────────────────────────────────┐
 * │ Every write path in the app stores `students.phone` as 11 digits        │
 * │ "01xxxxxxxxx" (RegisterRequest `digits:11`; AddStudent/EditStudent       │
 * │ accept digits only, EGYPT_PHONE_DIGITS = 11). The PATCH API itself only │
 * │ says `string|max:20`, so "+201xxxxxxxxx" / "00201xxxxxxxxx" can exist   │
 * │ too. All of them share the same trailing 10 digits "1xxxxxxxxx".        │
 * │                                                                          │
 * │ So the SERVER is sent that 10-digit fragment (its `phone` filter is a   │
 * │ LIKE '%…%' on students.phone — never parent_phone), which matches every │
 * │ one of those spellings, and the CLIENT then keeps only rows whose       │
 * │ stored phone normalises to exactly the same 11-digit number.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

/** Arabic-Indic (٠-٩) and Persian (۰-۹) digits → ASCII, so Arabic keyboards work. */
export function toAsciiDigits(value: string): string {
  return value
    .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
}

/** Egyptian mobile in any common spelling → canonical "01xxxxxxxxx", or null. */
export function normalizePhone(raw: string | null | undefined): string | null {
  let digits = toAsciiDigits(String(raw ?? '')).replace(/\D/g, '');
  if (digits.startsWith('0020')) digits = digits.slice(4);
  else if (digits.startsWith('20') && digits.length === 12) digits = digits.slice(2);
  if (digits.length === 10 && digits.startsWith('1')) digits = '0' + digits;
  return /^01\d{9}$/.test(digits) ? digits : null;
}

/** The fragment sent to the server's contains-match `phone` filter: "1xxxxxxxxx". */
export function phoneSearchFragment(normalized: string): string {
  return normalized.slice(1);
}

const CODE_PATTERN = /^(STU)?\d{6}$/i;

/** "510078" | "stu510078" | "STU510078" → "STU510078", or null. */
export function normalizeStudentCode(raw: string): string | null {
  const v = toAsciiDigits(raw).trim().toUpperCase();
  if (!CODE_PATTERN.test(v)) return null;
  return v.startsWith('STU') ? v : 'STU' + v;
}
