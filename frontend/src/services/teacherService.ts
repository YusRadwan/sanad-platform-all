import api from './api';
import { logger } from './logger';

export const teacherService = {
  getStatistics: async () => {
    try {
      const response = await api.get('/teachers/statistics');
      return response.data.data.stats;
    } catch (error) {
      logger.error('teacherService.getStatistics failed', error as Error);
      throw error;
    }
  },
  getAllTeachers: async () => {
    try {
      const response = await api.get('/teachers');
      return response.data.data.teachers;
    } catch (error) {
      logger.error('teacherService.getAllTeachers failed', error as Error);
      throw error;
    }
  }
};
