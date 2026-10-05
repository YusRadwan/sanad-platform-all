import { authRepository } from '../repositories/authRepository';
import api from './api';
import { logger } from './logger';
import type { RegisterPayload, RegisterResult } from '../types/auth';

export const authService = {
  ...authRepository,

  async login(username: string, password: string): Promise<any> {
    try {
      const response = await api.post('/auth/login', { username, password });
      const { data } = response.data; // data here is just { user }

      // Store non-sensitive user info in session storage
      sessionStorage.setItem('user', JSON.stringify(data.user));

      // Update repository state
      authRepository.setIsLoggedIn(true);
      authRepository.setUsername(data.user.username);
      authRepository.setRole(data.user.role);

      return data;
    } catch (error) {
      logger.error('authService.login failed', error as Error);
      throw error;
    }
  },

  /**
   * POST /auth/register — public (rate-limited, CSRF-protected).
   *
   * Shared by SignupPage.tsx and Admin/AddStudent.tsx; see RegisterPayload for
   * why the two callers legitimately send different field sets.
   */
  async register(studentData: RegisterPayload): Promise<RegisterResult> {
    try {
      const response = await api.post('/auth/register', studentData);
      const { data } = response.data;
      return data as RegisterResult;
    } catch (error) {
      logger.error('authService.register failed', error as Error);
      throw error;
    }
  },

  async logout() {
    try {
      await api.post('/auth/logout');
    } catch (error) {
      logger.error('authService.logout failed', error as Error);
    } finally {
      // Clear session storage
      sessionStorage.removeItem('user');
      authRepository.clearAuth();
    }
  },

  async getMe() {
    try {
      const response = await api.get('/auth/me');
      return response.data.data;
    } catch (error) {
      logger.error('authService.getMe failed', error as Error);
      throw error;
    }
  },

  async updateCredentials(data: any) {
    try {
      const response = await api.patch('/auth/update-credentials', data);
      return response.data;
    } catch (error) {
      logger.error('authService.updateCredentials failed', error as Error);
      throw error;
    }
  },

  /**
   * POST /auth/stop-impersonation — restores the admin session that started
   * an active impersonation (see adminService.impersonateStudent). Returns
   * the admin's own PublicUser payload so the caller can re-sync local state
   * without a second round trip.
   */
  async stopImpersonation() {
    try {
      const response = await api.post('/auth/stop-impersonation');
      const user = response.data.data.user;
      sessionStorage.setItem('user', JSON.stringify(user));
      return user;
    } catch (error) {
      logger.error('authService.stopImpersonation failed', error as Error);
      throw error;
    }
  },
};
