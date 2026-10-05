/**
 * Locale note: every date in this product renders with Latin digits under
 * Arabic conventions — that is what the `-u-nu-latn` extension on the
 * `ar-EG` tag does. Keep it identical to the one in utils/formatMoney.ts.
 */

/** Plain calendar date, no time part. */
export function formatDate(dateString: string | number | Date): string {
  return new Date(dateString).toLocaleDateString('ar-EG-u-nu-latn');
}

export function formatDateTime(dateString: string): string {
  return new Date(dateString).toLocaleDateString('ar-EG-u-nu-latn', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}
