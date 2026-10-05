import api from './api';
import { logger } from './logger';
import { VideoItem } from '../types/index';

/**
 * Wraps the group-scoped content-item endpoints (type='video') used by
 * VideoManagement.tsx, mirroring groupNoteApiService.ts /
 * examAssignmentApiService.ts:
 *   GET/POST /groups/{groupId}/content-items
 *   PUT/DELETE /content-items/{id}
 * Backend detail fields (migration 0005 `videos` table, ContentItemService):
 *   video_url, duration, is_preview, provider, thumbnail_url.
 * "Group" here is the same course_groups / student-group entity used by
 * NotesManagement.tsx and ExamsAssignmentsManagement (VideoManagement.tsx
 * cascades course -> group exactly like those, confirmed by Group.courseId
 * usage), not a course Section.
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

export const toVideoItem = (item: any): VideoItem => {
  const detail = item.detail || {};
  const start = splitDateTime(item.start_at);
  const end = splitDateTime(item.end_at);
  return {
    id: item.id,
    groupId: item.group_id,
    courseId: item.course_id,
    title: item.title,
    description: item.description || '',
    video_url: detail.video_url,
    url: detail.video_url,
    duration: detail.duration !== undefined && detail.duration !== null ? Number(detail.duration) : undefined,
    provider: detail.provider || undefined,
    thumbnailUrl: detail.thumbnail_url || undefined,
    isFree: !!Number(detail.is_preview ?? 0),
    startDate: start.date,
    startTime: start.time,
    endDate: end.date,
    endTime: end.time,
    isHidden: !!item.is_hidden,
  };
};

interface VideoFormPayload {
  title: string;
  description?: string;
  url: string;
  provider: 'Bunny' | 'YouTube' | 'Vimeo';
  duration: number;
  thumbnailUrl?: string | null;
  isFree: boolean;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
}

const buildPayload = (p: VideoFormPayload) => ({
  type: 'video',
  title: p.title,
  description: p.description || null,
  video_url: p.url,
  provider: p.provider,
  duration: p.duration,
  thumbnail_url: p.thumbnailUrl || null,
  is_preview: p.isFree ? 1 : 0,
  start_at: joinDateTime(p.startDate, p.startTime) ?? null,
  end_at: joinDateTime(p.endDate, p.endTime) ?? null,
});

export const videoApiService = {
  listByGroup: async (groupId: string): Promise<VideoItem[]> => {
    try {
      const response = await api.get(`/groups/${groupId}/content-items`);
      // A group with zero content items comes back as `"data": {}`, not
      // `[]` — ApiResponse::normalise() converts any empty array to an
      // object — so `|| []` alone doesn't catch it (`{}` is truthy).
      const items = Array.isArray(response.data.data) ? response.data.data : [];
      return items.filter((i: any) => i.type === 'video').map(toVideoItem);
    } catch (error) {
      logger.error('videoApiService.listByGroup failed', error as Error);
      throw error;
    }
  },

  create: async (groupId: string, payload: VideoFormPayload): Promise<VideoItem> => {
    try {
      const response = await api.post(`/groups/${groupId}/content-items`, buildPayload(payload));
      return toVideoItem(response.data.data);
    } catch (error) {
      logger.error('videoApiService.create failed', error as Error);
      throw error;
    }
  },

  update: async (id: string, payload: VideoFormPayload): Promise<VideoItem> => {
    try {
      const response = await api.put(`/content-items/${id}`, buildPayload(payload));
      return toVideoItem(response.data.data);
    } catch (error) {
      logger.error('videoApiService.update failed', error as Error);
      throw error;
    }
  },

  delete: async (id: string): Promise<void> => {
    try {
      await api.delete(`/content-items/${id}`);
    } catch (error) {
      logger.error('videoApiService.delete failed', error as Error);
      throw error;
    }
  },
};
