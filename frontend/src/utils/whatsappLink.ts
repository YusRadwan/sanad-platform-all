/**
 * Turns a support-settings phone number (in whatever local/international
 * shape an admin typed it) into a working `https://wa.me/<digits>` link.
 *
 * Extracted from `WhatsAppWidget.tsx`'s inline `handleOpenWhatsApp` so the
 * teacher-page support widget can open the exact same links without a second,
 * independently-drifting copy of the country-code guessing rules.
 */
export function buildWhatsAppLink(rawNumber: string): string {
  let cleaned = rawNumber.replace(/[^0-9]/g, '');

  // Remove a leading international-dialling "00" if present.
  if (cleaned.startsWith('00')) {
    cleaned = cleaned.substring(2);
  }

  // Local formats -> prepend the matching country code.
  if (cleaned.startsWith('0')) {
    if (cleaned.startsWith('01') && cleaned.length === 11) {
      // Egypt: replace leading '0' with '20'.
      cleaned = '20' + cleaned.substring(1);
    } else if (cleaned.startsWith('05') && cleaned.length === 10) {
      // Saudi Arabia: replace leading '0' with '966'.
      cleaned = '966' + cleaned.substring(1);
    } else {
      // Default Egyptian/general prefix: replace leading '0' with '20'.
      cleaned = '20' + cleaned.substring(1);
    }
  } else if (cleaned.startsWith('1') && cleaned.length === 10) {
    // Egypt (leading 0 omitted, e.g. 1012345678): prepend '20'.
    cleaned = '20' + cleaned;
  }

  return `https://wa.me/${cleaned}`;
}
