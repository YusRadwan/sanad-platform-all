import api from './api';
import { logger } from './logger';
import { ExamAssignment, QuestionGroup, Question } from '../types/index';

/**
 * Wraps the group-scoped content-item endpoints (type='exam')
 * used by ExamsAssignmentsManagement/index.tsx, mirroring the pattern used
 * by groupNoteApiService.ts for notes:
 *   GET/POST /groups/{groupId}/content-items
 *   PUT/DELETE /content-items/{id}
 * plus the QuestionBank endpoints:
 *   GET /question-groups
 *   GET /question-groups/{groupId}/questions
 *
 * Backend detail fields (see ContentItemService::present/saveTypeDetail):
 *   exam: question_group_id, duration_minutes, pass_score, total_score,
 *         allowed_attempts, randomize_questions, lock_content, show_answers
 */

const splitDateTime = (value?: string | null): { date?: string; time?: string } => {
  if (!value) return {};
  const [date, time] = String(value).split(/[T ]/);
  return { date, time: time ? time.slice(0, 5) : undefined };
};

const joinDateTime = (date?: string, time?: string): string | undefined => {
  if (!date) return undefined;
  return `${date}T${time || '00:00'}:00`;
};

/** Maps a raw content-item (type exam, with `detail`) to the ExamAssignment shape the component uses. */
export const toExamAssignment = (item: any): ExamAssignment => {
  const detail = item.detail || {};
  const start = splitDateTime(item.start_at);
  const end = splitDateTime(item.end_at);

  return {
    id: item.id,
    course_id: item.course_id,
    courseId: item.course_id,
    groupId: item.group_id,
    questionGroupId: detail.question_group_id || undefined,
    title: item.title,
    description: item.description || '',
    type: item.type,
    duration: detail.duration_minutes !== undefined && detail.duration_minutes !== null ? Number(detail.duration_minutes) : undefined,
    totalScore: detail.total_score !== undefined && detail.total_score !== null ? Number(detail.total_score) : undefined,
    passingScore: detail.pass_score !== undefined && detail.pass_score !== null ? Number(detail.pass_score) : undefined,
    startDate: start.date,
    startTime: start.time,
    endDate: end.date,
    endTime: end.time,
    allowedAttempts: detail.allowed_attempts !== undefined && detail.allowed_attempts !== null ? Number(detail.allowed_attempts) : undefined,
    lockContent: !!Number(detail.lock_content ?? 0),
    showAnswers: !!Number(detail.show_answers ?? 0),
    randomizeQuestions: !!Number(detail.randomize_questions ?? 0),
  };
};

interface ExamFormPayload {
  title: string;
  description?: string;
  questionGroupId: string;
  duration: number;
  totalScore: number;
  passingScore?: number;
  allowedAttempts?: number;
  lockContent: boolean;
  showAnswers: boolean;
  randomizeQuestions: boolean;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
}

const buildExamPayload = (p: ExamFormPayload) => ({
  type: 'exam',
  title: p.title,
  description: p.description || null,
  question_group_id: p.questionGroupId,
  duration_minutes: p.duration,
  total_score: p.totalScore,
  pass_score: p.passingScore ?? null,
  allowed_attempts: p.allowedAttempts ?? null,
  randomize_questions: p.randomizeQuestions ? 1 : 0,
  lock_content: p.lockContent ? 1 : 0,
  show_answers: p.showAnswers ? 1 : 0,
  start_at: joinDateTime(p.startDate, p.startTime) ?? null,
  end_at: joinDateTime(p.endDate, p.endTime) ?? null,
});

export const examAssignmentApiService = {
  listByGroup: async (groupId: string): Promise<any[]> => {
    try {
      const response = await api.get(`/groups/${groupId}/content-items`);
      // A group with zero content items comes back as `"data": {}`, not
      // `[]` — ApiResponse::normalise() converts any empty array to an
      // object — so `|| []` alone doesn't catch it (`{}` is truthy).
      return Array.isArray(response.data.data) ? response.data.data : [];
    } catch (error) {
      logger.error('examAssignmentApiService.listByGroup failed', error as Error);
      throw error;
    }
  },

  createExam: async (groupId: string, payload: ExamFormPayload) => {
    try {
      const response = await api.post(`/groups/${groupId}/content-items`, buildExamPayload(payload));
      return toExamAssignment(response.data.data);
    } catch (error) {
      logger.error('examAssignmentApiService.createExam failed', error as Error);
      throw error;
    }
  },

  updateExam: async (id: string, payload: ExamFormPayload) => {
    try {
      const response = await api.put(`/content-items/${id}`, buildExamPayload(payload));
      return toExamAssignment(response.data.data);
    } catch (error) {
      logger.error('examAssignmentApiService.updateExam failed', error as Error);
      throw error;
    }
  },

  deleteItem: async (id: string) => {
    try {
      await api.delete(`/content-items/${id}`);
    } catch (error) {
      logger.error('examAssignmentApiService.deleteItem failed', error as Error);
      throw error;
    }
  },

  getQuestionGroups: async (): Promise<QuestionGroup[]> => {
    try {
      const response = await api.get('/question-groups');
      const groups = response.data.data.groups || [];
      return groups.map((g: any) => ({
        id: String(g.id),
        title: g.title,
        name: g.title,
        grade: g.grade,
        description: g.description,
      }));
    } catch (error) {
      logger.error('examAssignmentApiService.getQuestionGroups failed', error as Error);
      throw error;
    }
  },

  getQuestionsForGroup: async (groupId: string): Promise<Question[]> => {
    if (!groupId) return [];
    try {
      const response = await api.get(`/question-groups/${groupId}/questions`);
      const questions = response.data.data.questions || [];
      return questions.map((q: any) => ({
        id: String(q.id),
        groupId: String(q.question_group_id),
        group_id: String(q.question_group_id),
        text: q.body,
        question_text: q.body,
        type: q.type,
        question_type: q.type,
        options: q.options,
        correctAnswer: q.correct_answer,
        correct_answer: q.correct_answer,
        points: q.points,
      }));
    } catch (error) {
      logger.error('examAssignmentApiService.getQuestionsForGroup failed', error as Error);
      throw error;
    }
  },
};
