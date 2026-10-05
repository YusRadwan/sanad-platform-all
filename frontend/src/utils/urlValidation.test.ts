import {describe, expect, it, vi} from 'vitest';

import {isSafeUrl, safeOpen, safeUrl} from './urlValidation';

/**
 * Guards the frontend half of the Part 2 URL allow-list. The backend
 * (backend/app/Http/Requests/UrlValidator.php) is the enforcement point and has
 * its own PHPUnit suite (backend/tests/Unit/UrlValidatorTest.php); this file
 * exists so the two implementations cannot silently drift apart — the case
 * lists below intentionally mirror that PHP test.
 */

const SAFE = [
  'https://example.com/course/1',
  'http://example.com',
  'https://example.com:8443/a?b=c#d',
  '/courses/abc',
  '/',
  '  https://example.com  ',
  'HTTPS://example.com',
];

const UNSAFE: unknown[] = [
  'javascript:alert(1)',
  'JaVaScRiPt:alert(1)',
  'data:text/html;base64,PHNjcmlwdD4=',
  'vbscript:msgbox(1)',
  'file:///etc/passwd',
  '//evil.example/x',
  'evil.example',
  'java\tscript:alert(1)',
  'java\nscript:alert(1)',
  '',
  '   ',
  null,
  undefined,
  123,
  ['https://example.com'],
];

describe('isSafeUrl', () => {
  it.each(SAFE)('accepts %j', (value) => {
    expect(isSafeUrl(value)).toBe(true);
  });

  it.each(UNSAFE)('rejects %j', (value) => {
    expect(isSafeUrl(value)).toBe(false);
  });
});

describe('safeUrl', () => {
  it('returns the trimmed url when safe', () => {
    expect(safeUrl('  https://example.com  ')).toBe('https://example.com');
  });

  it('returns undefined for a javascript: sink so href renders inert', () => {
    expect(safeUrl('javascript:alert(1)')).toBeUndefined();
  });
});

describe('safeOpen', () => {
  it('opens a safe url with noopener,noreferrer and reports success', () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);
    expect(safeOpen('https://example.com')).toBe(true);
    expect(open).toHaveBeenCalledWith('https://example.com', '_blank', 'noopener,noreferrer');
    open.mockRestore();
  });

  it('never calls window.open for a hostile url and reports failure', () => {
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);
    expect(safeOpen('javascript:alert(1)')).toBe(false);
    expect(open).not.toHaveBeenCalled();
    open.mockRestore();
  });
});
