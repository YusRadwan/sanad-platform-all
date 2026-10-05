import api from './api';
import { logger } from './logger';

export const enrollmentApiService = {
  getInstructorStudents: async () => {
    try {
      const response = await api.get('/enrollments/instructor-students');
      return response.data.data.students;
    } catch (error) {
      logger.error('enrollmentApiService.getInstructorStudents failed', error as Error);
      throw error;
    }
  },

  /**
   * POST /enrollment-codes/redeem — student redeems an 8-digit code.
   *
   * Only the code is sent. The course is resolved from the code's own row
   * on the server and the student from the session, so the caller cannot
   * point a valid code at a different course or at another student.
   */
  redeemEnrollmentCode: async (code: string) => {
    try {
      const response = await api.post('/enrollment-codes/redeem', { code });
      return response.data.data;
    } catch (error) {
      logger.error('enrollmentApiService.redeemEnrollmentCode failed', error as Error);
      throw error;
    }
  }
};
