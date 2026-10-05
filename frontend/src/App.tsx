import { useEffect, useCallback, useState, lazy, Suspense } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import { Routes, Route, useNavigate, useLocation } from 'react-router';
import { Eye, LogOut } from 'lucide-react';
import LoadingScreen from './components/UI/LoadingScreen';
import Header from './components/Header';
import HeroSection from './components/HeroSection';
import FeaturesSection from './components/FeaturesSection';
import HowItWorksSection from './components/HowItWorksSection';
import TeachersSection from './components/TeachersSection';
import CoursesSection from './components/CoursesSection';
import Footer from './components/Footer';
const SignupPage = lazy(() => import('./components/SignupPage'));
const LoginPage = lazy(() => import('./components/LoginPage'));
const TeacherApplicationPage = lazy(() => import('./components/TeacherApplicationPage'));
const InstructorSystem = lazy(() => import('./components/InstructorSystem'));

// Lazy load wrappers
const TeacherProfileWrapper = lazy(() => import('./components/wrappers/TeacherProfileWrapper'));
const CourseDetailsWrapper = lazy(() => import('./components/wrappers/CourseDetailsWrapper'));

// Lazy load instructor components
const InstructorHome = lazy(() => import('./components/Instructor/InstructorHome'));
const InstructorRevenue = lazy(() => import('./components/Instructor/InstructorRevenue'));
const CourseManagement = lazy(() => import('./components/Instructor/CourseManagement'));
const CourseStructureManagement = lazy(() => import('./components/Instructor/CourseStructureManagement'));
const GroupManagement = lazy(() => import('./components/Instructor/GroupManagement'));
const NotesManagement = lazy(() => import('./components/Instructor/NotesManagement'));
const LinksManagement = lazy(() => import('./components/Instructor/LinksManagement'));
const QuestionGroupsManagement = lazy(() => import('./components/Instructor/QuestionGroupsManagement'));
const QuestionBank = lazy(() => import('./components/Instructor/QuestionBank'));
const ExamsAssignmentsManagement = lazy(() => import('./components/Instructor/ExamsAssignmentsManagement'));
const SubscriptionsManagement = lazy(() => import('./components/Instructor/SubscriptionsManagement'));
const VideoManagement = lazy(() => import('./components/Instructor/VideoManagement'));
const InstructorStatistics = lazy(() => import('./components/Instructor/InstructorStatistics'));
const CreateCenterGroup = lazy(() => import('./components/Instructor/CreateCenterGroup'));
const CenterAttendance = lazy(() => import('./components/Instructor/CenterAttendance'));
const CenterReports = lazy(() => import('./components/Instructor/CenterReports'));
const ActivateCenterCourse = lazy(() => import('./components/Instructor/ActivateCenterCourse'));
const CenterPaymentTracking = lazy(() => import('./components/Instructor/CenterPaymentTracking'));
const ExamStatistics = lazy(() => import('./components/Instructor/ExamStatistics'));
const WatchStatistics = lazy(() => import('./components/Instructor/WatchStatistics'));
const AdminManagement = lazy(() => import('./components/Instructor/AdminManagement'));

const AdminSystem = lazy(() => import('./components/AdminSystem'));

