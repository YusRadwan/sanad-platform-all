import { Course } from './course';

export interface Subscription {
  id: string;
  student_id: string;
  course_id: string;
  status: 'active' | 'expired' | 'cancelled';
  expires_at?: string;
  created_at: string;
  studentName?: string;
  courseName?: string;
  teacherName?: string;
  isActive?: boolean;
}

export interface SubscriptionsManagementProps {
  courses?: Course[];
  username?: string;
}

// Revenue / Payouts domain — shared across Admin and Instructor revenue screens
export interface RevenueTeacherSummary {
  teacher_id: string;
  name: string;
  /** Real admin-uploaded photo — falls back to a placeholder when absent. */
  avatar_url?: string | null;
  profit_percentage?: string | number;
  available_balance?: string | number;
}

export interface RevenueTransaction {
  id: string;
  created_at: string;
  gross_amount: string | number;
  payment_method: string;
  status: string;
  student_name: string;
  course_name: string;
  teacher_name?: string;
  teacher_profit_percentage?: string | number;
  teacher_earnings?: string | number;
}

export interface RevenuePayout {
  id: string;
  created_at: string;
  amount: string | number;
  payment_method: string;
  notes: string;
  created_by_name?: string;
}

export interface InstructorStatementSummary {
  total_earnings: string | number;
  total_paid_out: string | number;
  balance: string | number;
  profit_percentage: string | number;
  transactions_count: string | number;
}

/** Wire shape is App\Actions\Revenue\PlatformSummary — six keys, not the four this used to declare. */
export interface PlatformRevenueSummary {
  gross_revenue: string | number;
  teacher_earnings_total: string | number;
  platform_share: string | number;
  transactions_count: string | number;
  payouts_total: string | number;
  refunds_total: string | number;
}
