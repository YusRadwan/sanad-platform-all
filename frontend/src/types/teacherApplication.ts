/**
 * "طلب مدرس جديد" — the public teacher-application form and its admin
 * listing. See open decision 10.23 / backend TeacherApplicationController.
 */

/** The exact body POST /teacher-applications accepts. */
export interface TeacherApplicationPayload {
  full_name: string;
  /** Plain local digits, e.g. `01012345678`. */
  phone: string;
  whatsapp: string;
  governorate: string;
  age: number;
  years_of_experience: number;
  subject: string;
  section_name: string;
  grades: string[];
  social_links: string[];
}

/** What both POST /teacher-applications and GET /admin/teacher-applications return per row. */
export interface TeacherApplication extends TeacherApplicationPayload {
  id: string;
  created_at: string;
}

export interface TeacherApplicationPageProps {
  onBackToHome?: () => void;
}
