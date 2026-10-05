/**
 * Frontend mirror of backend/app/Http/Requests/UrlValidator.php.
 *
 * This is DEFENCE IN DEPTH ONLY. The backend is the enforcement point - it
 * rejects a bad URL at write time with a 422. This module exists so that a
 * value which predates the backend validation (or arrives from any other
 * path) is still never handed to an href / src / window.open.
 *
 * Keep the rules here equivalent to the PHP class:
 *   ALLOW  https://host/...  |  http://host/...  |  /internal/path
 *   REJECT javascript: data: vbscript: any other scheme, //protocol-relative,
 *          bare hosts, control characters, unparseable input.
 */

const ALLOWED_SCHEMES = ['http:', 'https:'];

/**
 * Control characters (NUL, TAB, CR, LF, DEL, ...). Browsers strip these before
 * parsing a URL, so "java<TAB>script:alert(1)" would slip past a naive scheme
 * check. Detected by char code rather than a regex literal so no raw control
 * byte ever has to appear in this source file.
 */
function hasControlChars(value: string): boolean {
  for (let i = 0; i < value.length; i++) {
    const code = value.charCodeAt(i);
    if (code <= 0x1f || code === 0x7f) return true;
  }
  return false;
}

export function isSafeUrl(value: unknown): value is string {
  if (typeof value !== 'string') return false;

  const url = value.trim();
  if (url === '') return false;
  if (hasControlChars(url)) return false;

  // Internal app path: exactly one leading slash. '//host' is
  // protocol-relative and goes off-site, so it is NOT an internal path.
  if (url.startsWith('/')) return !url.startsWith('//');

  try {
    const parsed = new URL(url);
    return ALLOWED_SCHEMES.includes(parsed.protocol) && parsed.host !== '';
  } catch {
    // Relative/bare values such as 'evil.example' throw here - rejected.
    return false;
  }
}

/**
 * Returns the URL when safe, otherwise undefined.
 *
 * Use for href/src props: <a href={safeUrl(x)}> renders a non-navigating
 * anchor rather than a javascript: sink when x is hostile.
 */
export function safeUrl(value: unknown): string | undefined {
  return isSafeUrl(value) ? value.trim() : undefined;
}

/**
 * Guarded window.open. Returns false when the URL was rejected, so callers
 * can surface a message instead of silently doing nothing.
 */
export function safeOpen(value: unknown): boolean {
  const url = safeUrl(value);
  if (!url) return false;
  window.open(url, '_blank', 'noopener,noreferrer');
  return true;
}
