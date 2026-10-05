import { User } from './user';
import { Course } from './course';

export interface Student extends User {
  student_id: string;
  enrollments_count?: number;
  status: string;
  governorate?: string;
  phone?: string;
  parentPhone?: string;
  name: string;
  studentCode: string;
  gradeLevel: string;
  educationalStage: string;
  course_titles?: string | null;
  courses_count?: number;
  subscribed_at?: string | null;
}

export interface MyCoursesProps {
  courses?: Course[];
  username?: string;
}
