import { COURSE_IMAGE_PLACEHOLDER } from '../../utils/imagePlaceholders';

/**
 * Shared helpers for the "كراسة سند" homepage (see HomePage.tsx).
 */

/** The six grades, used when the /catalogs/grades request fails. */
export const FALLBACK_GRADES = [
  'الصف الأول الإعدادي',
  'الصف الثاني الإعدادي',
  'الصف الثالث الإعدادي',
  'الصف الأول الثانوي',
  'الصف الثاني الثانوي',
  'الصف الثالث الثانوي',
];

const ORDINAL_SHORT: Record<string, string> = {
  'الأول': 'أولى',
  'الثاني': 'تانية',
  'الثالث': 'تالتة',
};

/**
 * "الصف الثالث الإعدادي" -> "تالتة إعدادي" — the way Egyptian students say
 * their grade. Anything that does not match the catalogue pattern is shown
 * as written.
 */
export function shortGrade(name: string): string {
  const m = name.trim().match(/^الصف\s+(\S+)\s+ال(\S+)$/);
  if (!m) return name;
  const ordinal = ORDINAL_SHORT[m[1]];
  return ordinal ? `${ordinal} ${m[2]}` : name;
}

const ORDINAL_RANK: Record<string, number> = { 'الأول': 1, 'الثاني': 2, 'الثالث': 3 };
const STAGE_RANK: Record<string, number> = { 'ابتدائي': 0, 'إعدادي': 1, 'ثانوي': 2 };

/**
 * The grades catalogue is ordered per stage (each stage's grades restart at
 * sort 0), so the raw list interleaves "أولى إعدادي، أولى ثانوي، تانية
 * إعدادي…". Put it back in school order: stage, then year. Names that do not
 * follow the "الصف <ordinal> ال<stage>" pattern keep their place at the end.
 */
export function sortGrades(names: string[]): string[] {
  const rank = (name: string) => {
    const m = name.trim().match(/^الصف\s+(\S+)\s+ال(\S+)$/);
    if (!m || ORDINAL_RANK[m[1]] === undefined || STAGE_RANK[m[2]] === undefined) return 1000;
    return STAGE_RANK[m[2]] * 10 + ORDINAL_RANK[m[1]];
  };
  return names
    .map((name, i) => ({ name, i, r: rank(name) }))
    .sort((a, b) => a.r - b.r || a.i - b.i)
    .map((x) => x.name);
}

/**
 * Cover colour per subject. Every value carries white text at >= 7:1, and
 * each subject keeps one colour everywhere so a student learns it.
 */
export function subjectColor(subject = ''): string {
  const s = subject.toLowerCase();
  if (/إنجليز|انجليز|english/.test(s)) return '#3544E1';
  if (/فيزياء|physics/.test(s)) return '#0E5E63';
  if (/كيمياء|chem/.test(s)) return '#8A3A22';
  if (/عربي/.test(s)) return '#1E5B3E';
  if (/أحياء|احياء|biolog/.test(s)) return '#4A5D23';
  if (/فرنس|french/.test(s)) return '#6B2D4F';
  if (/رياض|math/.test(s)) return '#0F4C81';
  return '#3D4466';
}

/** A course thumbnail that is a real upload (not the grey placeholder). */
export function hasRealImage(url?: string): boolean {
  return Boolean(url) && url !== COURSE_IMAGE_PLACEHOLDER;
}

export function initialOf(name = ''): string {
  return name.replace(/^(أ\.|م\.|د\.)\s*/, '').trim().charAt(0) || 'س';
}

const nf = new Intl.NumberFormat('ar-EG', { numberingSystem: 'latn', maximumFractionDigits: 0 });
export const formatPrice = (n: number) => nf.format(n);

/** "3 كورسات" with the Arabic dual/plural forms. */
export function countCourses(n: number): string {
  if (n === 0) return 'مفيش كورسات لسه';
  if (n === 1) return 'كورس واحد';
  if (n === 2) return 'كورسين';
  if (n <= 10) return `${n} كورسات`;
  return `${n} كورس`;
}

export const GRADE_STORAGE_KEY = 'sanad.home.grade';
