/**
 * Mirrors the backend's `App\Actions\Upload\StoreUpload` type/extension
 * allow-list, which is the enforcement point — these checks are a UX
 * convenience so the user sees an immediate message instead of an opaque 422.
 *
 * image/svg+xml is deliberately absent. An SVG is an XML document that can
 * carry <script>, so a stored SVG is a stored-XSS vector; the backend rejects
 * it for every upload category and listing it here would only produce a
 * confusing "accepted locally, rejected remotely" flow.
 *
 * NO SIZE LIMIT — open decision 10.22, explicit user request ("لا تقيديني
 * بمساحة ملف او صورة"). This module used to also enforce MAX_IMAGE_SIZE_BYTES
 * (2MB) / MAX_DOCUMENT_SIZE_BYTES (10MB); the backend's matching per-kind cap
 * (`UploadKind::maxBytes()`) has been removed the same way, so there is no
 * silent mismatch where the frontend allows something the server still 422s.
 * The only real ceiling left is PHP's own `upload_max_filesize`/
 * `post_max_size` ini limits, which neither layer of this application code
 * controls.
 */
export const ALLOWED_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];

export const ALLOWED_DOCUMENT_TYPES = [
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-powerpoint',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

export interface FileValidationResult {
  valid: boolean;
  error?: string;
}

export function validateFile(
  file: File,
  options: { allowedTypes: string[]; typeErrorMessage: string }
): FileValidationResult {
  const { allowedTypes, typeErrorMessage } = options;

  if (!allowedTypes.includes(file.type)) {
    return { valid: false, error: typeErrorMessage };
  }

  return { valid: true };
}

export function validateImageFile(
  file: File,
  options?: { allowedTypes?: string[] }
): FileValidationResult {
  return validateFile(file, {
    allowedTypes: options?.allowedTypes ?? ALLOWED_IMAGE_TYPES,
    typeErrorMessage: 'صيغة الملف غير مدعومة. الصيغ المسموحة: PNG, JPG, WEBP',
  });
}

export function validateDocumentFile(
  file: File,
  options?: { allowedTypes?: string[] }
): FileValidationResult {
  return validateFile(file, {
    allowedTypes: options?.allowedTypes ?? ALLOWED_DOCUMENT_TYPES,
    typeErrorMessage: 'صيغة الملف غير مدعومة. الصيغ المسموحة: PDF, Word, PowerPoint',
  });
}
