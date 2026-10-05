import api from './api';
import { logger } from './logger';

export interface Notification {
  id: string;
  user_id: string;
  title: string;
  message: string;
  link: string | null;
  is_read: boolean;
  created_at: string;
}

export const notificationService = {
  getNotifications: async (): Promise<Notification[]> => {
    try {
      const response = await api.get('/notifications');
      return response.data.data.notifications;
    } catch (error) {
      logger.error('notificationService.getNotifications failed', error as Error);
      throw error;
    }
  },

  markAsRead: async (notificationId: string): Promise<Notification> => {
    try {
      const response = await api.post(`/notifications/${notificationId}/mark-read`);
      return response.data.data.notification;
    } catch (error) {
      logger.error('notificationService.markAsRead failed', error as Error);
      throw error;
    }
  }
};