// Lazy load admin components
const AdminHome = lazy(() => import('./components/Admin/AdminHome'));
const AddTeacher = lazy(() => import('./components/Admin/AddTeacher'));
const ManageTeachers = lazy(() => import('./components/Admin/ManageTeachers'));
const ManageEmployees = lazy(() => import('./components/Admin/ManageEmployees'));
const BroadcastNotifications = lazy(() => import('./components/Admin/BroadcastNotifications'));
const ManageSubscriptions = lazy(() => import('./components/Admin/ManageSubscriptions'));
const EnrollmentCodes = lazy(() => import('./components/Admin/EnrollmentCodes'));
const EnrollmentCodeDetails = lazy(() => import('./components/Admin/EnrollmentCodeDetails'));
const FilterEnrollmentCodes = lazy(() => import('./components/Admin/FilterEnrollmentCodes'));
const StudentCodeDetails = lazy(() => import('./components/Admin/StudentCodeDetails'));
const ResetStudentPassword = lazy(() => import('./components/Admin/ResetStudentPassword'));
const ManageUsers = lazy(() => import('./components/Admin/ManageUsers'));
const EditStudent = lazy(() => import('./components/Admin/EditStudent'));
const AddStudent = lazy(() => import('./components/Admin/AddStudent'));
const Statistics = lazy(() => import('./components/Admin/Statistics'));
const LoginActivity = lazy(() => import('./components/Admin/LoginActivity'));
const PlatformSettings = lazy(() => import('./components/Admin/PlatformSettings'));
const ProfitEngine = lazy(() => import('./components/Admin/ProfitEngine'));
const TeacherApplications = lazy(() => import('./components/Admin/TeacherApplications'));
const Revenue = lazy(() => import('./components/Admin/Revenue'));
const InstructorStatement = lazy(() => import('./components/Admin/InstructorStatement'));
const TeacherPayouts = lazy(() => import('./components/Admin/TeacherPayouts'));
const RefundManagement = lazy(() => import('./components/Admin/RefundManagement'));
const GenerateCenterCodes = lazy(() => import('./components/Admin/GenerateCenterCodes'));
const CenterCodeDetails = lazy(() => import('./components/Admin/CenterCodeDetails'));

const MyCourses = lazy(() => import('./components/Student/MyCourses'));
const StudentStatistics = lazy(() => import('./components/Student/StudentStatistics'));
const StudentWatchedVideos = lazy(() => import('./components/Student/StudentWatchedVideos'));
const StudentCompletedExams = lazy(() => import('./components/Student/StudentCompletedExams'));
const CenterStudent = lazy(() => import('./components/Student/CenterStudent'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));
import JoinUsSection from './components/JoinUsSection';
const VideoPlayerPage = lazy(() => import('./components/VideoPlayerPage'));
const ExamPage = lazy(() => import('./components/ExamPage'));
const PrivacyPolicyPage = lazy(() => import('./pages/PrivacyPolicyPage'));
const TermsConditionsPage = lazy(() => import('./pages/TermsConditionsPage'));
import WhatsAppWidget from './components/WhatsAppWidget';
import ProtectedRoute from './components/ProtectedRoute';
import PublicHomeRoute from './components/PublicHomeRoute';
import { ToastProvider } from './context/ToastContext';
import { CenteredNotificationProvider } from './context/CenteredNotificationContext';
import { useAuth } from './hooks/useAuth';
import { useCourses } from './hooks/useCourses';
import { useTeachers } from './hooks/useTeachers';
import { courseService } from './services/courseService';

