import api from './api';
import { logger } from './logger';

/**
 * The three admin-managed option catalogs behind AddTeacher.tsx's
 * "التصنيفات" step. They used to be plain arrays in
 * `src/constants/{grades,subjects}.ts` held in React state, so every
 * add/delete the admin made vanished the moment the form closed. They are
 * real rows now — `grades`, `subjects`, `grade_sections` — served by backend
 * CatalogController.
 *
 * `grade_sections` (open decision 10.12) is the one catalog SCOPED by
 * another: every grade carries a `sectionId`, and creating/reordering a
 * grade requires passing its section's id — see `create`/`reorder` below.
 *
 * Reading is PUBLIC — no session required. The public, logged-out student
 * signup wizard reads the `grade_sections` and `grades` catalogs directly for
 * its section-then-grade picker (since the platform's curricula/countries
 * system was removed), so gating this would 401 the first step of signup.
 * Creating, deleting and reordering are admin-only and go through the shared
 * `api` instance, so they carry the CSRF token like every other mutation in
 * this app.
 */
export type CatalogType = 'grades' | 'subjects' | 'grade_sections';

export interface CatalogItem {
  id: string;
  name: string;
  /** Only present on `grades` items — the id of their parent section. */
  sectionId?: string;
}

export const catalogService = {
  /**
   * GET /catalogs/{type} — every option, in this catalog's display order
   * (`grades`/`grade_sections`: the admin's manual order; `subjects`:
   * alphabetical — see backend CatalogType::query()).
   */
  list: async (type: CatalogType): Promise<CatalogItem[]> => {
    try {
      const response = await api.get(`/catalogs/${type}`);
      return response.data.data.items as CatalogItem[];
    } catch (error) {
      logger.error(`catalogService.list(${type}) failed`, error as Error);
      throw error;
    }
  },

  /**
   * POST /catalogs/{type} — admin only. Rejects a duplicate name with 422.
   * `sectionId` is REQUIRED for `type === 'grades'` (the server 422s
   * without it) and ignored otherwise.
   */
  create: async (type: CatalogType, name: string, sectionId?: string): Promise<CatalogItem> => {
    try {
      const response = await api.post(`/catalogs/${type}`, {
        name,
        ...(sectionId ? { section_id: sectionId } : {}),
      });
      return response.data.data.item as CatalogItem;
    } catch (error) {
      logger.error(`catalogService.create(${type}) failed`, error as Error);
      throw error;
    }
  },

  /**
   * PATCH /catalogs/{type}/{id} — admin only. Renames the option IN PLACE.
   *
   * This used to be impossible on purpose. Teachers store their assigned
   * grades/subjects as NAME STRINGS in `teachers.grades`/`.subjects`, so a bare
   * rename would leave every one of them pointing at a name no longer in the
   * catalog. The server now renames the row AND rewrites those JSON arrays in a
   * single transaction, so the two can never disagree. Courses reference the
   * catalog by FOREIGN KEY and pick the new name up from a live lookup, so
   * nothing is written to them at all. A grade may not be moved to a
   * different section this way — rename only changes `name`.
   *
   * Rejects a duplicate name inside the same catalog with 422, exactly like
   * `create`. Callers surface the server's Arabic message.
   */
  rename: async (type: CatalogType, id: string, name: string): Promise<CatalogItem> => {
    try {
      const response = await api.patch(`/catalogs/${type}/${id}`, { name });
      return response.data.data.item as CatalogItem;
    } catch (error) {
      logger.error(`catalogService.rename(${type}, ${id}) failed`, error as Error);
      throw error;
    }
  },

  /**
   * DELETE /catalogs/{type}/{id} — admin only.
   *
   * Answers 409 when the option is still referenced by a teacher's assigned
   * grades/subjects, a live course, a student's grade_level (grades only), or
   * — for `grade_sections` — a grade still assigned to that section, because
   * deleting the row would orphan the reference. Callers surface the
   * server's Arabic message rather than removing the entry locally.
   */
  remove: async (type: CatalogType, id: string): Promise<void> => {
    try {
      await api.delete(`/catalogs/${type}/${id}`);
    } catch (error) {
      logger.error(`catalogService.remove(${type}, ${id}) failed`, error as Error);
      throw error;
    }
  },

  /**
   * PATCH /catalogs/{type}/reorder — admin only. `orderedIds` must be the
   * COMPLETE list of this catalog's current row ids, in the new display
   * order — the server rejects anything short of an exact permutation with
   * 422. `subjects` has no manual order and stays alphabetical; calling this
   * for it also 422s.
   *
   * `sectionId` is REQUIRED for `type === 'grades'`: a grade's `order` is
   * scoped PER SECTION, so `orderedIds` must be the exact permutation of
   * THAT section's grades only, not every grade in the catalog. Ignored for
   * `grade_sections` (reordered as one flat, unscoped list).
   */
  reorder: async (type: CatalogType, orderedIds: string[], sectionId?: string): Promise<CatalogItem[]> => {
    try {
      const response = await api.patch(`/catalogs/${type}/reorder`, {
        order: orderedIds,
        ...(sectionId ? { section_id: sectionId } : {}),
      });
      return response.data.data.items as CatalogItem[];
    } catch (error) {
      logger.error(`catalogService.reorder(${type}) failed`, error as Error);
      throw error;
    }
  },
};
