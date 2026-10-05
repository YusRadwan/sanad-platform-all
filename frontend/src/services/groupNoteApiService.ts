import api from './api';
import { logger } from './logger';

/**
 * Wraps the group-scoped content-item endpoints used by NotesManagement.tsx:
 * GET/POST /groups/{groupId}/content-items and the generic
 * PUT/DELETE /content-items/{id} (backend ContentItemController /
 * ContentItemService::listByGroup/createForGroup, migration 0005
 * content_items.group_id + notes detail table).
 */
export const groupNoteApiService = {
  listByGroup: async (groupId: string) => {
    try {
      const response = await api.get(`/groups/${groupId}/content-items`);
      // A group with zero content items comes back as `"data": {}`, not
      // `[]` — ApiResponse::normalise() converts any empty array to an
      // object — so callers that iterate this need an explicit array guard.
      return Array.isArray(response.data.data) ? response.data.data : [];
    } catch (error) {
      logger.error('groupNoteApiService.listByGroup failed', error as Error);
      throw error;
    }
  },
  createNote: async (groupId: string, data: any) => {
    try {
      const response = await api.post(`/groups/${groupId}/content-items`, { ...data, type: 'note' });
      return response.data.data;
    } catch (error) {
      logger.error('groupNoteApiService.createNote failed', error as Error);
      throw error;
    }
  },
  updateNote: async (id: string, data: any) => {
    try {
      const response = await api.put(`/content-items/${id}`, data);
      return response.data.data;
    } catch (error) {
      logger.error('groupNoteApiService.updateNote failed', error as Error);
      throw error;
    }
  },
  deleteNote: async (id: string) => {
    try {
      await api.delete(`/content-items/${id}`);
    } catch (error) {
      logger.error('groupNoteApiService.deleteNote failed', error as Error);
      throw error;
    }
  },
};
