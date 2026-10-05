import { User } from './user';
import { Course } from './course';

export interface SocialLink { id?: string; platform: string; icon?: any; url: string; enabled?: boolean; order?: number; }
export interface SupportContact { id?: string; type: string; icon?: any; value: string; values?: string[]; enabled?: boolean; order?: number; }

export interface Teacher extends User {
  teacher_id: string;
  name: string;
  bio?: string;
  specialization: string;
  avatar_url?: string;
  banner_url?: string;
  subjects: string[];
  grades: string[];
  rating: number;
  is_verified: boolean;
  courses_count: number;
  socialLinks: SocialLink[];
  isActive: boolean;
  /**
   * Per-teacher WhatsApp support numbers an admin attaches from the "Add
   * Teacher" form — free-text "label: number" strings (e.g. "مسؤول المادة:
   * 01012345678"), not structured fields. Optional: older/legacy teacher
   * payloads may omit the key entirely.
   */
  whatsappNumbers?: string[];
  /**
   * `teachers.login_blocked` — a SECOND, INDEPENDENT admin switch, NOT another
   * spelling of `isActive`.
   *
   *   isActive === false     suspended: gone from the public directory, courses
   *                          off the catalogue, AND cannot sign in.
   *   loginBlocked === true  cannot sign in, and NOTHING ELSE — the profile and
   *                          the courses stay exactly as visible as before.
   *
   * Emitted only by the ADMIN resource (TeacherResource); no public/
   * unauthenticated payload carries it, which is why it is optional here — the
   * same `Teacher` shape is also filled from public responses.
   */
  loginBlocked?: boolean;
  /**
   * `teachers.center_enabled` — "زر تفعيل نظام السنتر", admin-only, and
   * INDEPENDENT of both `isActive` and `loginBlocked` above (same three-way
   * independence, enforced server-side in `UpdateTeacher`). `false` (or
   * absent) hides the "نظام السنتر" sidebar section and 403s every
   * `/center/*` route for this teacher — WITHOUT deleting any of their
   * center data, which reappears untouched the moment it is re-enabled.
   * Emitted only by the ADMIN resource (TeacherResource) and by the
   * session-scoped PublicUser payload for the logged-in instructor
   * themselves — optional here for the same reason as `loginBlocked`.
   */
  centerEnabled?: boolean;
}

export interface TeacherCardProps {
  teacher: Teacher;
  onViewProfile?: (id: string) => void;
}

export interface TeacherProfilePageProps {
  teacherId: string;
  teacher?: Teacher;
  courses?: Course[];
  onBack?: () => void;
  isLoggedIn?: boolean;
  onNavigate?: (id: string | number) => void;
  onViewCourse?: (id: string | number) => void;
}

export interface AddTeacherProps {
  onNavigateHome?: () => void;
  onLogout?: () => void;
  onAddTeacher?: (teacherData: any) => void;
  onUpdateTeacher?: (id: string, teacherData: any) => void;
}

export interface InstructorStatisticsProps {
  courses: Course[];
  username: string;
}
