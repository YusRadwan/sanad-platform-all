import api from './api';
import { logger } from './logger';

/**
 * Center System — covers all three roles' endpoints for one feature area
 * (admin center codes, instructor groups/sessions/attendance/reports,
 * student join/my-centers), matching the codebase's one-service-per-domain
 * convention rather than one-per-role. Every call mirrors the backend's
 * {success, message, data} envelope; each method unwraps `response.data.data`
 * and returns the relevant key.
 */
export const centerService = {
  // ── Admin: center codes ────────────────────────────────────────────

  async createCenterCodes(teacherId: string, count = 1) {
    try {
      const response = await api.post('/admin/center-codes', { teacher_id: teacherId, count });
      return response.data.data.codes ?? [];
    } catch (error) {
      logger.error('centerService.createCenterCodes failed', error as Error);
      throw error;
    }
  },

  async getCenterCodes() {
    try {
      const response = await api.get('/admin/center-codes');
      return response.data.data.codes ?? [];
    } catch (error) {
      logger.error('centerService.getCenterCodes failed', error as Error);
      throw error;
    }
  },

  async searchCenterCodes(params: { code?: string; teacher_id?: string }) {
    try {
      const response = await api.get('/admin/center-codes/search', { params });
      return response.data.data.codes ?? [];
    } catch (error) {
      logger.error('centerService.searchCenterCodes failed', error as Error);
      throw error;
    }
  },

  async getCenterCodeDetails(code: string) {
    try {
      const response = await api.get(`/admin/center-codes/${encodeURIComponent(code)}`);
      return response.data.data.code;
    } catch (error) {
      logger.error('centerService.getCenterCodeDetails failed', error as Error);
      throw error;
    }
  },

  /** "فك الربط" — removes one student's membership so they can rejoin the same (or any other) code of that teacher. */
  async unlinkMembership(membershipId: string) {
    try {
      await api.delete(`/admin/center-memberships/${membershipId}`);
    } catch (error) {
      logger.error('centerService.unlinkMembership failed', error as Error);
      throw error;
    }
  },

  // ── Instructor: groups ─────────────────────────────────────────────

  async getMyGroups() {
    try {
      const response = await api.get('/center/groups');
      return response.data.data.groups ?? [];
    } catch (error) {
      logger.error('centerService.getMyGroups failed', error as Error);
      throw error;
    }
  },

  async createGroup(data: {
    center_name: string;
    grade_label: string;
    group_name: string;
    billing_type: 'شهر' | 'حصة';
    schedules: Array<{ day_of_week: string; time: string }>;
  }) {
    try {
      const response = await api.post('/center/groups', data);
      return response.data.data.group;
    } catch (error) {
      logger.error('centerService.createGroup failed', error as Error);
      throw error;
    }
  },

  async updateGroup(id: string, data: Partial<{
    center_name: string;
    grade_label: string;
    group_name: string;
    billing_type: 'شهر' | 'حصة';
    schedules: Array<{ day_of_week: string; time: string }>;
  }>) {
    try {
      const response = await api.patch(`/center/groups/${id}`, data);
      return response.data.data.group;
    } catch (error) {
      logger.error('centerService.updateGroup failed', error as Error);
      throw error;
    }
  },

  /**
   * `password`: the TEACHER'S OWN password — only required by the backend
   * when the group actually has students on its roster ("لا يمكن حذف
   * مجموعة مسجل بها طلاب إلا بعد إدخال باسورد المدرس"); an empty group
   * deletes with no password at all. Sent both in the body and as the
   * `x-teacher-password` header, same two-way shape adminService's
   * deleteTeacher/deleteStudent use.
   */
  async deleteGroup(id: string, password?: string) {
    try {
      await api.delete(`/center/groups/${id}`, {
        data: { password },
        headers: password ? { 'x-teacher-password': password } : undefined,
      });
    } catch (error) {
      logger.error('centerService.deleteGroup failed', error as Error);
      throw error;
    }
  },

  async getGroupSessions(groupId: string) {
    try {
      const response = await api.get(`/center/groups/${groupId}/sessions`);
      return response.data.data.sessions ?? [];
    } catch (error) {
      logger.error('centerService.getGroupSessions failed', error as Error);
      throw error;
    }
  },

  // ── Instructor: center-visible courses & their materials ────────────

  /** Only the teacher's own `center`/`both` visibility courses. */
  async getCenterCourses() {
    try {
      const response = await api.get('/center/courses');
      return response.data.data.courses ?? [];
    } catch (error) {
      logger.error('centerService.getCenterCourses failed', error as Error);
      throw error;
    }
  },

  /** @returns {videos: [{id,title}], exams: [{id,title}]} for one center-visible course. */
  async getCourseMaterials(courseId: string) {
    try {
      const response = await api.get(`/center/courses/${courseId}/materials`);
      return response.data.data as { videos: { id: string; title: string }[]; exams: { id: string; title: string }[] };
    } catch (error) {
      logger.error('centerService.getCourseMaterials failed', error as Error);
      throw error;
    }
  },

  /**
   * "تفعيل كورس سنتر" — a free, no-payment grant. EXACTLY ONE of
   * `centerSessionId` (every attendee of that session) or `identifier` (one
   * student by center code/platform code/phone).
   */
  async activateCourse(courseId: string, params: { centerSessionId?: string; identifier?: string }) {
    try {
      const response = await api.post(`/center/courses/${courseId}/activate`, {
        center_session_id: params.centerSessionId,
        identifier: params.identifier,
      });
      return response.data.data as
        | { mode: 'session'; granted: number; total_attendees: number }
        | { mode: 'student'; student_name: string | null };
    } catch (error) {
      logger.error('centerService.activateCourse failed', error as Error);
      throw error;
    }
  },

  // ── Instructor: sessions / attendance ──────────────────────────────

  async openSession(centerGroupId: string, sessionDate?: string) {
    try {
      const response = await api.post('/center/sessions', {
        center_group_id: centerGroupId,
        session_date: sessionDate,
      });
      return response.data.data.session;
    } catch (error) {
      logger.error('centerService.openSession failed', error as Error);
      throw error;
    }
  },

  async attachExams(sessionId: string, examIds: string[]) {
    try {
      const response = await api.post(`/center/sessions/${sessionId}/exams`, { exam_ids: examIds });
      return response.data.data.session;
    } catch (error) {
      logger.error('centerService.attachExams failed', error as Error);
      throw error;
    }
  },

  async attachVideos(sessionId: string, videoIds: string[]) {
    try {
      const response = await api.post(`/center/sessions/${sessionId}/videos`, { video_ids: videoIds });
      return response.data.data.session;
    } catch (error) {
      logger.error('centerService.attachVideos failed', error as Error);
      throw error;
    }
  },

  async getSession(sessionId: string) {
    try {
      const response = await api.get(`/center/sessions/${sessionId}`);
      return response.data.data.session;
    } catch (error) {
      logger.error('centerService.getSession failed', error as Error);
      throw error;
    }
  },

  /**
   * `identifier`: center code, platform student code, or phone.
   * `confirm`: resend `true` after the teacher accepts the "this student
   * belongs to a different group" warning (a 409, message names the group,
   * on the first unconfirmed attempt) — see CenterAttendance.tsx.
   */
  async markAttendance(sessionId: string, identifier: string, confirm = false) {
    try {
      const response = await api.post(`/center/sessions/${sessionId}/attendance`, { identifier, confirm });
      return response.data.data.session;
    } catch (error: any) {
      // 409 here is an expected branch (cross-group confirmation), not a bug.
      if (error?.response?.status !== 409) {
        logger.error('centerService.markAttendance failed', error as Error);
      }
      throw error;
    }
  },

  async deleteAttendance(sessionId: string, attendanceId: string) {
    try {
      await api.delete(`/center/sessions/${sessionId}/attendance/${attendanceId}`);
    } catch (error) {
      logger.error('centerService.deleteAttendance failed', error as Error);
      throw error;
    }
  },

  async updateAttendanceNote(attendanceId: string, note: string | null) {
    try {
      await api.patch(`/center/attendance/${attendanceId}/note`, { note });
    } catch (error) {
      logger.error('centerService.updateAttendanceNote failed', error as Error);
      throw error;
    }
  },

  /** @returns {total, present, absent} */
  async closeSession(sessionId: string) {
    try {
      const response = await api.post(`/center/sessions/${sessionId}/close`);
      return response.data.data as { total: number; present: number; absent: number };
    } catch (error) {
      logger.error('centerService.closeSession failed', error as Error);
      throw error;
    }
  },

  /** "استعادة حضور الحصة" — reopens a closed session so attendance can be edited again. */
  async reopenSession(sessionId: string) {
    try {
      const response = await api.post(`/center/sessions/${sessionId}/reopen`);
      return response.data.data.session;
    } catch (error) {
      logger.error('centerService.reopenSession failed', error as Error);
      throw error;
    }
  },

  /** "حذف الحصة بالكامل" — hard-deletes the session and (server-side, via FK cascade) its attendance/exams/videos. */
  async deleteSession(sessionId: string) {
    try {
      await api.delete(`/center/sessions/${sessionId}`);
    } catch (error) {
      logger.error('centerService.deleteSession failed', error as Error);
      throw error;
    }
  },

  // ── Instructor: reports ────────────────────────────────────────────

  async getStudentsReport(params: {
    phone?: string;
    student_code?: string;
    center_name?: string;
    group_id?: string;
    grade_level?: string;
  }) {
    try {
      const response = await api.get('/center/reports/students', { params });
      return response.data.data.students ?? [];
    } catch (error) {
      logger.error('centerService.getStudentsReport failed', error as Error);
      throw error;
    }
  },

  /** "فك الربط" from the students report itself — teacher-scoped twin of the admin action. */
  async unlinkCenterMembership(membershipId: string) {
    try {
      await api.delete(`/center/memberships/${membershipId}`);
    } catch (error) {
      logger.error('centerService.unlinkCenterMembership failed', error as Error);
      throw error;
    }
  },

  // ── Instructor: "متابعة مدفوعات السنتر" — a PURE paid/unpaid tracking ──
  // log, never financial. See backend App\Models\CenterPaymentRecord's
  // docblock: no amount/price/balance field exists or may ever be added. ──

  /**
   * GET /center/payments/students — the chosen group's roster with each
   * student's paid/unpaid status for one (payment_type, period).
   * `period`: "YYYY-MM" for `month`, "YYYY-MM-DD" for `session`.
   */
  async getPaymentStudents(params: {
    group_id: string;
    payment_type: 'session' | 'month';
    period: string;
    q?: string;
  }) {
    try {
      const response = await api.get('/center/payments/students', { params });
      return response.data.data.students ?? [];
    } catch (error) {
      logger.error('centerService.getPaymentStudents failed', error as Error);
      throw error;
    }
  },

  /** "تسجيل حالة الدفع" — sets (creates or updates) one student's status for one period. */
  async setPaymentStatus(data: {
    group_id: string;
    student_id: string;
    payment_type: 'session' | 'month';
    period: string;
    status: 'paid' | 'unpaid';
  }) {
    try {
      const response = await api.post('/center/payments', data);
      return response.data.data;
    } catch (error) {
      logger.error('centerService.setPaymentStatus failed', error as Error);
      throw error;
    }
  },

  /** "تقرير الطالب" — every payment-tracking record for one student with this teacher. */
  async getPaymentReport(studentId: string) {
    try {
      const response = await api.get(`/center/payments/students/${studentId}/report`);
      return response.data.data as {
        student: {
          id: string;
          name: string | null;
          student_code: string | null;
          center_code: string | null;
          center_name: string | null;
          group_name: string | null;
        };
        records: Array<{
          id: string;
          payment_type: 'session' | 'month';
          period: string;
          status: 'paid' | 'unpaid';
          recorded_at: string;
        }>;
      };
    } catch (error) {
      logger.error('centerService.getPaymentReport failed', error as Error);
      throw error;
    }
  },

  // ── Student ─────────────────────────────────────────────────────────

  async joinCenter(code: string) {
    try {
      const response = await api.post('/center/join', { code });
      return response.data.data.teacher;
    } catch (error) {
      logger.error('centerService.joinCenter failed', error as Error);
      throw error;
    }
  },

  async getMyCenters() {
    try {
      const response = await api.get('/center/my-centers');
      return response.data.data.centers ?? [];
    } catch (error) {
      logger.error('centerService.getMyCenters failed', error as Error);
      throw error;
    }
  },
};

export default centerService;
