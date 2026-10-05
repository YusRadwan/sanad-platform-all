import api from './api';
import { logger } from './logger';

export const adminService = {
  async getDashboardStats() {
    try {
      const response = await api.get('/admin/dashboard');
      const s = response.data.data.stats ?? {};
      // AdminHome.tsx and Statistics.tsx read `total_students` /
      // `total_teachers` / `total_courses` / `total_exams`, but
      // AdminRepository::dashboardStats emits `*_count`. Every tile on both
      // pages therefore fell through to its `|| 0` default and permanently
      // showed zero. The aliases are added here so both naming conventions
      // resolve, without changing a response shape other callers may read.
      return {
        ...s,
        total_students: s.students_count ?? 0,
        total_teachers: s.teachers_count ?? 0,
        total_courses: s.courses_count ?? 0,
        total_exams: s.exams_count ?? 0,
        total_users: s.users_count ?? 0,
      };
    } catch (error) {
      logger.error('adminService.getDashboardStats failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/teachers.
   *
   * Two real mismatches are absorbed here rather than by changing the
   * backend contract (which the instructor-facing pages also depend on):
   *
   *  1. The endpoint answers with the paginated envelope
   *     `data: { items, total, page, per_page }` — TeacherController::index
   *     calls TeacherService::list(). This function used to read
   *     `data.data.teachers`, which is simply not a key the response has, so
   *     it resolved to `undefined` and every consumer
   *     (ManageTeachers.tsx, BroadcastNotifications.tsx, useTeachers.ts)
   *     silently rendered an empty list.
   *  2. The row presenter emits `teacher_id` / `name` / `isActive`, while
   *     ManageTeachers.tsx matches rows on `user_id` and reads `is_active`.
   *
   * Both naming conventions are therefore emitted, so no consumer has to
   * care which one it was written against.
   */
  async getAllTeachers() {
    try {
      const response = await api.get('/admin/teachers');
      const payload = response.data.data;
      const rows = payload?.items ?? payload?.teachers ?? [];
      return (Array.isArray(rows) ? rows : []).map((t: any) => ({
        ...t,
        id: t.id ?? t.teacher_id ?? t.user_id,
        user_id: t.user_id ?? t.teacher_id ?? t.id,
        teacher_id: t.teacher_id ?? t.user_id ?? t.id,
        full_name: t.full_name ?? t.name,
        name: t.name ?? t.full_name,
        is_active: t.is_active ?? t.isActive,
        isActive: t.isActive ?? t.is_active,
        avatar_url: t.avatar_url ?? t.avatarUrl ?? null,
        banner_url: t.banner_url ?? t.bannerUrl ?? null,
        // A SEPARATE flag from is_active/isActive above: it blocks SIGN-IN only
        // and says nothing about visibility. Defaulted here rather than left
        // undefined so ManageTeachers.tsx can label its button from a real
        // boolean even against an older backend that does not emit the key.
        loginBlocked: t.loginBlocked ?? t.login_blocked ?? false,
      }));
    } catch (error) {
      logger.error('adminService.getAllTeachers failed', error as Error);
      throw error;
    }
  },

  async createTeacher(teacherData: any) {
    try {
      const response = await api.post('/admin/teachers', teacherData);
      // TeacherController::store returns the presented teacher AS `data`, with
      // no `teacher` wrapper — so `.teacher` was always undefined. Harmless
      // today (AddTeacher.tsx ignores the return value and navigates away) but
      // wrong, and it would silently break the first caller that uses it.
      return response.data.data?.teacher ?? response.data.data;
    } catch (error) {
      logger.error('adminService.createTeacher failed', error as Error);
      throw error;
    }
  },

  async updateTeacher(id: string, updateData: any) {
    try {
      const response = await api.put(`/admin/teachers/${id}`, updateData);
      // Same missing wrapper as createTeacher above.
      return response.data.data?.teacher ?? response.data.data;
    } catch (error) {
      logger.error('adminService.updateTeacher failed', error as Error);
      throw error;
    }
  },

  async deleteTeacher(id: string, password?: string) {
    try {
      await api.delete(`/admin/teachers/${id}`, {
        data: { password },
        headers: password ? { 'x-admin-password': password } : undefined
      });
    } catch (error) {
      logger.error('adminService.deleteTeacher failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/students.
   *
   * Same two mismatches as getAllTeachers above, and fixed the same way:
   * the endpoint answers `data: { items, total, ... }` (not `data.students`,
   * which does not exist), and StudentService's presenter emits
   * `student_id` / `name` / `status:'active'|'suspended'`, whereas
   * ManageUsers.tsx matches rows on `user_id` and reads `full_name` /
   * `is_active`. Both shapes are emitted so the page works without
   * renegotiating the backend contract used elsewhere.
   */
  async getAllStudents() {
    try {
      const response = await api.get('/admin/students');
      const payload = response.data.data;
      const rows = payload?.items ?? payload?.students ?? [];
      return (Array.isArray(rows) ? rows : []).map((s: any) => ({
        ...s,
        id: s.id ?? s.student_id ?? s.user_id,
        user_id: s.user_id ?? s.student_id ?? s.id,
        student_id: s.student_id ?? s.user_id ?? s.id,
        full_name: s.full_name ?? s.name,
        name: s.name ?? s.full_name,
        is_active: s.is_active ?? (s.status ? s.status === 'active' : true),
      }));
    } catch (error) {
      logger.error('adminService.getAllStudents failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/students with real server-side filtering/pagination (open
   * decision 10.17). Supersedes getAllStudents() for ManageUsers.tsx, which
   * previously fetched an unfiltered, un-paginated first page only and then
   * paginated that single page client-side — silently hiding every student
   * past the server's default per_page=20. `all: true` maps to `?all=1`
   * (the "عرض جميع الطلاب" button — bypasses pagination, capped server-side).
   */
  async getStudents(params: {
    student_code?: string;
    name?: string;
    phone?: string;
    parent_phone?: string;
    governorate?: string;
    page?: number;
    per_page?: number;
    all?: boolean;
  } = {}) {
    try {
      const query: Record<string, string> = {};
      Object.entries(params).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || value === false) return;
        query[key] = key === 'all' ? '1' : String(value);
      });
      const response = await api.get('/admin/students', { params: query });
      const payload = response.data.data;
      const items = Array.isArray(payload?.items) ? payload.items : [];
      return {
        items: items.map((s: any) => ({ ...s, id: s.student_id })),
        total: payload?.total ?? items.length,
        page: payload?.page ?? 1,
        per_page: payload?.per_page ?? items.length,
      };
    } catch (error) {
      logger.error('adminService.getStudents failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/subscriptions. Filtering is server-side (see
   * SubscriptionRepository::allForAdminGroupedSource) so the export can pull
   * the complete filtered set rather than whatever the table happens to hold.
   * Empty/undefined values are dropped, so calling with no argument keeps the
   * original unfiltered behaviour.
   */
  async getSubscriptions(filters?: {
    phone?: string;
    parent_phone?: string;
    student_code?: string;
    teacher_id?: string;
    course_id?: string;
  }) {
    try {
      const params: Record<string, string> = {};
      Object.entries(filters ?? {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && String(value).trim() !== '') {
          params[key] = String(value).trim();
        }
      });
      const response = await api.get('/admin/subscriptions', { params });
      return response.data.data.subscriptions;
    } catch (error) {
      logger.error('adminService.getSubscriptions failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/login-activity — real rows from the `login_sessions` table.
   * LoginActivity.tsx used to read a sessionStorage key that nothing ever
   * wrote, so the page was permanently empty.
   */
  async getLoginActivity() {
    try {
      const response = await api.get('/admin/login-activity');
      return response.data.data.sessions ?? [];
    } catch (error) {
      logger.error('adminService.getLoginActivity failed', error as Error);
      throw error;
    }
  },

  /** GET /admin/teachers/{id}/courses — cascading course picker. */
  async getTeacherCourses(teacherId: string) {
    try {
      const response = await api.get(`/admin/teachers/${teacherId}/courses`);
      return response.data.data.courses;
    } catch (error) {
      logger.error('adminService.getTeacherCourses failed', error as Error);
      throw error;
    }
  },

  /**
   * POST /admin/enrollment-codes — generates `count` 8-digit codes bound to a
   * course (default 1, server caps at 200). The response shape is always the
   * plural `{codes: [...]}` array now, even for count=1 (open decision 10.15
   * — a deliberate breaking change from the old singular `enrollmentCode`).
   */
  async createEnrollmentCode(courseId: string, count = 1) {
    try {
      const response = await api.post('/admin/enrollment-codes', { course_id: courseId, count });
      return response.data.data.codes;
    } catch (error) {
      logger.error('adminService.createEnrollmentCode failed', error as Error);
      throw error;
    }
  },

  /** GET /admin/enrollment-codes — recently generated codes, newest first. */
  async getEnrollmentCodes(courseId?: string) {
    try {
      const response = await api.get('/admin/enrollment-codes', {
        params: courseId ? { course_id: courseId } : {},
      });
      return response.data.data.enrollmentCodes;
    } catch (error) {
      logger.error('adminService.getEnrollmentCodes failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/enrollment-codes/{code} — full details of ONE code for the
   * "تفاصيل كود الاشتراك" page. Errors are re-thrown untouched so the caller
   * can tell a 404 ("no such code") apart from a real failure; swallowing
   * them here would make both look like an empty result.
   */
  async getEnrollmentCodeDetails(code: string) {
    try {
      const response = await api.get(`/admin/enrollment-codes/${encodeURIComponent(code)}`);
      return response.data.data.enrollmentCode;
    } catch (error) {
      logger.error('adminService.getEnrollmentCodeDetails failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/enrollment-codes/search — the "فلتر أكواد الاشتراك" review
   * screen. Five optional filters, ANDed server-side; same shape convention
   * as getSubscriptions(filters) — blank/absent values are simply omitted.
   */
  async searchEnrollmentCodes(filters?: {
    code?: string;
    teacher_name?: string;
    teacher_id?: string;
    student_name?: string;
    student_phone?: string;
    parent_phone?: string;
  }) {
    try {
      const params: Record<string, string> = {};
      Object.entries(filters ?? {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && String(value).trim() !== '') {
          params[key] = String(value).trim();
        }
      });
      const response = await api.get('/admin/enrollment-codes/search', { params });
      return response.data.data.enrollmentCodes;
    } catch (error) {
      logger.error('adminService.searchEnrollmentCodes failed', error as Error);
      throw error;
    }
  },

  /** GET /admin/students/{id} — a single student's full profile, for the "تعديل بيانات الطالب" page. */
  async getStudent(id: string) {
    try {
      const response = await api.get(`/admin/students/${id}`);
      return response.data.data;
    } catch (error) {
      logger.error('adminService.getStudent failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/students/by-code/{code} — profile + every subscription for the
   * "تفاصيل كود الطالب" page. Accepts the bare 6 digits or the full
   * STU###### form; the backend normalises both (StudentService::findByStudentCode).
   */
  async getStudentByCode(code: string) {
    try {
      const response = await api.get(`/admin/students/by-code/${encodeURIComponent(code)}`);
      const payload = response.data.data;
      return {
        student: payload?.student ?? null,
        subscriptions: Array.isArray(payload?.subscriptions) ? payload.subscriptions : [],
      };
    } catch (error) {
      logger.error('adminService.getStudentByCode failed', error as Error);
      throw error;
    }
  },

  async toggleEnrollmentStatus(studentId: string, courseId: string, isActive: boolean) {
    try {
      const response = await api.put('/admin/enrollments/toggle', { studentId, courseId, isActive });
      return response.data.data.enrollment;
    } catch (error) {
      logger.error('adminService.toggleEnrollmentStatus failed', error as Error);
      throw error;
    }
  },

  async updateStudentStatus(id: string, isActive: boolean) {
    try {
      const response = await api.put(`/admin/students/${id}`, { isActive });
      // StudentController::update also returns the presented student directly,
      // with no `student` wrapper. Same latent-undefined case as the two
      // teacher mutations above.
      return response.data.data?.student ?? response.data.data;
    } catch (error) {
      logger.error('adminService.updateStudentStatus failed', error as Error);
      throw error;
    }
  },

  /**
   * PUT /admin/students/{id} — full profile edit ("تعديل بيانات الطالب").
   * Field names are UpdateStudentRequest's wire names; an absent key is left
   * untouched server-side, so only the fields the edit form actually sent
   * should be included here.
   */
  async updateStudent(id: string, data: {
    fullName?: string;
    studentPhone?: string;
    parentPhone?: string;
    governorate?: string;
    gradeLevel?: string;
    educationalStage?: string;
    gender?: string;
    eduType?: string;
    /** "إعادة تعيين كلمة السر" — omitted or '' leaves the password untouched. */
    password?: string;
  }) {
    try {
      const response = await api.put(`/admin/students/${id}`, data);
      return response.data.data?.student ?? response.data.data;
    } catch (error) {
      logger.error('adminService.updateStudent failed', error as Error);
      throw error;
    }
  },

  /**
   * DELETE /admin/students/{id}. Requires the ACTING ADMIN'S OWN password
   * (DeleteStudentRequest's RequiresAdminReauth) — sent both in the body and
   * as the `x-admin-password` header, same as deleteTeacher() above. Answers
   * 409 (not 401/200) if the student still has an active subscription (open
   * decision 10.19) — the caller must show that message and point at
   * ManageSubscriptions, not retry.
   */
  async deleteStudent(id: string, password: string) {
    try {
      await api.delete(`/admin/students/${id}`, {
        data: { password },
        headers: { 'x-admin-password': password },
      });
    } catch (error) {
      logger.error('adminService.deleteStudent failed', error as Error);
      throw error;
    }
  },

  /**
   * POST /admin/students/{id}/impersonate — session-swap login as this
   * student (open decision 10.18). Returns the student's own PublicUser
   * payload, with `impersonating: true`.
   */
  async impersonateStudent(id: string) {
    try {
      const response = await api.post(`/admin/students/${id}/impersonate`);
      return response.data.data.user;
    } catch (error) {
      logger.error('adminService.impersonateStudent failed', error as Error);
      throw error;
    }
  },

  async getCoursesOverview() {
    try {
      const response = await api.get('/admin/courses/overview');
      return response.data.data.overview;
    } catch (error) {
      logger.error('adminService.getCoursesOverview failed', error as Error);
      throw error;
    }
  }
};
