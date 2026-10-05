import api from './api';
import { logger } from './logger';
import { LinkItem } from '../types/index';

/**
 * Wraps the group-scoped content-item endpoints (type='link') used by
 * LinksManagement.tsx, mirroring groupNoteApiService.ts /
 * examAssignmentApiService.ts / videoApiService.ts:
 *   GET/POST /groups/{groupId}/content-items
 *   PUT/DELETE /content-items/{id}
 * Backend detail field (migration 0005 `links` table): url.
 * "Group" here is the same course_groups / student-group entity used
 * elsewhere (LinksManagement.tsx cascades course -> group, matching
 * Group.courseId), not a course Section.
 */

const splitDateTime = (value?: string | null): { date?: string; time?: string } => {
  if (!value) return {};
  const [date, time] = String(value).split(/[T ]/);
  return { date, time: time ? time.slice(0, 5) : undefined };
};

const joinDateTime = (date?: string, time?: string): string | undefined => {
  if (!date) return undefined;
  return `${date}T${time || '00:00'}:00`;
};

export const toLinkItem = (item: any): LinkItem => {
  const detail = item.detail || {};
  const start = splitDateTime(item.start_at);
  const end = splitDateTime(item.end_at);
  return {
    id: item.id,
    groupId: item.group_id,
    courseId: item.course_id,
    title: item.title,
    description: item.description || '',
    url: detail.url || '',
    startDate: start.date,
    startTime: start.time,
    endDate: end.date,
    endTime: end.time,
    isHidden: !!item.is_hidden,
  };
};

interface LinkFormPayload {
  title: string;
  description?: string;
  url: string;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
}

const buildPayload = (p: LinkFormPayload) => ({
  type: 'link',
  title: p.title,
  description: p.description || null,
  url: p.url,
  start_at: joinDateTime(p.startDate, p.startTime) ?? null,
  end_at: joinDateTime(p.endDate, p.endTime) ?? null,
});

export const linkApiService = {
  listByGroup: async (groupId: string): Promise<LinkItem[]> => {
    try {
      const response = await api.get(`/groups/${groupId}/content-items`);
      // A group with zero content items comes back as `"data": {}`, not
      // `[]` — ApiResponse::normalise() converts any empty array to an
      // object — so `|| []` alone doesn't catch it (`{}` is truthy).
      const items = Array.isArray(response.data.data) ? response.data.data : [];
      return items.filter((i: any) => i.type === 'link').map(toLinkItem);
    } catch (error) {
      logger.error('linkApiService.listByGroup failed', error as Error);
      throw error;
    }
  },

  create: async (groupId: string, payload: LinkFormPayload): Promise<LinkItem> => {
    try {
      const response = await api.post(`/groups/${groupId}/content-items`, buildPayload(payload));
      return toLinkItem(response.data.data);
    } catch (error) {
      logger.error('linkApiService.create failed', error as Error);
      throw error;
    }
  },

  update: async (id: string, payload: LinkFormPayload): Promise<LinkItem> => {
    try {
      const response = await api.put(`/content-items/${id}`, buildPayload(payload));
      return toLinkItem(response.data.data);
    } catch (error) {
      logger.error('linkApiService.update failed', error as Error);
      throw error;
    }
  },

  delete: async (id: string): Promise<void> => {
    try {
      await api.delete(`/content-items/${id}`);
    } catch (error) {
      logger.error('linkApiService.delete failed', error as Error);
      throw error;
    }
  },
};
