import { useState, useCallback, useRef } from 'react';
import { courseService } from '../services/courseService';
import { Course } from '../types/index';

export const useCourses = (initialLimit = 12) => {
  const [coursesList, setCoursesList] = useState<Course[]>([]);
  const [pagination, setPagination] = useState({
    page: 1,
    limit: initialLimit,
    total: 0,
    hasMore: false,
    isLoading: false
  });
  const [error, setError] = useState<string | null>(null);
  const loadingRef = useRef(false);

  const fetchCourses = useCallback(async (page = 1, append = false) => {
    if (loadingRef.current) return;
    
    loadingRef.current = true;
    setPagination(prev => ({ ...prev, isLoading: true }));
    setError(null);
    try {
      const result = await courseService.getAllCourses(page, pagination.limit);
      
      if (append) {
        setCoursesList(prev => [...prev, ...result.courses]);
      } else {
        setCoursesList(result.courses);
      }
      
      setPagination({
        page: result.page,
        limit: result.limit,
        total: result.total,
        hasMore: result.hasMore,
        isLoading: false
      });
    } catch (err) {
      console.error('Failed to fetch courses:', err);
      setError('حدث خطأ أثناء تحميل الكورسات');
      setPagination(prev => ({ ...prev, isLoading: false }));
    } finally {
      loadingRef.current = false;
    }
  }, [pagination.limit]);

  const addCourse = useCallback(async (newCourse: Partial<Course>) => {
    try {
      await courseService.createCourse({
        title: newCourse.title,
        description: newCourse.description,
        thumbnail_url: newCourse.image,
        price: newCourse.price,
        discount_price: newCourse.discountPrice,
        is_published: true,
        grade_name: newCourse.grade,
        subject_name: newCourse.subject,
        visibility: newCourse.visibility
      });
      await fetchCourses(1, false);
    } catch (err) {
      console.error('Failed to create course:', err);
      throw err;
    }
  }, [fetchCourses]);

  const editCourse = useCallback(async (updatedCourse: Course) => {
    try {
      await courseService.updateCourse(String(updatedCourse.id), {
        title: updatedCourse.title,
        description: updatedCourse.description,
        thumbnail_url: updatedCourse.image,
        price: updatedCourse.price,
        discount_price: updatedCourse.discountPrice,
        grade_name: updatedCourse.grade,
        subject_name: updatedCourse.subject,
        visibility: updatedCourse.visibility
      });
      await fetchCourses(1, false);
    } catch (err) {
      console.error('Failed to update course:', err);
      throw err;
    }
  }, [fetchCourses]);

  const deleteCourse = useCallback(async (id: string | number) => {
    try {
      await courseService.deleteCourse(String(id));
      setCoursesList(prev => prev.filter(c => c.id !== id));
    } catch (err) {
      console.error('Failed to delete course:', err);
      throw err;
    }
  }, []);

  const loadMore = useCallback(() => {
    if (pagination.hasMore && !pagination.isLoading) {
      fetchCourses(pagination.page + 1, true);
    }
  }, [pagination.hasMore, pagination.isLoading, pagination.page, fetchCourses]);

  const refreshCourses = useCallback(() => {
    fetchCourses(1, false);
  }, [fetchCourses]);

  return {
    coursesList,
    pagination,
    error,
    fetchCourses,
    loadMore,
    refreshCourses,
    setCoursesList,
    addCourse,
    editCourse,
    deleteCourse
  };
};
