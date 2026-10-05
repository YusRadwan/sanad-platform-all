import { useParams, useNavigate } from 'react-router';
import TeacherProfilePage from '../TeacherProfilePage';
import { Teacher, Course } from '../../types/index';

interface TeacherProfileWrapperProps {
  teachersList: Teacher[];
  coursesList: Course[];
  isLoggedIn: boolean;
  navigateTo: (view: any) => void;
  handleViewCourseDetails: (id: string | number, teacherId?: string) => void;
}

export default function TeacherProfileWrapper({ 
  teachersList, 
  coursesList, 
  isLoggedIn, 
  navigateTo, 
  handleViewCourseDetails 
}: TeacherProfileWrapperProps) {
  const { id } = useParams();
  const rawNavigate = useNavigate();
  
  const navigate = (to: string, options?: any) => {
    if (typeof to !== 'string' || String(to).includes('[object Promise]')) {
      console.error('BAD NAVIGATION TARGET:', to);
    }
    rawNavigate(to, options);
  };

  const matchedTeacher = teachersList.find((t: any) => t.id === id && t.isActive !== false);

  if (!matchedTeacher) {
    return (
      <div className="py-32 text-center text-slate-900 dark:text-white">
        <h2 className="text-2xl font-black mb-4">عذراً، لم نتمكن من العثور على هذا المعلم!</h2>
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
    <TeacherProfilePage
      teacherId={id || ''}
      teacher={matchedTeacher}
      courses={coursesList}
      isLoggedIn={isLoggedIn}
      onNavigate={navigateTo}
      onViewCourse={handleViewCourseDetails}
      onBack={() => navigate('/')}
    />
  );
}
