import api from './api';
import { logger } from './logger';
import { PlatformNotification } from '../types/index';

/**
 * Admin broadcast notifications — the real API behind
 * components/Admin/BroadcastNotifications.tsx, which before this existed
 * mutated local React state only and persisted nothing.
 *
 * Own file (rather than another method on adminService) to match the
 * convention already used by announcementApiService / enrollmentApiService:
 * one file per bounded admin feature.
 *
 * Every endpoint is admin-only server-side
 * (RoleMiddleware::for('admin') in backend/routes/api.php) and the write verbs
 * are rate limited by the 'broadcast' bucket — that is the enforcement point;
 * nothing here is a security control.
 */

/** Exactly the four radio options the create form offers. */
export type BroadcastAudience =
  | 'all_teachers'
  | 'all_staff'
  | 'selected_teachers'
  | 'specific_user';

export interface BroadcastPayload {
  title: string;
  body: string;
  /** Optional link. Validated server-side by UrlValidator; javascript:/data: are rejected with a 422. */
  link?: string | null;
  audience: BroadcastAudience;
  /** users.id list — required when audience === 'selected_teachers'. */
  target_user_ids?: string[];
  /** Username or user id — required when audience === 'specific_user'. */
  specific_user?: string;
  /** 'YYYY-MM-DDTHH:mm', straight from the datetime-local input. */
  start_at: string;
  end_at?: string | null;
}

/**
 * What the backend presenter emits. It is a PlatformNotification (so the
 * existing card JSX renders it unchanged) plus two admin-only extras.
 */
export interface Broadcast extends PlatformNotification {
  created_by_username: string | null;
  recipients_count: number;
}

export const broadcastApiService = {
  listBroadcasts: async (): Promise<Broadcast[]> => {
    try {
      const response = await api.get('/admin/broadcasts');
      return response.data.data.broadcasts ?? [];
    } catch (error) {
      logger.error('broadcastApiService.listBroadcasts failed', error as Error);
      throw error;
    }
  },

  createBroadcast: async (payload: BroadcastPayload): Promise<Broadcast> => {
    try {
      const response = await api.post('/admin/broadcasts', payload);
      return response.data.data.broadcast;
    } catch (error) {
      logger.error('broadcastApiService.createBroadcast failed', error as Error);
      throw error;
    }
  },

  updateBroadcast: async (id: string, payload: BroadcastPayload): Promise<Broadcast> => {
    try {
      const response = await api.put(`/admin/broadcasts/${id}`, payload);
      return response.data.data.broadcast;
    } catch (error) {
      logger.error('broadcastApiService.updateBroadcast failed', error as Error);
      throw error;
    }
  },

  setBroadcastActive: async (id: string, isActive: boolean): Promise<Broadcast> => {
    try {
      const response = await api.patch(`/admin/broadcasts/${id}/toggle`, { is_active: isActive });
      return response.data.data.broadcast;
    } catch (error) {
      logger.error('broadcastApiService.setBroadcastActive failed', error as Error);
      throw error;
    }
  },

  deleteBroadcast: async (id: string): Promise<void> => {
    try {
      await api.delete(`/admin/broadcasts/${id}`);
    } catch (error) {
      logger.error('broadcastApiService.deleteBroadcast failed', error as Error);
      throw error;
    }
  }
};
