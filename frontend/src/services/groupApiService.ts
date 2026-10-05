import api from './api';
import { logger } from './logger';

export const groupApiService = {
  getInstructorGroups: async () => {
    try {
      const response = await api.get('/groups');
      return response.data.data.groups;
    } catch (error) {
      logger.error('groupApiService.getInstructorGroups failed', error as Error);
      throw error;
    }
  },
  createGroup: async (data: any) => {
    try {
      const response = await api.post('/groups', data);
      return response.data.data.group;
    } catch (error) {
      logger.error('groupApiService.createGroup failed', error as Error);
      throw error;
    }
  },
  updateGroup: async (id: string, data: any) => {
    try {
      const response = await api.patch(`/groups/${id}`, data);
      return response.data.data.group;
    } catch (error) {
      logger.error('groupApiService.updateGroup failed', error as Error);
      throw error;
    }
  },
  deleteGroup: async (id: string) => {
    try {
      await api.delete(`/groups/${id}`);
    } catch (error) {
      logger.error('groupApiService.deleteGroup failed', error as Error);
      throw error;
    }
  }
};
