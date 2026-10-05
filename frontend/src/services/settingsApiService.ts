import api from './api';
import { logger } from './logger';

export const settingsApiService = {
  getSettings: async () => {
    try {
      const response = await api.get('/settings');
      return response.data.data;
    } catch (error) {
      logger.error('settingsApiService.getSettings failed', error as Error);
      throw error;
    }
  },
  updateSettings: async (data: any) => {
    try {
      const response = await api.post('/settings', data);
      return response.data.data;
    } catch (error) {
      logger.error('settingsApiService.updateSettings failed', error as Error);
      throw error;
    }
  }
};
