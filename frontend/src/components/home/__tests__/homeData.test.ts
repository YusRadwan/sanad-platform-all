import { describe, it, expect } from 'vitest';
import { sortGrades, shortGrade, countCourses } from '../homeData';
describe('homeData', () => {
  it('orders grades by stage then year', () => {
    const api = ['الصف الأول الإعدادي','الصف الأول الثانوي','الصف الثاني الإعدادي','الصف الثاني الثانوي','الصف الثالث الإعدادي','الصف الثالث الثانوي','KG'];
    expect(sortGrades(api)).toEqual(['الصف الأول الإعدادي','الصف الثاني الإعدادي','الصف الثالث الإعدادي','الصف الأول الثانوي','الصف الثاني الثانوي','الصف الثالث الثانوي','KG']);
  });
  it('shortens grade names', () => { expect(shortGrade('الصف الثالث الإعدادي')).toBe('تالتة إعدادي'); expect(shortGrade('KG')).toBe('KG'); });
  it('counts in Arabic', () => { expect(countCourses(2)).toBe('كورسين'); expect(countCourses(3)).toBe('3 كورسات'); expect(countCourses(12)).toBe('12 كورس'); });
});
