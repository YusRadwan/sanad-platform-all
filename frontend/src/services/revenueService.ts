import api from './api';
import { ApiResponse } from '../types/index';
import { logger } from './logger';

/**
 * Unwraps the backend's single-key data envelope.
 *
 * Every /revenue/* endpoint answers
 * `{success, message, data: {<one key>: <payload>}}` — e.g.
 * `data: {transactions: [...]}` or `data: {summary: {...}}`. Every consumer
 * of this service, however, was written as `set…(res.data)` after reading
 * `res.success`, i.e. expecting `data` to BE the array/object. The result was
 * that Revenue.tsx, ProfitEngine.tsx, TeacherPayouts.tsx,
 * InstructorStatement.tsx and RefundManagement.tsx each handed a wrapper
 * OBJECT to state typed as an array, so every one of those tables rendered
 * nothing (`.map` on a non-array) despite the request succeeding.
 *
 * Unwrapping here fixes all five pages in one place and leaves the backend
 * contract — which the instructor-facing pages share — untouched. The
 * `expected` key is named explicitly rather than inferred so a future
 * multi-key response cannot silently pick the wrong one.
 */
function unwrap(res: any, expected: string): ApiResponse {
  const body = res?.data ?? {};
  const inner = body?.data;
  const payload =
    inner && typeof inner === 'object' && !Array.isArray(inner) && expected in inner
      ? inner[expected]
      : inner;
  return { ...body, data: payload };
}

export const revenueService = {
  // Instructor Endpoints
  async getMyStatement(): Promise<ApiResponse> {
    try {
      const res = await api.get('/revenue/my-statement');
      return unwrap(res, 'statement');
    } catch (error) {
      logger.error('revenueService.getMyStatement failed', error as Error);
      throw error;
    }
  },

  async getMyTransactions(): Promise<ApiResponse> {
    try {
      const res = await api.get('/revenue/my-transactions');
      return unwrap(res, 'transactions');
    } catch (error) {
      logger.error('revenueService.getMyTransactions failed', error as Error);
      throw error;
    }
  },

  async getMyPayouts(): Promise<ApiResponse> {
    try {
      const res = await api.get('/revenue/my-payouts');
      return unwrap(res, 'payouts');
    } catch (error) {
      logger.error('revenueService.getMyPayouts failed', error as Error);
      throw error;
    }
  },

  // Admin Platform Stats
  async getPlatformRevenue(params?: string): Promise<ApiResponse> {
    try {
      const res = await api.get(params ? `/revenue/platform?${params}` : '/revenue/platform');
      return unwrap(res, 'summary');
    } catch (error) {
      logger.error('revenueService.getPlatformRevenue failed', error as Error);
      throw error;
    }
  },

  async getTransactions(params?: string): Promise<ApiResponse> {
    try {
      const res = await api.get(params ? `/revenue/transactions?${params}` : '/revenue/transactions');
      return unwrap(res, 'transactions');
    } catch (error) {
      logger.error('revenueService.getTransactions failed', error as Error);
      throw error;
    }
  },

  // Refunds
  async getRefunds(status?: string): Promise<ApiResponse> {
    try {
      const url = status ? `/revenue/refunds?status=${status}` : '/revenue/refunds';
      const res = await api.get(url);
      return unwrap(res, 'refunds');
    } catch (error) {
      logger.error('revenueService.getRefunds failed', error as Error);
      throw error;
    }
  },

  async processRefund(id: string | number, status: 'approved' | 'rejected'): Promise<ApiResponse> {
    try {
      const res = await api.post('/revenue/refunds', { id, status });
      return unwrap(res, 'refund');
    } catch (error) {
      logger.error('revenueService.processRefund failed', error as Error);
      throw error;
    }
  },

  async requestRefund(transactionId: string | number, reason: string): Promise<ApiResponse> {
    try {
      const res = await api.post('/revenue/refunds', { transactionId, reason });
      return unwrap(res, 'refund');
    } catch (error) {
      logger.error('revenueService.requestRefund failed', error as Error);
      throw error;
    }
  },

  // Teacher payout / percentage / stats management
  async getRevenueTeachers(): Promise<ApiResponse> {
    try {
      const res = await api.get('/revenue/teachers');
      return unwrap(res, 'teachers');
    } catch (error) {
      logger.error('revenueService.getRevenueTeachers failed', error as Error);
      throw error;
    }
  },

  async getTeacherStatement(teacherId: string | number): Promise<ApiResponse> {
    try {
      const res = await api.get(`/revenue/statement/${teacherId}`);
      return unwrap(res, 'statement');
    } catch (error) {
      logger.error('revenueService.getTeacherStatement failed', error as Error);
      throw error;
    }
  },

  async getTeacherTransactions(teacherId: string | number): Promise<ApiResponse> {
    try {
      const res = await api.get(`/revenue/transactions/${teacherId}`);
      return unwrap(res, 'transactions');
    } catch (error) {
      logger.error('revenueService.getTeacherTransactions failed', error as Error);
      throw error;
    }
  },

  async getTeacherPayouts(teacherId: string | number): Promise<ApiResponse> {
    try {
      const res = await api.get(`/revenue/payouts/${teacherId}`);
      return unwrap(res, 'payouts');
    } catch (error) {
      logger.error('revenueService.getTeacherPayouts failed', error as Error);
      throw error;
    }
  },

  async updateTeacherPercentage(teacherId: string | number, percentage: number): Promise<ApiResponse> {
    try {
      const res = await api.put(`/revenue/teachers/${teacherId}/percentage`, { percentage });
      // RevenueController::updateTeacherPercentage answers
      // `data: {result: {...}}`, not `{teacher: ...}`. With the wrong key the
      // unwrap fell back to returning the `{result: …}` wrapper itself.
      return unwrap(res, 'result');
    } catch (error) {
      logger.error('revenueService.updateTeacherPercentage failed', error as Error);
      throw error;
    }
  },

  async createPayout(teacherId: string | number, amount: number, paymentMethod?: string, note?: string): Promise<ApiResponse> {
    try {
      const res = await api.post('/revenue/payouts', { teacherId, amount, paymentMethod, note });
      return unwrap(res, 'payout');
    } catch (error) {
      logger.error('revenueService.createPayout failed', error as Error);
      throw error;
    }
  }
};
