/**
 * Single source of truth for rendering an EGP amount in the UI.
 *
 * The locale tag is `ar-EG-u-nu-latn` deliberately: the interface is Arabic
 * (RTL) but every number in this product is displayed with Latin digits
 * (0-9), never Arabic-Indic (٠-٩) — the `-u-nu-latn` extension is what forces
 * that while keeping Arabic grouping/decimal conventions. Changing it would
 * silently switch digits across every revenue screen at once.
 *
 * This was previously copy-pasted as a local `formatCurrency` inside
 * Admin/Revenue.tsx, Admin/RefundManagement.tsx, Admin/InstructorStatement.tsx,
 * Admin/TeacherPayouts.tsx and Instructor/InstructorRevenue.tsx (five
 * byte-identical copies), plus a near-identical `money()` in
 * Admin/Statistics.tsx that additionally capped fraction digits at 2.
 * Both behaviours are covered here via `maximumFractionDigits`.
 */
export function formatMoney(
  amount: number | string | null | undefined,
  options?: { maximumFractionDigits?: number },
): string {
  const n = Number(amount ?? 0);
  const safe = Number.isFinite(n) ? n : 0;
  return `${safe.toLocaleString('ar-EG-u-nu-latn', options)} ج.م`;
}

/** Same digit convention as formatMoney but with no currency suffix. */
export function formatNumber(
  value: number | string | null | undefined,
  options?: { maximumFractionDigits?: number },
): string {
  const n = Number(value ?? 0);
  const safe = Number.isFinite(n) ? n : 0;
  return safe.toLocaleString('ar-EG-u-nu-latn', options);
}
