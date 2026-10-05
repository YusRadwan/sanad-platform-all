import { Course } from './course';

/** How the four MCQ options are labelled when rendered. Presentation only. */
export type QuestionChoiceFormat = '1234' | 'abcd' | 'arabic';

export interface Question {
  id: string;
  group_id?: string;
  groupId?: string;
  /** Short label distinct from the full prompt (`question_text`/`text`). */
  title?: string | null;
  question_text?: string;
  text?: string;
  question_type?: 'multiple_choice' | 'true_false' | 'essay' | 'text' | 'image';
  type?: 'multiple_choice' | 'true_false' | 'essay' | 'text' | 'image';
  options?: string[];
  choices?: string[];
  /** '1234' | 'abcd' | 'arabic' — how `options`/`choices` are labelled. */
  choice_format?: QuestionChoiceFormat;
  choiceFormat?: QuestionChoiceFormat;
  correct_answer?: string | number | boolean;
  correctAnswer?: string | number | boolean;
  explanation?: string;
  points?: number;
  image?: string | null;
  grade?: string;
}

export interface QuestionGroup {
  id: string;
  title: string;
  name?: string;
  grade?: string;
  description?: string;
  questions?: Question[];
  questions_count?: number;
}

export interface ExamAssignment {
  id: string;
  course_id?: string;
  courseId: string;
  groupId: string;
  questionGroupId?: string;
  grade?: string;
  title: string;
  description?: string;
  type: 'exam';
  time_limit?: number;
  duration?: number;
  totalScore?: number;
  passing_score?: number;
  passingScore?: number;
  is_published?: boolean;
  isPublished?: boolean;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
  allowedAttempts?: number;
  lockContent?: boolean;
  showAnswers?: boolean;
  randomizeQuestions?: boolean;
}

export interface QuestionBankProps {
  username: string;
}

export interface ExamsAssignmentsManagementProps {
  courses: Course[];
  username: string;
}
