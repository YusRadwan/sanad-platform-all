import { useState, useEffect } from 'react';
import { useParams, useNavigate, useLocation } from 'react-router';
import CourseDetailsPage from '../CourseDetailsPage';
import { courseService } from '../../services/courseService';
import { Course } from '../../types/index';

interface CourseDetailsWrapperProps {
  coursesList: Course[];
  handleViewTeacherProfile: (id: string) => void;
}

export default function CourseDetailsWrapper({
  coursesList,
  handleViewTeacherProfile
}: CourseDetailsWrapperProps) {
  const { id } = useParams();
  const rawNavigate = useNavigate();
  const location = useLocation();

  const navigate = (to: string, options?: any) => {
    if (typeof to !== 'string' || String(to).includes('[object Promise]')) {
      console.error('BAD NAVIGATION TARGET:', to);
    }
    rawNavigate(to, options);
  };

  const listedCourse = coursesList.find((c: any) => c.id === id);

  // `coursesList` is the public homepage catalogue's first page — it is
  // never populated at all on routes like /student (MyCourses), and even on
  // '/'/'/courses' it only holds whatever page the visitor has scrolled to.
  // A student clicking into their OWN enrolled course from MyCourses (or
  // anyone opening a direct course link) previously saw "course not found"
  // any time the course wasn't in that incidental list — this fetches it
  // directly by id instead of giving up.
  const [fetchedCourse, setFetchedCourse] = useState<Course | null>(null);
  const [fetchAttempted, setFetchAttempted] = useState(false);

  useEffect(() => {
    if (listedCourse || !id) return;
    let cancelled = false;
    setFetchAttempted(false);
    courseService.getCourseById(id).then((course) => {
      if (!cancelled) {
        setFetchedCourse(course);
        setFetchAttempted(true);
      }
    });
    return () => { cancelled = true; };
  }, [id, listedCourse]);

  const matchedCourse = listedCourse || fetchedCourse;

  if (!matchedCourse) {
    if (!fetchAttempted) {
      return null;
    }
    return (
      <div className="py-32 text-center text-slate-900 dark:text-white">
        <h2 className="text-2xl font-black mb-4">عذراً، لم نتمكن من العثور على هذا الكورس!</h2>
        <button
          onClick={() => navigate('/')}
          className="px-6 py-3 bg-blue-600 text-white font-bold rounded-2xl shadow-lg shadow-blue-500/20 cursor-pointer"
        >
          العودة للصفحة الرئيسية
        </button>
      </div>
    );
  }

  return (
    <CourseDetailsPage
      course={matchedCourse}
      onBack={() => {
        const fromTeacher = new URLSearchParams(location.search).get('fromTeacher');
        if (fromTeacher) {
          handleViewTeacherProfile(fromTeacher);
        } else {
          navigate('/');
        }
      }}
    />
  );
}
