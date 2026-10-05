import api from './api';
import { logger } from './logger';
import { QuestionGroup, Question, QuestionChoiceFormat } from '../types/index';

/**
 * Wraps the QuestionBank endpoints (backend QuestionBankController /
 * QuestionBankService, migration 0006 `question_groups`/`questions` +
 * migration 0010 `question_groups.grade` / `questions.image_url`):
 *   GET/POST      /question-groups
 *   PATCH/DELETE  /question-groups/{id}
 *   GET/POST      /question-groups/{groupId}/questions
 *   PATCH/DELETE  /questions/{id}
 * Used by QuestionGroupsManagement.tsx and QuestionBank.tsx. Mirrors the
 * style of examAssignmentApiService.ts / groupNoteApiService.ts.
 */

const toQuestionGroup = (g: any): QuestionGroup => ({
  id: String(g.id),
  title: g.title,
  name: g.title,
  grade: g.grade || undefined,
});

const toQuestion = (q: any): Question => ({
  id: String(q.id),
  group_id: String(q.question_group_id),
  groupId: String(q.question_group_id),
  title: q.title ?? null,
  question_text: q.body,
  text: q.body,
  question_type: q.type === 'mcq' ? 'multiple_choice' : q.type,
  type: q.image_url ? 'image' : 'text',
  options: q.options || undefined,
  choices: q.options || undefined,
  choice_format: q.choice_format || '1234',
  choiceFormat: q.choice_format || '1234',
  correct_answer: q.correct_answer,
  correctAnswer: q.options ? q.options.indexOf(q.correct_answer) : q.correct_answer,
  points: q.points !== undefined ? Number(q.points) : undefined,
  image: q.image_url || null,
});

export const questionBankApiService = {
  listGroups: async (): Promise<QuestionGroup[]> => {
    try {
      const response = await api.get('/question-groups');
      const groups = response.data.data.groups || [];
      return groups.map(toQuestionGroup);
    } catch (error) {
      logger.error('questionBankApiService.listGroups failed', error as Error);
      throw error;
    }
  },

  createGroup: async (title: string, grade?: string): Promise<QuestionGroup> => {
    try {
      const response = await api.post('/question-groups', { title, grade: grade || null });
      return toQuestionGroup(response.data.data.group);
    } catch (error) {
      logger.error('questionBankApiService.createGroup failed', error as Error);
      throw error;
    }
  },

  updateGroup: async (id: string, title: string, grade?: string): Promise<QuestionGroup> => {
    try {
      const response = await api.patch(`/question-groups/${id}`, { title, grade: grade || null });
      return toQuestionGroup(response.data.data.group);
    } catch (error) {
      logger.error('questionBankApiService.updateGroup failed', error as Error);
      throw error;
    }
  },

  deleteGroup: async (id: string): Promise<void> => {
    try {
      await api.delete(`/question-groups/${id}`);
    } catch (error) {
      logger.error('questionBankApiService.deleteGroup failed', error as Error);
      throw error;
    }
  },

  listQuestions: async (groupId: string): Promise<Question[]> => {
    try {
      const response = await api.get(`/question-groups/${groupId}/questions`);
      const questions = response.data.data.questions || [];
      return questions.map(toQuestion);
    } catch (error) {
      logger.error('questionBankApiService.listQuestions failed', error as Error);
      throw error;
    }
  },

  createQuestion: async (groupId: string, payload: {
    title?: string;
    body: string;
    type?: 'mcq' | 'true_false' | 'short_answer';
    options?: string[];
    choiceFormat?: QuestionChoiceFormat;
    correctAnswer?: string | number;
    points?: number;
    imageUrl?: string | null;
  }): Promise<Question> => {
    try {
      const response = await api.post(`/question-groups/${groupId}/questions`, {
        title: payload.title || null,
        body: payload.body,
        type: payload.type || 'mcq',
        options: payload.options,
        choice_format: payload.choiceFormat || '1234',
        correct_answer: payload.correctAnswer !== undefined ? String(payload.correctAnswer) : null,
        points: payload.points ?? 1,
        image_url: payload.imageUrl ?? null,
      });
      return toQuestion(response.data.data.question);
    } catch (error) {
      logger.error('questionBankApiService.createQuestion failed', error as Error);
      throw error;
    }
  },

  updateQuestion: async (id: string, payload: {
    title?: string;
    body?: string;
    type?: 'mcq' | 'true_false' | 'short_answer';
    options?: string[];
    choiceFormat?: QuestionChoiceFormat;
    correctAnswer?: string | number;
    points?: number;
    imageUrl?: string | null;
  }): Promise<Question> => {
    try {
      const response = await api.patch(`/questions/${id}`, {
        title: payload.title,
        body: payload.body,
        type: payload.type,
        options: payload.options,
        choice_format: payload.choiceFormat,
        correct_answer: payload.correctAnswer !== undefined ? String(payload.correctAnswer) : undefined,
        points: payload.points,
        image_url: payload.imageUrl,
      });
      return toQuestion(response.data.data.question);
    } catch (error) {
      logger.error('questionBankApiService.updateQuestion failed', error as Error);
      throw error;
    }
  },

  deleteQuestion: async (id: string): Promise<void> => {
    try {
      await api.delete(`/questions/${id}`);
    } catch (error) {
      logger.error('questionBankApiService.deleteQuestion failed', error as Error);
      throw error;
    }
  },
};
