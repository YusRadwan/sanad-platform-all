import api from './api';
import { logger } from './logger';

/**
 * `kind` selects the storage bucket + MIME/size rules on the backend
 * (UploadService::KIND_RULES):
 *   'avatar' | 'banner' | 'image'  -> public imagery, gets a servable `url`
 *   'document'                     -> private educational file, `url` is null
 *
 * 'image' exists for inline teaching imagery (QuestionBank question images,
 * VideoManagement thumbnails). Those used to post as 'document', which only
 * ever succeeded through an application/octet-stream catch-all in the document
 * allow-list — the file was stored as a ".bin". That catch-all was a MIME
 * validation bypass and has been removed, so those flows use 'image' now.
 */
export type UploadKind = 'avatar' | 'banner' | 'image' | 'document';

export interface UploadedFile {
  file_name: string;
  stored_name: string;
  /**
   * Storage-relative identifier, e.g. "uploads/documents/<uuid>.pdf".
   * This is NOT a URL and is not fetchable — nothing under storage/ is served
   * by the web server. It is what notes.file_path stores; the file is read back
   * via GET /api/v1/content-items/{id}/file, behind the course access gate.
   */
  path: string;
  /**
   * Servable URL, or NULL for the private 'document' kind, which has no
   * addressable URL by design.
   *
   * Public URLs look like "/api/v1/files/avatars/<uuid>" — served through PHP
   * (FileController), never straight off disk, and deliberately carrying no
   * file extension so no static-file handler can claim them ahead of the
   * backend front controller.
   */
  url: string | null;
  mime: string;
  size: number;
}

/**
 * Wraps POST /uploads (backend UploadController::store / UploadService::store).
 */
export const uploadApiService = {
  upload: async (file: File, kind: UploadKind): Promise<UploadedFile> => {
    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('type', kind);
      const response = await api.post('/uploads', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return response.data.data.file as UploadedFile;
    } catch (error) {
      logger.error('uploadApiService.upload failed', error as Error);
      throw error;
    }
  },

  /**
   * Upload a PUBLIC image kind and return its servable URL.
   *
   * Callers that need a `string` (an <img src>, a course banner field) use this
   * instead of reading `.url` directly, so the null case — which only a
   * 'document' upload can produce — is handled explicitly rather than being
   * silenced with a non-null assertion.
   */
  uploadPublicImage: async (file: File, kind: 'avatar' | 'banner' | 'image'): Promise<string> => {
    const uploaded = await uploadApiService.upload(file, kind);
    if (!uploaded.url) {
      // Unreachable for the three public kinds; treated as a hard failure
      // rather than silently rendering a broken image.
      throw new Error('Upload succeeded but returned no servable URL');
    }
    return uploaded.url;
  },

  /**
   * Downloads the real file attached to a group's content item (note/file)
   * via GET /content-items/{id}/file, and saves it under the browser's
   * native download flow. `fallbackName` is used only if the server response
   * carries no filename (it always does — see FileController::download).
   */
  downloadContentItemFile: async (contentItemId: string, fallbackName = 'file'): Promise<void> => {
    try {
      const response = await api.get(`/content-items/${contentItemId}/file`, { responseType: 'blob' });
      const disposition = response.headers?.['content-disposition'] as string | undefined;
      const match = disposition?.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);
      const filename = match ? decodeURIComponent(match[1]) : fallbackName;

      const url = URL.createObjectURL(response.data as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (error) {
      logger.error('uploadApiService.downloadContentItemFile failed', error as Error);
      throw error;
    }
  },
};