export default function App() {
  const navigate = useNavigate();
  const location = useLocation();
  
  const view = (() => {
    const path = location.pathname;
    if (path === '/login') return 'login';
    if (path === '/signup') return 'signup';
    if (path === '/teacher-application') return 'teacher-application';
    if (path.startsWith('/instructor')) return 'instructor';
    if (path.startsWith('/admin')) return 'admin';
    if (path.startsWith('/student')) return 'student';
    if (path.startsWith('/teacher/')) return 'teacher-profile';
    if (path.startsWith('/course/')) return 'course-details';
    return 'home';
  })();

  const {
    isLoggedIn,
    username,
    userRole,
    adminImpersonationUsername,
    handleLogin: handleLoginSuccess,
    handleLogout,
    stopImpersonation,
  } = useAuth();

  const handleRestoreAdmin = useCallback(() => {
    stopImpersonation().catch(() => {
      // Session already invalid server-side (e.g. impersonation expired) —
      // ProtectedRoute's own /auth/me check on the next navigation will
      // redirect appropriately; nothing further to do here.
    });
  }, [stopImpersonation]);

  const {
    coursesList,
    pagination: coursesPagination,
    fetchCourses,
    loadMore: handleLoadMoreCourses,
    addCourse,
    editCourse,
    deleteCourse
  } = useCourses(12);

  const {
    teachersList,
    fetchTeachers,
  } = useTeachers();

  // An instructor never renders the homepage (PublicHomeRoute redirects during
  // render), so fetching its teacher/course lists for them is two guaranteed-
  // wasted requests on every visit.
  const isInstructorSession = isLoggedIn && userRole === 'instructor';

  useEffect(() => {
    if (['/', '/teachers', '/courses'].includes(location.pathname) && !isInstructorSession) {
      fetchCourses(1, false);
      fetchTeachers();
    }
  }, [location.pathname, isInstructorSession, fetchCourses, fetchTeachers]);

  // `coursesList` above is the PUBLIC catalogue (GET /courses, every teacher's
  // published courses) and is never populated for an instructor session — the
  // effect right above this one explicitly skips them. CourseManagement,
  // GroupManagement, VideoManagement, NotesManagement and LinksManagement all
  // need the instructor's OWN courses instead (GET /courses/my-courses, same
  // endpoint CourseStructureManagement already uses independently), so they
  // get a separate, teacher-scoped list here rather than sharing the public one.
  const [myCoursesList, setMyCoursesList] = useState<any[]>([]);

  const refreshMyCourses = useCallback(async () => {
    const courses = await courseService.getMyCourses();
    setMyCoursesList(courses);
  }, []);

  useEffect(() => {
    if (isInstructorSession) {
      refreshMyCourses();
    } else {
      setMyCoursesList([]);
    }
  }, [isInstructorSession, refreshMyCourses]);

  // Instructor bounce. PublicHomeRoute is what actually keeps "/" off an
  // instructor's screen; this stays as the catch-all for every other
  // non-/instructor path they might reach programmatically, and as the fallback
  // for the case where the role only becomes known after AuthContext's
  // /auth/me sync has resolved.
  useEffect(() => {
    if (isInstructorSession && !location.pathname.startsWith('/instructor')) {
      navigate('/instructor', { replace: true });
    }
  }, [isInstructorSession, location.pathname, navigate]);

  const navigateTo = useCallback((newView: string) => {
    if (newView === 'home') navigate('/');
    else navigate(`/${newView}`);
    window.scrollTo({ top: 0, behavior: 'instant' });
  }, [navigate]);

  const handleViewTeacherProfile = useCallback((id: string) => {
    navigate(`/teacher/${id}`);
    window.scrollTo({ top: 0, behavior: 'instant' });
  }, [navigate]);

  const handleViewCourseDetails = useCallback((id: string | number, teacherId?: string) => {
    navigate(`/course/${id}${teacherId ? `?fromTeacher=${teacherId}` : ''}`);
    window.scrollTo({ top: 0, behavior: 'instant' });
  }, [navigate]);

  return (
    <ToastProvider>
    <CenteredNotificationProvider>
      <div className="min-h-screen bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-50 font-sans transition-colors duration-300">
      <AnimatePresence>
        {adminImpersonationUsername && (
          <motion.div
            initial={{ y: -48, opacity: 0 }}
            animate={{ y: 0, opacity: 1 }}
            exit={{ y: -48, opacity: 0 }}
            transition={{ duration: 0.3, ease: 'easeOut' }}
            className="sticky top-0 z-50 bg-slate-900 dark:bg-slate-950 text-white border-b border-white/10 shadow-lg"
            dir="rtl"
          >
            <div className="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-2.5 font-sans text-xs md:text-sm">
              <div className="flex items-center gap-2.5">
                <span className="shrink-0 w-7 h-7 rounded-lg bg-amber-500/15 text-amber-400 flex items-center justify-center">
                  <Eye className="w-3.5 h-3.5" />
                </span>
                <span className="font-bold text-slate-200">
                  وضع المراجعة — أنت تتصفح المنصة كحساب الطالب{' '}
                  <strong className="font-black text-white">{username}</strong>
                </span>
              </div>
              <button
                onClick={handleRestoreAdmin}
                className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/15 active:scale-[0.97] border border-white/10 text-white text-xs font-bold transition-all cursor-pointer leading-none"
              >
                <LogOut className="w-3.5 h-3.5" />
                <span>العودة إلى حساب الأدمن</span>
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
      {view !== 'instructor' && view !== 'admin' && (
        <Header 
          currentView={view as any} 
          onNavigate={navigateTo as any} 
          isLoggedIn={isLoggedIn}
          username={username}
          userRole={userRole}
          onLogout={handleLogout}
        />
      )}
      
      <main>
        {/* The route-transition wait uses the SHARED LoadingScreen, the same one
            ProtectedRoute shows while it verifies permissions. `fullScreen` is
            off because this fallback renders inside <main>, under the header. */}
        <Suspense fallback={<LoadingScreen fullScreen={false} />}>
          <Routes>
          <Route path="/" element={
            <PublicHomeRoute>
              <HeroSection onNavigate={navigateTo as any} isLoggedIn={isLoggedIn} />
              <HowItWorksSection />
              <TeachersSection
                teachersList={teachersList.filter((t) => t.isActive !== false)}
                onViewProfile={handleViewTeacherProfile}
                isPreview={true}
              />
              <FeaturesSection />
              <CoursesSection
                courses={coursesList}
                isLoggedIn={isLoggedIn}
                onNavigate={navigateTo as any}
                onViewCourse={handleViewCourseDetails}
                hasMore={coursesPagination.hasMore}
                isLoading={coursesPagination.isLoading}
                onLoadMore={handleLoadMoreCourses}
                isPreview={true}
              />
              <JoinUsSection onNavigate={navigateTo as any} />
            </PublicHomeRoute>
          } />
          
          <Route path="/teachers" element={
            <div className="pt-24 pb-12 bg-slate-50 dark:bg-slate-950 min-h-screen">
              <TeachersSection
                teachersList={teachersList.filter((t) => t.isActive !== false)}
                onViewProfile={handleViewTeacherProfile}
                isPreview={false}
              />
            </div>
          } />

          <Route path="/courses" element={
            <div className="pt-24 pb-12 bg-slate-50 dark:bg-slate-950 min-h-screen">
              <CoursesSection
                courses={coursesList}
                isLoggedIn={isLoggedIn}
                onNavigate={navigateTo as any}
                onViewCourse={handleViewCourseDetails}
                hasMore={coursesPagination.hasMore}
                isLoading={coursesPagination.isLoading}
                onLoadMore={handleLoadMoreCourses}
                isPreview={false}
              />
            </div>
          } />

          <Route path="/signup" element={
            <SignupPage
              onBackToHome={() => navigateTo('home')}
              onNavigateToLogin={() => navigateTo('login')}
            />
          } />

          <Route path="/teacher-application" element={
            <TeacherApplicationPage
              onBackToHome={() => navigateTo('home')}
            />
          } />

          <Route path="/login" element={
            <LoginPage
              onBackToHome={() => navigateTo('home')}
              onNavigateToSignup={() => navigateTo('signup')}
              onLoginSuccess={handleLoginSuccess}
              onNavigateToStudent={() => navigate('/')}
              onNavigateToInstructor={() => navigateTo('instructor')}
              onNavigateToAdmin={() => navigateTo('admin')}
            />
          } />

          <Route path="/teacher/:id" element={
            <TeacherProfileWrapper 
              teachersList={teachersList}
              coursesList={coursesList}
              isLoggedIn={isLoggedIn}
              navigateTo={navigateTo}
              handleViewCourseDetails={handleViewCourseDetails}
            />
          } />

          <Route path="/course/:id" element={
            <CourseDetailsWrapper 
              coursesList={coursesList}
              handleViewTeacherProfile={handleViewTeacherProfile}
            />
          } />

          <Route path="/privacy-policy" element={<PrivacyPolicyPage />} />
          <Route path="/terms" element={<TermsConditionsPage />} />
          <Route path="/play-video/:videoId" element={
            <ProtectedRoute allowedRoles={['student']}>
              <VideoPlayerPage />
            </ProtectedRoute>
          } />
          <Route path="/exam/:examId" element={
            <ProtectedRoute allowedRoles={['student']}>
              <ExamPage />
            </ProtectedRoute>
          } />

          <Route path="/instructor" element={
            <ProtectedRoute allowedRoles={['instructor']}>
              <InstructorSystem
                username={username}
                onBackToHome={() => navigateTo('instructor')}
                onLogout={handleLogout}
              />
            </ProtectedRoute>
          }>
            <Route index element={<InstructorHome />} />
            <Route path="course-management" element={
              <CourseManagement
                courses={myCoursesList}
                username={username}
                onAddCourse={async (c: any) => { await addCourse(c); await refreshMyCourses(); }}
                onEditCourse={async (c: any) => { await editCourse(c); await refreshMyCourses(); }}
                onDeleteCourse={async (id: any) => { await deleteCourse(id); await refreshMyCourses(); }}
              />
            } />
            <Route path="course-structure" element={
              <CourseStructureManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="groups-management" element={
              <GroupManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="notes-management" element={
              <NotesManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="links-management" element={
              <LinksManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="question-groups-management" element={
              <QuestionGroupsManagement
                username={username}
              />
            } />
            <Route path="question-bank" element={
              <QuestionBank
                username={username}
              />
            } />
            <Route path="exams-assignments-management" element={
              <ExamsAssignmentsManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="subscriptions" element={
              <SubscriptionsManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="video-management" element={
              <VideoManagement
                courses={myCoursesList}
                username={username}
              />
            } />
            <Route path="statistics" element={
              <InstructorStatistics 
                courses={coursesList}
                username={username}
              />
            } />
            <Route path="revenue" element={<InstructorRevenue />} />
            <Route path="center-groups" element={<CreateCenterGroup />} />
            <Route path="center-attendance" element={<CenterAttendance />} />
            <Route path="center-reports" element={<CenterReports />} />
            <Route path="center-course-activation" element={<ActivateCenterCourse />} />
            <Route path="center-payments" element={<CenterPaymentTracking />} />
            <Route path="exam-statistics" element={<ExamStatistics />} />
            <Route path="watch-statistics" element={<WatchStatistics />} />
            <Route path="admin-management" element={<AdminManagement />} />
          </Route>

          <Route path="/admin" element={
            <ProtectedRoute allowedRoles={['admin']}>
              <AdminSystem
                username={username}
                onBackToHome={() => navigateTo('home')}
                onLogout={handleLogout}
              />
            </ProtectedRoute>
          }>
            <Route index element={<AdminHome />} />
            <Route path="add-teacher" element={
              <AddTeacher 
                onAddTeacher={fetchTeachers}
                onUpdateTeacher={fetchTeachers}
                onNavigateHome={() => navigate('/admin')}
              />
            } />
            {/* ManageTeachers fetches its own rows through
                adminService.getAllTeachers(); the teachers /
                onUpdateTeachers props passed here before were never read. */}
            <Route path="manage-teachers" element={<ManageTeachers />} />
            <Route path="manage-employees" element={<ManageEmployees />} />
            <Route path="broadcast-notifications" element={
              <BroadcastNotifications />
            } />
            <Route path="manage-subscriptions" element={
              <ManageSubscriptions />
            } />
            <Route path="enrollment-codes" element={
              <EnrollmentCodes />
            } />
            {/* Read-only lookup companion to enrollment-codes above. Placed
                adjacent so the generate/inspect pair stays together. */}
            <Route path="enrollment-code-details" element={
              <EnrollmentCodeDetails />
            } />
            {/* Review/filter screen over ALL enrollment codes — companion to
                the single-code lookup right above. */}
            <Route path="filter-enrollment-codes" element={
              <FilterEnrollmentCodes />
            } />
            <Route path="student-code-details" element={
              <StudentCodeDetails />
            } />
            <Route path="reset-student-password" element={
              <ResetStudentPassword />
            } />
            <Route path="users" element={
              <ManageUsers />
            } />
            <Route path="students/:id/edit" element={
              <EditStudent />
            } />
            <Route path="add-student" element={
              <AddStudent />
            } />
            <Route path="statistics" element={
              <Statistics />
            } />
            <Route path="login-activity" element={
              <LoginActivity />
            } />
            <Route path="platform-settings" element={
              <PlatformSettings onLogout={handleLogout} />
            } />
            <Route path="profit-engine" element={
              <ProfitEngine />
            } />
            <Route path="revenue" element={
              <Revenue />
            } />
            <Route path="instructor-statement" element={
              <InstructorStatement />
            } />
            <Route path="teacher-payouts" element={
              <TeacherPayouts />
            } />
            <Route path="refund-management" element={
              <RefundManagement />
            } />
            <Route path="teacher-applications" element={
              <TeacherApplications />
            } />
            <Route path="generate-center-codes" element={
              <GenerateCenterCodes />
            } />
            <Route path="center-code-details" element={
              <CenterCodeDetails />
            } />
          </Route>

          <Route path="/student" element={
            <ProtectedRoute allowedRoles={['student']}>
              <div className="py-24 bg-slate-50 dark:bg-slate-950 min-h-screen" dir="rtl">
                <div className="container mx-auto px-6 md:px-12 lg:px-16">
                  <MyCourses 
                    username={username}
                    courses={coursesList}
                  />
                </div>
              </div>
            </ProtectedRoute>
          } />

          <Route path="/student/statistics" element={
            <ProtectedRoute allowedRoles={['student']}>
              <div className="py-24 bg-slate-50 dark:bg-slate-950 min-h-screen" dir="rtl">
                <div className="container mx-auto px-6 md:px-12 lg:px-16">
                  <StudentStatistics 
                    username={username}
                    courses={coursesList}
                  />
                </div>
              </div>
            </ProtectedRoute>
          } />

          <Route path="/student/watched-videos" element={
            <ProtectedRoute allowedRoles={['student']}>
              <div className="py-24 bg-slate-50 dark:bg-slate-950 min-h-screen" dir="rtl">
                <div className="container mx-auto px-6 md:px-12 lg:px-16">
                  <StudentWatchedVideos />
                </div>
              </div>
            </ProtectedRoute>
          } />

          <Route path="/student/completed-exams" element={
            <ProtectedRoute allowedRoles={['student']}>
              <div className="py-24 bg-slate-50 dark:bg-slate-950 min-h-screen" dir="rtl">
                <div className="container mx-auto px-6 md:px-12 lg:px-16">
                  <StudentCompletedExams />
                </div>
              </div>
            </ProtectedRoute>
          } />

          <Route path="/student/center" element={
            <ProtectedRoute allowedRoles={['student']}>
              <div className="py-24 bg-slate-50 dark:bg-slate-950 min-h-screen" dir="rtl">
                <div className="container mx-auto px-6 md:px-12 lg:px-16">
                  <CenterStudent />
                </div>
              </div>
            </ProtectedRoute>
          } />

          <Route path="*" element={<NotFoundPage />} />
        </Routes>
        </Suspense>
      </main>

      {view !== 'instructor' && view !== 'admin' && view !== 'login' && view !== 'signup' && view !== 'teacher-application' && <Footer />}
      <WhatsAppWidget key={location.pathname} />
      </div>
    </CenteredNotificationProvider>
    </ToastProvider>
  );
}
