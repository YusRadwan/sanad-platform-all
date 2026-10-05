import React from 'react';
import { Teacher } from './teacher';

export interface Announcement {
  id: string;
  course_id?: string;
  title: string;
  content: string;
  is_global: boolean;
  created_at: string;
}

export interface PlatformNotification {
  id: string;
  user_id: string;
  title: string;
  message: string;
  is_read: boolean;
  created_at: string;
  // Broadcast fields. Widened to allow null because the backend presenter
  // (BroadcastNotificationService::present) emits JSON null for the ones that
  // do not apply to a given audience, and JSON has no `undefined`.
  targetAudience?: string;
  isActive?: boolean;
  startDate?: string | null;
  endDate?: string | null;
  selectedTeachers?: string[] | null;
  specificUser?: string | null;
  content?: string;
  url?: string | null;
}

// Component Props Interfaces
export interface AdminSystemProps {
  username?: string;
  onBackToHome?: () => void;
  onLogout?: () => void;
}

export interface InstructorSystemProps {
  username: string;
  onBackToHome?: () => void;
  onLogout?: () => void;
}

export interface SidebarProps {
  isOpen: boolean;
  onClose: () => void;
  userRole: 'student' | 'instructor' | 'admin';
  username: string;
  headerContent?: React.ReactNode;
  children?: React.ReactNode;
  overlayClassName?: string;
  useAnimation?: boolean;
  /**
   * Opt-in: from xl up, "closed" collapses the sidebar to a 5rem icon-only
   * rail that stays in normal flow, instead of translating it off-screen.
   * Below xl the behaviour is the unchanged overlay drawer. See Sidebar.tsx.
   */
  railMode?: boolean;
}

export interface HeroSectionProps {
  onNavigate?: (id: string | number) => void;
  isLoggedIn?: boolean;
}
export interface TeachersSectionProps {
  teachersList?: Teacher[];
  onViewProfile?: (id: string) => void;
}

export interface PlatformLogoProps {
  className?: string;
}
