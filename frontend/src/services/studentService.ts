import api from './api';
import { logger } from './logger';

export const studentService = {
  getDashboard: async () => {
    try {
      const response = await api.get('/students/dashboard');
      return response.data.data;
    } catch (error) {
      logger.error('studentService.getDashboard failed', error as Error);
      throw error;
    }
  },

  /** The calling student's own watch history — backs StudentWatchedVideos.tsx. */
  getWatchedVideos: async () => {
    try {
      const response = await api.get('/students/watched-videos');
      return response.data.data;
    } catch (error) {
      logger.error('studentService.getWatchedVideos failed', error as Error);
      throw error;
    }
  },

  /** The calling student's own full exam record — backs StudentCompletedExams.tsx. */
  getExamAttempts: async () => {
    try {
      const response = await api.get('/students/exam-attempts');
      return response.data.data;
    } catch (error) {
      logger.error('studentService.getExamAttempts failed', error as Error);
      throw error;
    }
  },
};
