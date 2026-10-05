/**
 * Unified REST API response contract expected from the future PHP/MySQL backend.
 * Services that return the raw envelope (not yet unwrapped) should type their
 * return value as `Promise<ApiResponse<T>>`.
 */
export interface ApiResponse<T = any> {
  success: boolean;
  data: T;
  message?: string;
}
