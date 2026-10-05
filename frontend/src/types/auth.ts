export interface LoginPageProps {
  onBackToHome?: () => void;
  onNavigateToSignup?: () => void;
  onLoginSuccess?: (role: string, name: string) => void;
  onNavigateToStudent?: () => void;
  onNavigateToInstructor?: () => void;
  onNavigateToAdmin?: () => void;
}

export interface SignupPageProps {
  onBackToHome?: () => void;
  onNavigateToLogin?: () => void;
}

/**
 * The exact body POST /api/v1/auth/register accepts (backend
 * AuthService::register). Replaces the `studentData: any` that
 * authService.register() used to take.
 *
 * Both SignupPage.tsx and Admin/AddStudent.tsx send this same shape: plain
 * LOCAL phone digits (`01012345678`, no `+`, no country code), a free-text
 * governorate string and a free-text grade name — the platform serves Egypt
 * only, so there is no country/curriculum selection anymore.
 */
export interface RegisterPayload {
  fullName: string;
  /** 'ذكر' | 'أنثى' — optional server-side, so a caller may still omit it. */
  gender?: string;
  governorate: string;
  educationalStage?: string;
  eduType?: string;
  gradeLevel: string;
  /** Plain local digits, e.g. `01012345678`. */
  studentPhone: string;
  parentPhone: string;
  password: string;
}

/** What the register endpoint answers with inside the `data` envelope. */
export interface RegisterResult {
  studentCode: string;
  userId: string;
}

export interface LoginSession {
  id: string;
  device: string;
  lastActive: string;
  lastActivity?: string;
  status?: 'Active' | 'Logged Out' | 'Session Expired' | 'Blocked' | 'Suspicious Activity' | string;
  country?: string;
  city?: string;
  os?: string;
  browser?: string;
  deviceType?: string;
  loginDate?: string;
  userName?: string;
  userId?: string | number;
  userPhone?: string;
  userEmail?: string;
  ipAddress?: string;
  userType?: string;
  sessionDuration?: string;
}
