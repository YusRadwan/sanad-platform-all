import api from './api';
import { logger } from './logger';
import { AVATAR_PLACEHOLDER, COURSE_IMAGE_PLACEHOLDER } from '../utils/imagePlaceholders';

/** Shared in-flight GET /courses/my-courses promise — see getMyCourses(). */
let inFlightMyCourses: Promise<any[]> | null = null;

/**
 * Same field mapping `getAllCourses` applies to every catalogue item
 * (`thumbnail_url` -> `image`, flattened teacher name/avatar, ...) — shared
 * so a course fetched individually (`getCourseById`) renders identically to
 * one that came from the paginated listing, instead of a raw resource shape
 * missing `image`/`teacher.name`/etc.
 */
function mapCourse(course: any) {
  return {
    ...course,
    image: course.thumbnail_url || course.image || COURSE_IMAGE_PLACEHOLDER,
    grade: course.grade_name || course.grade,
    subject: course.subject_name || course.subject,
    discountPrice: course.discount_price || course.discountPrice,
    teacher: {
      name: course.teacher_name || (course.teacher && course.teacher.name) || 'غير محدد',
      image: course.teacher_avatar || (course.teacher && course.teacher.image) || AVATAR_PLACEHOLDER
    }
  };
}

export const courseService = {
  async getAllCourses(page = 1, limit = 12) {
    try {
      const response = await api.get('/courses', {
        params: { page, per_page: limit }
      });
      // Backend envelope: { success, data: { items, total, page, per_page } } — see CourseService::list.
      const { items = [], total = 0, page: currentPage = 1, per_page: currentLimit = 12 } = response.data.data;
      const hasMore = currentPage * currentLimit < total;

      const mappedCourses = items.map(mapCourse);

      return {
        courses: mappedCourses,
        total,
        page: currentPage,
        limit: currentLimit,
        hasMore
      };
    } catch (error) {
      if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) logger.error('Error fetching courses:', error as Error);
      return {
        courses: [],
        total: 0,
        page: 1,
        limit: 12,
        hasMore: false
      };
    }
  },

  async getCourseById(id: string) {
    try {
      const response = await api.get(`/courses/${id}`);
      // CourseService::find returns the course object directly under data (no "course" wrapper key).
      return mapCourse(response.data.data);
    } catch (error) {
      if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) logger.error(`Error fetching course ${id}:`, error as Error);
      return null;
    }
  },

  /**
   * The catalogue renders one <CourseCard> per course and EVERY card calls
   * this on mount to answer "is the logged-in student enrolled in me?", so a
   * 12-course page fired 12 identical GET /courses/my-courses requests in the
   * same tick.
   *
   * `inFlightMyCourses` collapses those into one real request: concurrent
   * callers await the same promise, and the slot is cleared as soon as it
   * settles. This is in-flight de-duplication only, NOT a result cache —
   * nothing is ever served from a previous render, so a later call (after an
   * enrolment change, login, or navigation) still hits the network and sees
   * fresh data. Behaviour and return value are unchanged for every caller.
   */
  async getMyCourses() {
    if (inFlightMyCourses) {
      return inFlightMyCourses;
    }
    inFlightMyCourses = courseService.fetchMyCourses().finally(() => {
      inFlightMyCourses = null;
    });
    return inFlightMyCourses;
  },

  async fetchMyCourses() {
    try {
      const response = await api.get('/courses/my-courses');
      const courses = response.data.data.courses || [];
      return courses.map((course: any) => ({
        ...course,
        image: course.thumbnail_url || course.image || COURSE_IMAGE_PLACEHOLDER,
        grade: course.grade_name || course.grade,
        subject: course.subject_name || course.subject,
        discountPrice: course.discount_price || course.discountPrice,
        teacher: {
          name: course.teacher_name || (course.teacher && course.teacher.name) || 'غير محدد',
          image: course.teacher_avatar || (course.teacher && course.teacher.image) || AVATAR_PLACEHOLDER
        }
      }));
    } catch (error) {
      if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) logger.error('Error fetching my courses:', error as Error);
      return [];
    }
  },

  /**
   * Course-wide video listing, backing CourseDetailsPage.tsx's globalVideos.
   * Wraps GET /courses/{courseId}/videos (ContentItemController::indexVideosByCourse
   * / ContentItemService::listVideosByCourse), which aggregates video
   * content-items across all of the course's sections AND groups — this
   * page has no single group/section context to scope a request to like
   * VideoManagement.tsx's group-scoped videoApiService.listByGroup does.
   */
  async getCourseVideos(courseId: string) {
    try {
      const response = await api.get(`/courses/${courseId}/videos`);
      return response.data.data || [];
    } catch (error) {
      if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) logger.error(`courseService.getCourseVideos(${courseId}) failed`, error as Error);
      return [];
    }
  },

  /**
   * Returns `{ sections, groups }` — two independent organisational layers a
   * course can have (open decision 10.21 added the `groups` key; it used to
   * be `sections`-only). Callers that only render the section tree (e.g. the
   * instructor's section-reorder page) should destructure just `sections`;
   * `CourseDetailsPage.tsx` reads both and merges them for display.
   */
  async getCourseStructure(courseId: string) {
    try {
      const response = await api.get(`/courses/${courseId}/structure`);
      return {
        sections: response.data.data.sections || [],
        groups: response.data.data.groups || [],
      };
    } catch (error) {
      if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) logger.error(`Error fetching structure for course ${courseId}:`, error as Error);
      return { sections: [], groups: [] };
    }
  },

  async getLessonVideo(lessonId: string) {
    try {
      const response = await api.get(`/lessons/${lessonId}/video`);
      return response.data.data.video;
    } catch (error) {
      logger.error(`courseService.getLessonVideo(${lessonId}) failed`, error as Error);
      throw error;
    }
  },

  /**
   * Resolves the real target URL of a 'link' content item, fetched on demand
   * via GET /content-items/{id} (ContentItemResource's `detail.url`) — the
   * course structure listing this item came from never carries it (see
   * CourseStructureResource's banner: no `detail` key at all).
   */
  async getContentItemLinkUrl(contentItemId: string): Promise<string | null> {
    try {
      const response = await api.get(`/content-items/${contentItemId}`);
      return response.data.data?.detail?.url ?? null;
    } catch (error) {
      logger.error(`courseService.getContentItemLinkUrl(${contentItemId}) failed`, error as Error);
      throw error;
    }
  },

  async getLessonProgress(lessonId: string) {
    try {
      const response = await api.get(`/lessons/${lessonId}/progress`);
      return response.data.data.progress;
    } catch (error) {
      logger.error(`courseService.getLessonProgress(${lessonId}) failed`, error as Error);
      throw error;
    }
  },

  async saveLessonProgress(lessonId: string, lastPosition: number, watchedPercentage: number) {
    try {
      const response = await api.post(`/lessons/${lessonId}/progress`, {
        lastPosition,
        watchedPercentage
      });
      return response.data.data.progress;
    } catch (error) {
      logger.error(`courseService.saveLessonProgress(${lessonId}) failed`, error as Error);
      throw error;
    }
  },

  async getExams(courseId?: string) {
    if (!courseId) return [];
    try {
      const response = await api.get(`/courses/${courseId}/exams`);
      return response.data.data.exams;
    } catch (error) {
      logger.error(`courseService.getExams(${courseId}) failed`, error as Error);
      throw error;
    }
  },

  async getExamDetails(examId: string) {
    try {
      const response = await api.get(`/exams/${examId}`);
      return response.data.data.exam;
    } catch (error) {
      logger.error(`courseService.getExamDetails(${examId}) failed`, error as Error);
      throw error;
    }
  },

  async getExamQuestions(examId: string) {
    try {
      const response = await api.get(`/exams/${examId}/questions`);
      const questions = response.data.data.questions || [];
      // Backend (QuestionResource) ships `body`/`options`/`image_url` —
      // ExamPage.tsx's `Question` shape reads `text`/`choices`/`image`.
      // Nothing mapped these before, so `q.choices` was always undefined
      // and rendering the very first question crashed the whole exam page
      // the instant a student clicked "ابدأ الاختبار الآن" — the exam-taking
      // flow was broken for every real (non-empty) question set.
      return questions.map((q: any) => ({
        id: q.id,
        text: q.body ?? q.text,
        image: q.image_url ?? q.image,
        choices: q.options ?? q.choices ?? [],
        correctAnswer: q.correct_answer_index ?? q.correctAnswer,
      }));
    } catch (error) {
      logger.error(`courseService.getExamQuestions(${examId}) failed`, error as Error);
      throw error;
    }
  },

  async submitExam(examId: string, answers: any[]) {
    try {
      const response = await api.post(`/exams/${examId}/submit`, { answers });
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.submitExam(${examId}) failed`, error as Error);
      throw error;
    }
  },

  async getExamResults(examId: string) {
    try {
      const response = await api.get(`/exams/${examId}/results`);
      return response.data.data.result;
    } catch (error) {
      logger.error(`courseService.getExamResults(${examId}) failed`, error as Error);
      throw error;
    }
  },

  async getMyEnrolledCourses() {
    try {
      const response = await api.get('/codes/student-courses');
      return response.data.data.courses;
    } catch (error) {
      logger.error('courseService.getMyEnrolledCourses failed', error as Error);
      throw error;
    }
  },

  async createCourse(courseData: any) {
    try {
      const response = await api.post('/courses', courseData);
      // CourseController::store -> ApiResponse::created(present($course)) — object directly under data.
      return response.data.data;
    } catch (error) {
      logger.error('courseService.createCourse failed', error as Error);
      throw error;
    }
  },

  async updateCourse(id: string, courseData: any) {
    try {
      // Backend route is PUT /courses/{id} (routes/api.php), not PATCH.
      const response = await api.put(`/courses/${id}`, courseData);
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.updateCourse(${id}) failed`, error as Error);
      throw error;
    }
  },

  async deleteCourse(id: string) {
    try {
      const response = await api.delete(`/courses/${id}`);
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.deleteCourse(${id}) failed`, error as Error);
      throw error;
    }
  },

  async reorderSections(courseId: string, orderedIds: string[]) {
    try {
      const response = await api.patch(`/courses/${courseId}/sections/reorder`, { order: orderedIds });
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.reorderSections(${courseId}) failed`, error as Error);
      throw error;
    }
  },

  async updateSectionTitle(sectionId: string, title: string) {
    try {
      const response = await api.put(`/sections/${sectionId}`, { title });
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.updateSectionTitle(${sectionId}) failed`, error as Error);
      throw error;
    }
  },

  async reorderSectionItems(sectionId: string, orderedIds: string[]) {
    try {
      const response = await api.patch(`/sections/${sectionId}/content-items/reorder`, { order: orderedIds });
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.reorderSectionItems(${sectionId}) failed`, error as Error);
      throw error;
    }
  },

  async updateContentItemVisibility(itemId: string, isHidden: boolean, orderIndex?: number) {
    try {
      const body: any = { is_hidden: isHidden };
      if (orderIndex !== undefined) body.order_index = orderIndex;
      const response = await api.put(`/content-items/${itemId}`, body);
      return response.data.data;
    } catch (error) {
      logger.error(`courseService.updateContentItemVisibility(${itemId}) failed`, error as Error);
      throw error;
    }
  },
};