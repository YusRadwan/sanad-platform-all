import { useState, useEffect, useCallback } from 'react';
import { teacherService } from '../services/teacherService';
import { adminService } from '../services/adminService';
import { Teacher } from '../types/index';

export const useTeachers = (autoFetch = false) => {
  const [teachersList, setTeachersList] = useState<Teacher[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchTeachers = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const teachers = await teacherService.getAllTeachers();
      setTeachersList(teachers);
    } catch (err) {
      console.error('Failed to fetch teachers:', err);
      setError('حدث خطأ أثناء تحميل المعلمين');
    } finally {
      setIsLoading(false);
    }
  }, []);

  const refreshTeachersAdmin = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const teachers = await adminService.getAllTeachers();
      setTeachersList(teachers);
    } catch (err) {
      console.error('Failed to fetch teachers as admin:', err);
      setError('حدث خطأ أثناء تحميل المعلمين');
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (autoFetch) {
      fetchTeachers();
    }
  }, [autoFetch, fetchTeachers]);

  return {
    teachersList,
    setTeachersList,
    isLoading,
    error,
    fetchTeachers,
    refreshTeachersAdmin
  };
};
