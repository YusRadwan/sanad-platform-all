import { useState, useEffect, useMemo } from 'react';
import { motion } from 'motion/react';
import { useNavigate } from 'react-router';
import CourseHeader from "./CourseDetails/CourseHeader";
import CourseEnrollmentAction from "./CourseDetails/CourseEnrollmentAction";
import CourseSections from "./CourseDetails/CourseSections";
import { CourseDetailsPageProps, SyllabusGroup } from '../types/index';
import { courseService } from '../services/courseService';
import { authService } from '../services/authService';
import { teacherService } from '../services/teacherService';
import { uploadApiService } from '../services/uploadApiService';
import { safeUrl } from '../utils/urlValidation';
import { useCenteredNotification } from '../context/CenteredNotificationContext';










export default function CourseDetailsPage({ course, onBack }: CourseDetailsPageProps) {
  const navigate = useNavigate();
  const [expandedGroupId, setExpandedGroupId] = useState<string | null>(null);
  const [isPlayingVideo, setIsPlayingVideo] = useState<string | null>(null);
  const [courseExams, setCourseExams] = useState<any[]>([]);
  const [studentSubmissions] = useState<any[]>([]);
  const [isSubscribed, setIsSubscribed] = useState(false);
  const [courseGroups, setCourseGroups] = useState<SyllabusGroup[]>([]);
  const [courseVideos, setCourseVideos] = useState<any[]>([]);

  const loggedIn = authService.getIsLoggedIn();
  const username = authService.getUsername() || '';
  const userRole = authService.getRole();

  useEffect(() => {
    const fetchAssessments = async () => {
      try {
        const [exams, enrolledCourses, structure, videos] = await Promise.all([
          courseService.getExams(String(course.id)).catch(() => []),
          (loggedIn && userRole === 'student') ? courseService.getMyEnrolledCourses().catch(() => []) : Promise.resolve([]),
          courseService.getCourseStructure(String(course.id)).catch(() => ({ sections: [], groups: [] })),
          courseService.getCourseVideos(String(course.id)).catch(() => [])
        ]);
        setCourseExams(exams);
        setCourseVideos(videos);

        // Transform to match the frontend's flat accordion-group shape.
        // Sections and course_groups (open decision 10.21 — real scheduling
        // via is_hidden/start_at/end_at, filtered server-side by
        // CourseController::structure() already) are two independent
        // backend concepts, but this page renders them identically —
        // concatenated into one flat list, section entries first.
        const toDisplayGroup = (entry: any) => ({
          id: entry.id,
          title: entry.title,
          isHidden: entry.isHidden || false,
          items: (entry.lessons || []).map((lesson: any) => ({
            ...lesson,
            type: lesson.type || 'video',
            isHidden: lesson.isHidden || false
          }))
        });

        const transformedGroups = [
          ...(structure?.sections || []).map(toDisplayGroup),
          ...(structure?.groups || []).map(toDisplayGroup),
        ];

        setCourseGroups(transformedGroups);
        
        if (loggedIn && userRole === 'student') {
          const isEnrolled = enrolledCourses.some((c: any) => c.id === course.id);
          setIsSubscribed(isEnrolled);
        }
      } catch (e) {
        console.error('Failed to fetch assessments:', e);
      } finally {
      }
    };
    fetchAssessments();
  }, [course.id, loggedIn, userRole]);

  const [teacherId, setTeacherId] = useState<string | null>(null);
  const [teacherImage, setTeacherImage] = useState<string | null>(null);

  useEffect(() => {
    const fetchTeacherId = async () => {
      if (!course.teacher?.name) return;
      try {
        const allTeachers = await teacherService.getAllTeachers();
        if (Array.isArray(allTeachers)) {
          const found = allTeachers.find(
            (t: any) => (t.full_name === course.teacher?.name || t.name === course.teacher?.name)
          );
          if (found) {
            setTeacherId(found.id);
            if (found.avatar_url) {
              setTeacherImage(found.avatar_url);
            }
          }
        }
      } catch (err) {
        console.error('Error fetching teacher ID:', err);
      }
    };
    fetchTeacherId();
  }, [course.teacher?.name]);

  // Arabic date formatter
  const formatDateArabic = (dateString?: string) => {
    if (!dateString) return '';
    try {
      const date = new Date(dateString);
      if (isNaN(date.getTime())) return dateString;
      
      const months = [
        'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
        'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'
      ];
      
      const day = date.getDate();
      const month = months[date.getMonth()];
      const year = date.getFullYear();
      
      return `${day} ${month} ${year}`;
    } catch {
      return dateString;
    }
  };

  // Load creation date and last updated date.
  // The API returns these as `created_at`/`updated_at` (snake_case) — this
  // used to read `createdAt`/`updatedAt`, a field that never exists on the
  // course object, so both dates rendered as "غير محدد" for every course.
  const getCourseDates = () => {
    const createdAt = (course as any).created_at || (course as any).createdAt || null;
    const updatedAt = (course as any).updated_at || (course as any).updatedAt || null;
    return { createdAt, updatedAt };
  };

  const courseDates = getCourseDates();
  const { notify } = useCenteredNotification();

  const handleDownload = async (contentItemId: string, filename: string) => {
    try {
      await uploadApiService.downloadContentItemFile(contentItemId, filename);
    } catch {
      notify('تعذر تحميل الملف، يرجى المحاولة مرة أخرى', 'error');
    }
  };

  const handleOpenLink = async (contentItemId: string) => {
    try {
      const url = await courseService.getContentItemLinkUrl(contentItemId);
      const safe = safeUrl(url || undefined);
      if (safe) {
        window.open(safe, '_blank', 'noopener,noreferrer');
      } else {
        notify('تعذر فتح الرابط، يرجى المحاولة مرة أخرى', 'error');
      }
    } catch {
      notify('تعذر فتح الرابط، يرجى المحاولة مرة أخرى', 'error');
    }
  };

  // Get merged course structure with exams injected
  const mergedGroups = useMemo(() => {
    // We must clone the structure to avoid mutating state
    const structure = courseGroups.map(g => ({
      ...g,
      items: [...(g.items || [])]
    }));

    if (structure.length === 0) return [];

    const allAssessments = [...courseExams];

    // Matched by `content_item_id` — the actual foreign key linking an
    // `exams` row back to its `content_items` row — not by title. Title
    // matching was unreliable: `/structure`'s item id is a content_items.id
    // while `/courses/{id}/exams`'s id is the separate exams.id (a 1:1
    // relation with two different primary keys), so the old `i.id ===
    // assessment.id` branch could never actually match, leaving every exam
    // to fall through to a same-title lookup. That silently merged (or
    // mis-merged, on a title collision) into whichever group's item shared
    // the exam's title, in EVERY group that matched, not just the exam's
    // own group — and pushed genuinely unmatched exams into the first group
    // in the list regardless of which group the instructor actually
    // assigned them to.
    allAssessments.forEach((assessment: any) => {
      for (const group of structure) {
        const itemIndex = group.items.findIndex((i: any) => i.id === assessment.content_item_id);
        if (itemIndex !== -1) {
          // `courseExams` rows (GET /courses/{id}/exams) carry no `type` key
          // at all — spreading `assessment` after the structure item and
          // then forcing `type: assessment.type` (as this used to do) wrote
          // `undefined` over the structure item's correct `'exam'` type,
          // which is exactly why the "دخول الاختبار" button never rendered
          // for ANY exam (CourseSections.tsx's button switches on
          // `item.type === 'exam'`). Every assessment merged here IS an
          // exam by construction, so it's set explicitly instead of trusting
          // a field the source object doesn't have.
          group.items[itemIndex] = { ...group.items[itemIndex], ...assessment, type: 'exam' };
          break;
        }
      }
      // No matching content item in any group the student can currently see
      // (most likely: the exam's own group is hidden or not yet scheduled)
      // — left out entirely rather than mis-attached to an unrelated group.
    });

    return structure;
  }, [courseGroups, courseExams]);

  const totalGroups = mergedGroups.length;
  const totalLessons = mergedGroups.reduce((acc, g) => acc + (g.items?.length || 0), 0);

  // Access validation:
  // - Admin: always has access
  // - Instructor: has access ONLY if they are the designated teacher of this course
  // - Subscribed student: has access
  // - Free course + Logged In student: has access
  const isCourseOwner = userRole === 'instructor' && !!(course.teacher && course.teacher.name === username);
  const hasAccess = userRole === 'admin' || isCourseOwner || (userRole !== 'instructor' && (isSubscribed || (!!course.isFree && loggedIn)));

  // Real course-wide video listing (GET /courses/{id}/videos), replacing the
  // old courseService.getVideos() mock (always returned []).
  const globalVideos = courseVideos;

  return (
    <motion.div
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -15 }}
      transition={{ duration: 0.4 }}
      className="min-h-screen bg-slate-50 dark:bg-slate-950 px-4 py-8 md:py-12 text-slate-900 dark:text-white"
      dir="rtl"
    >
      <div className="max-w-6xl mx-auto space-y-8">

        <CourseHeader
          course={course}
          courseDates={courseDates}
          formatDateArabic={formatDateArabic}
          teacherId={teacherId}
          teacherImage={teacherImage}
          navigate={navigate}
          onBack={onBack}
          userRole={userRole}
          username={username}
        />

        <CourseEnrollmentAction 
          course={course}
          hasAccess={hasAccess}
          loggedIn={loggedIn}
          userRole={userRole}
        />

        <CourseSections 
          course={course}
          hasAccess={hasAccess}
          loggedIn={loggedIn}
          userRole={userRole}
          isSubscribed={isSubscribed}
          mergedGroups={mergedGroups}
          totalGroups={totalGroups}
          totalLessons={totalLessons}
          expandedGroupId={expandedGroupId}
          setExpandedGroupId={setExpandedGroupId}
          isPlayingVideo={isPlayingVideo}
          setIsPlayingVideo={setIsPlayingVideo}
          handleDownload={handleDownload}
          handleOpenLink={handleOpenLink}
          studentSubmissions={studentSubmissions}
          courseExams={courseExams}
          globalVideos={globalVideos}
        />

      </div>
    </motion.div>
  );
}
