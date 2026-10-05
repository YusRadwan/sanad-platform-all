import axios from 'axios';
import { authRepository } from '../repositories/authRepository';

const apiVersion = (typeof process !== 'undefined' && process.env?.VITE_API_VERSION) || 'v1';
const API_URL = `/api/${apiVersion}`;

const api = axios.create({
  baseURL: API_URL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/vnd.sanad.v1+json',
    'API-Version': apiVersion,
  },
});

// Reads a cookie value by name. The backend (CsrfMiddleware) sets a
// non-HttpOnly cookie named "sanad_csrf" specifically so the frontend can
// read it here and echo it back as the X-CSRF-Token header.
function getCookie(name: string): string {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return match ? decodeURIComponent(match[1]) : '';
}

// Add a request interceptor to inject CSRF token
api.interceptors.request.use(
  (config) => {
    // Inject CSRF token for mutating requests (POST/PUT/DELETE)
    const method = config.method?.toUpperCase();
    if (method && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]');
      const csrfToken =
        (csrfMeta ? csrfMeta.getAttribute('content') : '') ||
        (window as any).__csrf_token__ ||
        getCookie('sanad_csrf');
      if (csrfToken) {
        config.headers['X-CSRF-Token'] = csrfToken;
      }
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Add a response interceptor to handle token refresh automatically via cookies
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;
    // Endpoints that legitimately answer 401 on their own merits — a rejected
    // login/register attempt is NOT "an authenticated session that needs
    // refreshing", and silently retrying it via /auth/refresh swallowed the
    // real Arabic error message (e.g. wrong credentials) and replaced it with
    // the refresh endpoint's own failure ("Session expired"), even though the
    // visitor was never logged in to begin with.
    const isAuthFlowEndpoint = originalRequest.url === '/auth/refresh'
      || originalRequest.url === '/auth/login'
      || originalRequest.url === '/auth/register';
    // If the request fails with 401 and it hasn't been retried yet (and it's not an auth-flow endpoint)
    if (error.response?.status === 401 && !originalRequest._retry && !isAuthFlowEndpoint) {
      originalRequest._retry = true;
      try {
        // Attempt to refresh the session via cookie. Must go through the
        // `api` instance (not raw axios) so the request interceptor above
        // injects the X-CSRF-Token header — the backend's CsrfMiddleware
        // rejects any POST without it, which previously made every refresh
        // attempt fail with 403 and forced a false logout on any stray 401.
        await api.post('/auth/refresh', {});
        // Retry the original request
        return api(originalRequest);
      } catch (refreshError) {
        // Refresh failed, user is actually logged out.
        // Clear local session state directly (not via authService) to avoid a
        // circular import between api.ts and authService.ts.
        sessionStorage.removeItem('user');
        authRepository.clearAuth();
        if (window.location.pathname !== '/login') window.location.href = '/login';
        return Promise.reject(refreshError);
      }
    }
    return Promise.reject(error);
  }
);

export default api;
