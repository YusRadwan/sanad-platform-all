export interface User {
  id: string;
  username: string;
  email: string;
  full_name: string;
  fullName?: string;
  role: 'student' | 'instructor' | 'admin';
  is_active: boolean;
  avatar_url?: string | null;
  created_at?: string;
  deleted_at?: string | null;
}
