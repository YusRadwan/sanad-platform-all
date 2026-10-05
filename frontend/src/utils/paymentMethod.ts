/**
 * The ONLY two values `revenue_transactions.payment_method` can ever hold —
 * confirmed by reading every backend call site of RevenueService::
 * recordTransaction() (its sole two callers, AdminDashboardService and
 * EnrollmentCodeService, both always pass one of these two literals
 * explicitly). There is no online/electronic payment gateway anywhere in
 * this system: a student unlocks a course ONLY by redeeming the 8-digit
 * enrollment code an admin generated for it (`enrollment_code`), or an admin
 * flips their enrollment on manually (`manual_admin`). Do not add entries
 * here for a payment method that isn't actually written by the backend —
 * a UI label for a value that can never appear only implies a subscription
 * path (e.g. an online payment gateway) that does not exist.
 */
const PAYMENT_METHOD_LABELS: Record<string, string> = {
  manual_admin: 'تفعيل يدوي (أدمن)',
  enrollment_code: 'كود اشتراك',
};

export function paymentMethodLabel(value?: string | null): string {
  if (!value) return '—';
  return PAYMENT_METHOD_LABELS[value] ?? value;
}
