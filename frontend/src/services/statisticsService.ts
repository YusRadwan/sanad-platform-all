import api from './api';
import { logger } from './logger';

/**
 * "إحصائيات الامتحانات" / "إحصائيات المشاهدات" — instructor-only, owner-only
 * reports (backend App\Http\Controllers\ExamStatisticsController /
 * VideoStatisticsController). Both mirror the same {stats, attempted|watched,
 * not_attempted|not_watched} shape.
 */
export const statisticsService = {
  async getExamStatistics(examId: string) {
    try {
      const response = await api.get(`/exams/${examId}/statistics`);
      return response.data.data;
    } catch (error) {
      logger.error(`statisticsService.getExamStatistics(${examId}) failed`, error as Error);
      throw error;
    }
  },

  async getVideoWatchStatistics(contentItemId: string) {
    try {
      const response = await api.get(`/content-items/${contentItemId}/watch-statistics`);
      return response.data.data;
    } catch (error) {
      logger.error(`statisticsService.getVideoWatchStatistics(${contentItemId}) failed`, error as Error);
      throw error;
    }
  },
};
