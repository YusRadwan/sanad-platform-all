import api from './api';
import { logger } from './logger';
import { TeacherApplication, TeacherApplicationPayload } from '../types/index';

/**
 * "طلب مدرس جديد" — see open decision 10.23.
 *
 * `submit` is PUBLIC (no session), matching authService.register()'s shape:
 * a bare POST through the shared `api` instance so it still carries the CSRF
 * cookie like every other mutation, but needs no prior login. `listForAdmin`
 * is admin-only and lives here rather than in adminService.ts because this
 * whole feature — payload shape, both endpoints — is one cohesive unit with
 * nothing else in adminService to share.
 */
export const teacherApplicationService = {
  /** POST /teacher-applications */
  async submit(payload: TeacherApplicationPayload): Promise<TeacherApplication> {
    try {
      const response = await api.post('/teacher-applications', payload);
      return response.data.data.application as TeacherApplication;
    } catch (error) {
      logger.error('teacherApplicationService.submit failed', error as Error);
      throw error;
    }
  },

  /**
   * GET /admin/teacher-applications — admin only.
   *
   * `?all=1` because TeacherApplications.tsx searches and paginates the
   * whole list client-side; the backend now paginates by default (see
   * TeacherApplicationController::index()) so this passes the same escape
   * hatch adminService.ts uses for the student table.
   */
  async listForAdmin(): Promise<TeacherApplication[]> {
    try {
      const response = await api.get('/admin/teacher-applications', { params: { all: 1 } });
      return (response.data.data.items ?? []) as TeacherApplication[];
    } catch (error) {
      logger.error('teacherApplicationService.listForAdmin failed', error as Error);
      throw error;
    }
  },
};
