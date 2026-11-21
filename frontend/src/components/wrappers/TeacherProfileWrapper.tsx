import { useParams, useNavigate } from 'react-router';
import TeacherProfilePage from '../TeacherProfilePage';
import LoadingScreen from '../UI/LoadingScreen';
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

  // The list arrives asynchronously (App fetches it on /teacher/* too), so an
  // empty list means "still loading", not "no such teacher".
  if (!matchedTeacher && teachersList.length === 0) {
    return <LoadingScreen fullScreen={false} />;
  }

  if (!matchedTeacher) {
    return (
      <div className="mx-auto max-w-[560px] px-5 py-24 text-center">
        <h1 className="m-0 mb-3 text-2xl font-semibold">مش لاقيين المدرس ده</h1>
        <p className="m-0 mb-8 text-base text-slate-500">ممكن يكون الرابط قديم أو المدرس مبقاش على المنصة</p>
        <button type="button" onClick={() => navigate('/teachers')} className="btn-primary h-11 px-6 text-[15px]">
          شوف كل المدرسين
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
