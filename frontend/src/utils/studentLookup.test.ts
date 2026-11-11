import { describe, expect, it } from 'vitest';
import {
  normalizePhone, normalizeStudentCode, phoneSearchFragment, toAsciiDigits,
} from './studentLookup';

describe('normalizePhone', () => {
  it.each([
    ['01012345678'],
    ['+201012345678'],
    ['201012345678'],
    ['00201012345678'],
    ['1012345678'],
    ['010 1234 5678'],
    ['+20 101-234-5678'],
    ['٠١٠١٢٣٤٥٦٧٨'],
  ])('normalises %s to 01012345678', (input) => {
    expect(normalizePhone(input)).toBe('01012345678');
  });

  it.each([[''], [null], [undefined], ['0101234567'], ['010123456789'], ['02123456789'], ['abc']])(
    'rejects %s',
    (input) => {
      expect(normalizePhone(input as string | null | undefined)).toBeNull();
    },
  );

  it('distinguishes two different numbers (exact match after partial search)', () => {
    expect(normalizePhone('+201012345678')).not.toBe(normalizePhone('01112345678'));
  });
});

describe('phoneSearchFragment', () => {
  it('is the trailing 10 digits, contained in every stored spelling', () => {
    const fragment = phoneSearchFragment('01012345678');
    expect(fragment).toBe('1012345678');
    for (const stored of ['01012345678', '+201012345678', '00201012345678']) {
      expect(stored.includes(fragment)).toBe(true);
    }
  });
});

describe('normalizeStudentCode', () => {
  it.each([['510078'], ['STU510078'], ['stu510078'], [' STU510078 '], ['٥١٠٠٧٨']])(
    'normalises %s to STU510078',
    (input) => {
      expect(normalizeStudentCode(input)).toBe('STU510078');
    },
  );

  it.each([[''], ['51007'], ['5100789'], ['ABC510078'], ['STU51007']])('rejects %s', (input) => {
    expect(normalizeStudentCode(input)).toBeNull();
  });
});

describe('toAsciiDigits', () => {
  it('converts Arabic-Indic and Persian digits', () => {
    expect(toAsciiDigits('٠١٢٣٤٥٦٧٨٩')).toBe('0123456789');
    expect(toAsciiDigits('۰۱۲۳۴۵۶۷۸۹')).toBe('0123456789');
  });
});
