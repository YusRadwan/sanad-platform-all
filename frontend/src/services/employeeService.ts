import api from './api';
import { logger } from './logger';

/**
 * "إدارة الأدمنز" (decisions 10.37 + 10.40) — CRUD for the calling user's
 * own employees/amins, whether the caller is a teacher (10.37) or an admin
 * (10.40) — the `/employees` route family and its underlying ownership are
 * resolved server-side from the session (`owner_id`), so this one client is
 * shared by both `Instructor/AdminManagement.tsx` and
 * `Admin/ManageEmployees.tsx` with zero owner-kind branching. Every call
 * mirrors the backend's {success, message, data} envelope, same convention
 * as centerService.ts.
 */

export interface EmployeeRow {
  id: string;
  name: string | null;
  phone: string | null;
  accountType: 'amin' | 'employee';
  permissions: string[];
  isActive: boolean;
  createdAt: string | null;
}

export interface EmployeePayload {
  name?: string;
  phone?: string;
  password?: string;
  accountType?: 'amin' | 'employee';
  permissions?: string[];
  isActive?: boolean;
}

export const employeeService = {
  async list(): Promise<EmployeeRow[]> {
    try {
      const response = await api.get('/employees');
      return response.data.data.employees ?? [];
    } catch (error) {
      logger.error('employeeService.list failed', error as Error);
      throw error;
    }
  },

  async create(data: EmployeePayload): Promise<EmployeeRow> {
    try {
      const response = await api.post('/employees', data);
      return response.data.data;
    } catch (error) {
      logger.error('employeeService.create failed', error as Error);
      throw error;
    }
  },

  async update(id: string, data: EmployeePayload): Promise<EmployeeRow> {
    try {
      const response = await api.put(`/employees/${id}`, data);
      return response.data.data;
    } catch (error) {
      logger.error(`employeeService.update(${id}) failed`, error as Error);
      throw error;
    }
  },

  async remove(id: string): Promise<void> {
    try {
      await api.delete(`/employees/${id}`);
    } catch (error) {
      logger.error(`employeeService.remove(${id}) failed`, error as Error);
      throw error;
    }
  },
};
