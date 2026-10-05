import { authService } from "../../services/authService";
import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router';
import { motion, AnimatePresence } from 'motion/react';
import {
  Library, Save, ChevronDown, ChevronUp, Eye, EyeOff, Edit, CheckCircle2,
  GripVertical, FileText, Video, Link as LinkIcon, File, FileEdit,
  Plus, ExternalLink, ListFilter,
} from 'lucide-react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
  useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { SortableContentItemProps, SortableGroupProps, CourseStructureManagementProps, SyllabusGroup, ContentItem } from '../../types/index';
import { courseService } from '../../services/courseService';
import { groupApiService } from '../../services/groupApiService';
import { logger } from '../../services/logger';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import {
  PageHeader,
  SectionHeader,
  Card,
  Button,
  Input,
  Select,
  FormField,
  EmptyState,
  Skeleton,
  cn,
} from '../UI';
import type { LucideIcon } from 'lucide-react';

/**
 * Count chip for one content type inside a group header.
 *
 * Deliberately NOT the design system's `Badge`: this page colour-CODES the five
 * content types (video=rose, note=emerald, file=blue, link=indigo, exam=amber)
 * and `BadgeTone` has no indigo — routing these through `Badge` would collapse
 * link onto another tone and break the legend the icons share. The tints below
 * are the originals, unchanged.
 */
function TypeChip({ icon: Icon, tint, children }: { icon?: LucideIcon; tint: string; children: React.ReactNode }) {
  return (
    <span className={cn('inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-md', tint)}>
      {Icon && <Icon className="w-3 h-3 shrink-0" />}
      {children}
    </span>
  );
}








type ContentType = 'video' | 'note' | 'file' | 'link' | 'exam';





// Helper components for Sortable items


const SortableContentItem: React.FC<SortableContentItemProps> = ({ item, onToggleVisibility, isFirst, isLast, onMoveItem }) => {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: item.id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    zIndex: isDragging ? 10 : 1,
  };

  const getIcon = (type: ContentType) => {
    switch (type) {
      case 'video': return <Video className="w-4 h-4 text-rose-500" />;
      case 'note': return <FileText className="w-4 h-4 text-emerald-500" />;
      case 'file': return <File className="w-4 h-4 text-blue-500" />;
      case 'link': return <LinkIcon className="w-4 h-4 text-indigo-500" />;
      case 'exam': return <FileEdit className="w-4 h-4 text-amber-500" />;
    }
  };

  const getTypeLabel = (type: ContentType) => {
    switch (type) {
      case 'video': return 'فيديو';
      case 'note': return 'مذكرة';
      case 'file': return 'ملف';
      case 'link': return 'رابط';
      case 'exam': return 'امتحان';
    }
  };

  const getTint = (type: ContentType) => {
    switch (type) {
      case 'video': return 'bg-rose-50 dark:bg-rose-500/10';
      case 'note': return 'bg-emerald-50 dark:bg-emerald-500/10';
      case 'file': return 'bg-blue-50 dark:bg-blue-500/10';
      case 'link': return 'bg-indigo-50 dark:bg-indigo-500/10';
      case 'exam': return 'bg-amber-50 dark:bg-amber-500/10';
    }
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`flex items-center justify-between gap-3 p-3 mb-2 rounded-xl border ${isDragging ? 'bg-white dark:bg-slate-800 border-rose-400 shadow-md' : 'bg-white dark:bg-slate-900 border-rose-100 dark:border-rose-500/10'} transition-colors`}
    >
      <div className="min-w-0 flex-1">
        <span className={`text-base font-bold block truncate ${item.isHidden ? 'text-slate-400 dark:text-slate-500' : 'text-slate-700 dark:text-slate-200'}`}>
          {item.title}
        </span>
        <span className="text-[11px] font-black text-slate-400 block mt-0.5">{getTypeLabel(item.type || 'video')}</span>
      </div>

      <div className="flex items-center gap-1 shrink-0">
        {onMoveItem && (
          <>
            <button
              type="button"
              onClick={() => onMoveItem(item.id, 'up')}
              disabled={isFirst}
              aria-label="نقل لأعلى"
              className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 disabled:opacity-30 disabled:pointer-events-none transition-colors"
            >
              <ChevronUp className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={() => onMoveItem(item.id, 'down')}
              disabled={isLast}
              aria-label="نقل لأسفل"
              className="p-1.5 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10 disabled:opacity-30 disabled:pointer-events-none transition-colors"
            >
              <ChevronDown className="w-3.5 h-3.5" />
            </button>
          </>
        )}
        <button
          onClick={() => onToggleVisibility(item.id)}
          className={`p-2 rounded-lg transition-colors ${item.isHidden ? 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500' : 'bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400'}`}
          title={item.isHidden ? 'إظهار للطلاب' : 'إخفاء عن الطلاب'}
        >
          {item.isHidden ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
        </button>
        <div className={`w-8 h-8 rounded-lg flex items-center justify-center ${getTint(item.type || 'video')} ${item.isHidden ? 'opacity-50 grayscale' : ''}`}>
          {getIcon(item.type || 'video')}
        </div>
        <button
          {...attributes}
          {...listeners}
          aria-label="سحب لإعادة الترتيب"
          className="text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 cursor-grab active:cursor-grabbing touch-none p-1.5 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-500/10"
        >
          <GripVertical className="w-4 h-4" />
        </button>
      </div>
    </div>
  );
}




const SortableGroup: React.FC<SortableGroupProps> = ({
  group,
  onUpdateTitle,
  onItemsReorder,
  onItemToggleVisibility,
  isFirst,
  isLast,
  onMoveGroup,
}) => {
  const {
    attributes,
    listeners,
    setNodeRef: setGroupNodeRef,
    transform: groupTransform,
    transition: groupTransition,
    isDragging: isGroupDragging,
  } = useSortable({ id: group.id, data: { type: 'group' } });

  const style = {
    transform: CSS.Transform.toString(groupTransform),
    transition: groupTransition,
    zIndex: isGroupDragging ? 20 : 1,
  };

  const [isEditing, setIsEditing] = useState(false);
  const [editTitle, setEditTitle] = useState(group.title);
  const [isExpanded, setIsExpanded] = useState(false);

  const handleSaveTitle = () => {
    if (editTitle.trim()) {
      onUpdateTitle(group.id, editTitle);
      setIsEditing(false);
    }
  };

  // Sensors for the inner DndContext
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      const oldIndex = group.items.findIndex((i) => i.id === active.id);
      const newIndex = group.items.findIndex((i) => i.id === over.id);
      onItemsReorder(group.id, arrayMove(group.items, oldIndex, newIndex));
    }
  };

  // Same reorder primitive the drag-and-drop handler above uses (`arrayMove`
  // on `group.items`), just triggered by an explicit up/down button instead
  // of a pointer drag.
  const handleMoveItem = (itemId: string, direction: 'up' | 'down') => {
    const oldIndex = group.items.findIndex((i) => i.id === itemId);
    if (oldIndex === -1) return;
    const newIndex = direction === 'up' ? oldIndex - 1 : oldIndex + 1;
    if (newIndex < 0 || newIndex >= group.items.length) return;
    onItemsReorder(group.id, arrayMove(group.items, oldIndex, newIndex));
  };

  const counts = {
    video: group.items.filter(i => i.type === 'video').length,
    note: group.items.filter(i => i.type === 'note').length,
    file: group.items.filter(i => i.type === 'file').length,
    link: group.items.filter(i => i.type === 'link').length,
    exam: group.items.filter(i => i.type === 'exam').length,
  };

  return (
    <div
      ref={setGroupNodeRef}
      style={style}
      className={`mb-4 rounded-2xl border-2 transition-all ${isGroupDragging ? 'bg-rose-50 dark:bg-rose-500/10 border-rose-400 shadow-xl' : 'bg-rose-50/50 dark:bg-rose-500/4 border-rose-200 dark:border-rose-500/20'}`}
    >
      {/* Group Header */}
      <div className="p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex-1 min-w-0">
          {isEditing ? (
            <div className="flex items-center gap-2 max-w-sm">
              <Input
                size="sm"
                type="text"
                value={editTitle}
                onChange={(e) => setEditTitle(e.target.value)}
                aria-label="عنوان المجموعة"
                autoFocus
              />
              <Button
                size="sm"
                iconOnly
                icon={CheckCircle2}
                onClick={handleSaveTitle}
                aria-label="حفظ العنوان"
                className="shrink-0"
              />
            </div>
          ) : (
            <div className="flex items-center gap-2 min-w-0">
              <span className="text-sm font-bold text-rose-500 dark:text-rose-400 shrink-0">العنوان:</span>
              <h3 className="text-lg font-black text-slate-800 dark:text-white truncate">
                {group.title}
              </h3>
              <button
                onClick={() => {
                  setEditTitle(group.title);
                  setIsEditing(true);
                }}
                className="p-1 text-slate-400 hover:text-rose-600 dark:hover:text-rose-400 transition-colors shrink-0"
                aria-label="تعديل عنوان المجموعة"
              >
                <Edit className="w-3.5 h-3.5" />
              </button>
            </div>
          )}

          {/* Stats badges */}
          <div className="flex flex-wrap gap-2 mt-2">
            {counts.video > 0 && <TypeChip icon={Video} tint="bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">{counts.video} فيديو</TypeChip>}
            {counts.note > 0 && <TypeChip icon={FileText} tint="bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">{counts.note} مذكرة</TypeChip>}
            {counts.file > 0 && <TypeChip icon={File} tint="bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">{counts.file} ملف</TypeChip>}
            {counts.link > 0 && <TypeChip icon={LinkIcon} tint="bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">{counts.link} رابط</TypeChip>}
            {counts.exam > 0 && <TypeChip icon={FileEdit} tint="bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">{counts.exam} امتحان</TypeChip>}
            {group.items.length === 0 && <TypeChip tint="bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">فارغ</TypeChip>}
          </div>
        </div>

        {/* Trailing icon-button cluster: reorder + drag handle + expand/collapse */}
        <div className="flex items-center gap-1 shrink-0">
          {onMoveGroup && (
            <>
              <button
                type="button"
                onClick={() => onMoveGroup(group.id, 'up')}
                disabled={isFirst}
                aria-label="نقل المجموعة لأعلى"
                className="p-2 rounded-lg text-rose-400 hover:text-rose-600 hover:bg-rose-100 dark:hover:bg-rose-500/10 disabled:opacity-30 disabled:pointer-events-none transition-colors"
              >
                <ChevronUp className="w-4 h-4" />
              </button>
              <button
                type="button"
                onClick={() => onMoveGroup(group.id, 'down')}
                disabled={isLast}
                aria-label="نقل المجموعة لأسفل"
                className="p-2 rounded-lg text-rose-400 hover:text-rose-600 hover:bg-rose-100 dark:hover:bg-rose-500/10 disabled:opacity-30 disabled:pointer-events-none transition-colors"
              >
                <ChevronDown className="w-4 h-4" />
              </button>
            </>
          )}
          <button
            {...attributes}
            {...listeners}
            aria-label="سحب لإعادة الترتيب"
            className="text-rose-400 hover:text-rose-600 dark:hover:text-rose-400 cursor-grab active:cursor-grabbing touch-none p-2 rounded-lg hover:bg-rose-100 dark:hover:bg-rose-500/10"
          >
            <GripVertical className="w-5 h-5" />
          </button>
          <button
            onClick={() => setIsExpanded(!isExpanded)}
            className="p-2.5 rounded-xl bg-rose-100/70 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400 hover:bg-rose-200 dark:hover:bg-rose-500/20 transition-colors"
            aria-label={isExpanded ? 'طي المجموعة' : 'عرض محتوى المجموعة'}
          >
            <ChevronDown className={`w-4 h-4 transition-transform duration-300 ${isExpanded ? 'rotate-180' : ''}`} />
          </button>
        </div>
      </div>

      {/* Group Content Items */}
      <AnimatePresence>
        {isExpanded && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            className="overflow-hidden"
          >
            <div className="p-4 pt-0 border-t border-rose-100 dark:border-rose-500/10 mt-2 bg-white/60 dark:bg-slate-900/40 rounded-b-2xl">
              {group.items.length > 0 ? (
                <div className="mt-4">
                  {/* Stop dragging the group when dragging an item by using a separate DndContext */}
                  <DndContext 
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleDragEnd}
                  >
                    <SortableContext items={group.items.map(i => i.id)} strategy={verticalListSortingStrategy}>
                      <div className="space-y-1 pr-6 sm:pr-8 border-r-2 border-rose-200 dark:border-rose-500/20">
                        {group.items.map((item, index) => (
                          <SortableContentItem
                            key={item.id}
                            item={item}
                            onToggleVisibility={(itemId) => onItemToggleVisibility(group.id, itemId)}
                            isFirst={index === 0}
                            isLast={index === group.items.length - 1}
                            onMoveItem={handleMoveItem}
                          />
                        ))}
                      </div>
                    </SortableContext>
                  </DndContext>
                </div>
              ) : (
                <div className="text-center py-6">
                  <p className="text-base font-bold text-slate-400 dark:text-slate-500">لا يوجد محتوى في هذه المجموعة.</p>
                </div>
              )}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

export default function CourseStructureManagement(_props: CourseStructureManagementProps) {
  const navigate = useNavigate();
  const [courses, setCourses] = useState<any[]>([]);

  useEffect(() => {
    let isMounted = true;
    const fetchMyCourses = async () => {
      try {
        const myCourses = await courseService.getMyCourses();
        if (isMounted) {
          setCourses(myCourses);
        }
      } catch (e) {
        console.error('Error fetching my courses:', e);
        if (isMounted) showFeedback('حدث خطأ أثناء تحميل الكورسات', 'error');
      }
    };
    fetchMyCourses();
    return () => { isMounted = false; };
  }, []);

  const getInstructorInfo = () => {
    try {
      const currentUser = authService.getUser();
      if (currentUser && currentUser.role === 'instructor') {
        return {
          name: currentUser.fullName || currentUser.username || '',
          grades: Array.isArray(currentUser.grades) ? currentUser.grades : [],
          subjects: Array.isArray(currentUser.subjects) ? currentUser.subjects : [],
          id: currentUser.id
        };
      }
      return null;
    } catch {
      return null;
    }
  };

  const currentTeacher = getInstructorInfo();
  // A course's own grade must ALWAYS stay reachable here even after the
  // instructor's current teaching-grade assignment changes — e.g. an admin
  // edits their profile after they already have a course under the old
  // grade. Restricting this dropdown to ONLY the current assignment made
  // that course permanently unselectable ("لا توجد كورسات"), with no way to
  // reach it even though the instructor still owns it and needs to manage
  // its structure. Real bug, reproduced live before this fix.
  const assignedGrades: string[] = currentTeacher?.grades || [];
  const courseGrades = (courses || []).map((c: any) => c.grade).filter(Boolean);
  const availableGrades = Array.from(new Set([...assignedGrades, ...courseGrades]));

  const [selectedGrade, setSelectedGrade] = useState<string>(availableGrades[0] || '');
  const [selectedCourseId, setSelectedCourseId] = useState<string>('');

  useEffect(() => {
    if (availableGrades.length === 0) return;

    if (!availableGrades.includes(selectedGrade)) {
      setSelectedGrade(availableGrades[0]);
      return;
    }

    // Once courses finish loading, don't leave the page sitting on a grade
    // that has none of them (e.g. the instructor's currently-assigned grade)
    // while another available grade actually has real courses — switch to
    // the first one that does, so the instructor sees their content instead
    // of an empty "لا توجد كورسات" state they'd have to know to work around.
    const currentGradeHasCourses = courses.some((c: any) => c.grade === selectedGrade);
    if (!currentGradeHasCourses) {
      const gradeWithCourses = availableGrades.find((g) =>
        courses.some((c: any) => c.grade === g)
      );
      if (gradeWithCourses && gradeWithCourses !== selectedGrade) {
        setSelectedGrade(gradeWithCourses);
      }
    }
  }, [availableGrades, selectedGrade, courses]);

  const filteredCourses = (courses || []).filter(c => c.grade === selectedGrade);
  
  useEffect(() => {
    if (!filteredCourses.find(c => c.id === selectedCourseId)) {
      setSelectedCourseId('');
    }
  }, [selectedGrade, courses]);

  // Dynamic groups state based on selectedCourseId
  const [groups, setGroups] = useState<SyllabusGroup[]>([]);
  const [isFetchingStructure, setIsFetchingStructure] = useState(false);

  useEffect(() => {
    let isMounted = true;
    if (!selectedCourseId) {
      setGroups([]);
      return;
    }

    const fetchStructure = async () => {
      setIsFetchingStructure(true);
      try {
        // Every course on the platform is organised through course_groups
        // (GroupManagement.tsx + Video/Notes/Links/Exams management all add
        // content under a group) — "sections" is a separate, older model with
        // no creation UI anywhere, so it is always empty and this page used
        // to render "لا يوجد مجموعات" for every real course. `groups` is the
        // one that actually holds the teacher's content.
        const { groups: courseGroups } = await courseService.getCourseStructure(selectedCourseId);
        if (!isMounted) return;

        if (courseGroups && courseGroups.length > 0) {
          const transformedGroups: SyllabusGroup[] = courseGroups.map((group: any) => ({
            id: group.id,
            title: group.title,
            isHidden: group.isHidden || false,
            items: (group.lessons || []).map((lesson: any) => ({
              ...lesson,
              type: lesson.type || lesson.content_type || 'video',
              isHidden: lesson.isHidden || false
            }))
          }));
          setGroups(transformedGroups);
        } else {
          setGroups([]);
        }
      } catch (e) {
        console.error('Error fetching course structure:', e);
        if (isMounted) showFeedback('حدث خطأ أثناء تحميل هيكل الكورس', 'error');
      } finally {
        if (isMounted) setIsFetchingStructure(false);
      }
    };

    fetchStructure();
    return () => { isMounted = false; };
  }, [selectedCourseId, courses]);

  const { notify } = useCenteredNotification();
  const showFeedback = (message: string, type: 'success' | 'error') => {
    notify(message, type);
  };

  // Sensors for group reordering
  const groupSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const handleGroupDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      const oldIndex = groups.findIndex((g) => g.id === active.id);
      const newIndex = groups.findIndex((g) => g.id === over.id);
      setGroups(arrayMove(groups, oldIndex, newIndex));
    }
  };

  // Same `arrayMove` reorder primitive as `handleGroupDragEnd`, triggered by
  // the group card's up/down buttons instead of a pointer drag.
  const handleMoveGroup = (groupId: string, direction: 'up' | 'down') => {
    const oldIndex = groups.findIndex((g) => g.id === groupId);
    if (oldIndex === -1) return;
    const newIndex = direction === 'up' ? oldIndex - 1 : oldIndex + 1;
    if (newIndex < 0 || newIndex >= groups.length) return;
    setGroups(arrayMove(groups, oldIndex, newIndex));
  };

  const handleSaveStructure = async () => {
    if (selectedCourseId) {
      // Persist everything the teacher may have changed locally: group
      // order + title renames (PATCH /groups/{id}, order_index + title
      // together), and per-item order + visibility (PUT /content-items/{id},
      // order_index + is_hidden together). No bulk /reorder route exists for
      // groups — each row is written individually via the same PATCH/PUT
      // GroupManagement.tsx and the content pages already use, just with the
      // new order_index field. Idempotent, so the current `groups` state is
      // sent in full rather than diffed.
      try {
        await Promise.all([
          ...groups.map((group, index) => groupApiService.updateGroup(group.id, { title: group.title, order_index: index })),
          ...groups.flatMap(group => group.items.map((item, index) =>
            courseService.updateContentItemVisibility(item.id, !!item.isHidden, index)
          )),
        ]);
      } catch (e) {
        logger.error('Error saving course structure', e as Error);
        showFeedback('حدث خطأ أثناء حفظ هيكل الكورس، برجاء المحاولة مرة أخرى', 'error');
        return;
      }

      // Also touch the course's updated_at via the real PUT /courses/{id}
      // endpoint (harmless, no longer the point of this button).
      const now = new Date().toISOString();
      try {
        const current = courses.find((c: any) => String(c.id) === String(selectedCourseId));
        if (current) {
          const updated = await courseService.updateCourse(String(selectedCourseId), {
            title: current.title,
            description: current.description,
          });
          setCourses((prev: any[]) => prev.map((c: any) =>
            String(c.id) === String(selectedCourseId) ? { ...c, updated_at: updated?.updated_at || now } : c
          ));
        }
      } catch (e) {
        console.error('Error touching course updated_at via backend:', e);
        showFeedback('تم حفظ هيكل الكورس، لكن حدث خطأ أثناء تحديث بيانات الكورس', 'error');
        return;
      }

      showFeedback('تم حفظ هيكل الكورس بنجاح!', 'success');
    }
  };

  const updateGroupTitle = (groupId: string, title: string) => {
    setGroups(groups.map(g => g.id === groupId ? { ...g, title } : g));
  };

  const toggleItemVisibility = (groupId: string, itemId: string) => {
    setGroups(groups.map(g => {
      if (g.id === groupId) {
        return {
          ...g,
          items: g.items.map(i => i.id === itemId ? { ...i, isHidden: !i.isHidden } : i)
        };
      }
      return g;
    }));
  };

  const handleItemsReorder = (groupId: string, newItems: ContentItem[]) => {
    setGroups(groups.map(g => g.id === groupId ? { ...g, items: newItems } : g));
  };

  return (
    <div dir="rtl" className="relative space-y-8 animate-in fade-in duration-300 text-right font-sans min-h-screen">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header */}
      <PageHeader
        icon={Library}
        breadcrumbs={[{ label: 'لوحة تحكم المعلم' }, { label: 'إدارة الكورسات' }]}
        title="إدارة الكورسات"
        description="ترتيب محتوى الكورس الدراسي"
        actions={
          <Button
            size="lg"
            icon={Save}
            onClick={handleSaveStructure}
            className="w-full md:w-auto shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
          >
            حفظ هيكل الكورس
          </Button>
        }
      />


      {/* Filter card */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0, duration: 0.35 }}
      >
        <Card className="border-2 border-rose-200 dark:border-rose-500/25">
          <div className="flex items-center gap-2.5 mb-5">
            <div className="p-2 rounded-xl bg-rose-50 dark:bg-rose-500/10 text-rose-600 dark:text-rose-400">
              <ListFilter className="w-4 h-4" aria-hidden="true" />
            </div>
            <h2 className="text-sm sm:text-base font-black text-slate-800 dark:text-white">تصفية الكورس</h2>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FormField label="اختر الصف الدراسي">
              {({ id }) =>
                availableGrades.length > 1 ? (
                  <Select
                    id={id}
                    size="lg"
                    value={selectedGrade}
                    onChange={(e) => setSelectedGrade(e.target.value)}
                    className="font-bold rounded-2xl border-rose-200 dark:border-rose-500/25 hover:border-rose-300 dark:hover:border-rose-500/40 focus:border-rose-500 focus:ring-rose-500/25"
                  >
                    {availableGrades.map((grade: string) => (
                      <option key={grade} value={grade}>{grade}</option>
                    ))}
                  </Select>
                ) : (
                  /* Plain read-only value, as before — not a control, nothing submitted. */
                  <div className="w-full h-12 px-4 flex items-center rounded-2xl bg-rose-50/60 dark:bg-slate-800 border border-rose-200 dark:border-rose-500/25 text-slate-500 dark:text-slate-400 text-base font-medium select-none">
                    {availableGrades[0]}
                  </div>
                )
              }
            </FormField>

            <FormField label="اختر الكورس">
              {({ id }) => (
                <Select
                  id={id}
                  size="lg"
                  value={selectedCourseId}
                  onChange={(e) => setSelectedCourseId(e.target.value)}
                  disabled={filteredCourses.length === 0}
                  className="font-bold rounded-2xl border-rose-200 dark:border-rose-500/25 hover:border-rose-300 dark:hover:border-rose-500/40 focus:border-rose-500 focus:ring-rose-500/25"
                >
                  {filteredCourses.length > 0 ? (
                    <>
                      <option value="">-- اختر الكورس --</option>
                      {filteredCourses.map((c) => (
                        <option key={c.id} value={c.id}>{c.title}</option>
                      ))}
                    </>
                  ) : (
                    <option value="">لا توجد كورسات</option>
                  )}
                </Select>
              )}
            </FormField>
          </div>
        </Card>
      </motion.div>

      {/* Actions row */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.04, duration: 0.35 }}
        className="flex flex-col sm:flex-row sm:items-center justify-between gap-3"
      >
        <Button
          variant="danger"
          size="lg"
          icon={Plus}
          onClick={() => navigate('/instructor/groups-management')}
        >
          إضافة مجموعة
        </Button>

        <div className="flex items-center gap-3 flex-wrap">
          <span className="text-sm font-bold text-slate-500 dark:text-slate-400">
            إجمالي {groups.length} مجموعة
          </span>
          <Button
            variant="outline"
            size="lg"
            icon={ExternalLink}
            disabled={!selectedCourseId}
            onClick={() => selectedCourseId && navigate(`/course/${selectedCourseId}`)}
          >
            عرض الكورس
          </Button>
        </div>
      </motion.div>

      {/* Main Area: Structure Management */}
      <motion.div
        initial={{ opacity: 0, y: 16 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.08, duration: 0.35 }}
      >
        {selectedCourseId ? (
          <Card radius="panel" className="min-h-125">

            {isFetchingStructure ? (
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <Skeleton variant="text" width="30%" />
                </div>
                <SkeletonGroupCard />
                <SkeletonGroupCard />
                <SkeletonGroupCard />
              </div>
            ) : (
              <>
                <SectionHeader
                  className="mb-6"
                  title="هيكل الكورس"
                  description="يمكنك إعادة ترتيب المجموعات وعناصرها بالسحب والإفلات أو بأزرار النقل."
                />

                {groups.length > 0 ? (
                  <DndContext
                    sensors={groupSensors}
                    collisionDetection={closestCenter}
                    onDragEnd={handleGroupDragEnd}
                  >
                    <SortableContext items={groups.map(g => g.id)} strategy={verticalListSortingStrategy}>
                      <div>
                        {groups.map((group, index) => (
                          <SortableGroup
                            key={group.id}
                            group={group}
                            onUpdateTitle={updateGroupTitle}
                            onItemsReorder={handleItemsReorder}
                            onItemToggleVisibility={toggleItemVisibility}
                            isFirst={index === 0}
                            isLast={index === groups.length - 1}
                            onMoveGroup={handleMoveGroup}
                          />
                        ))}
                      </div>
                    </SortableContext>
                  </DndContext>
                ) : (
                  <EmptyState
                    icon={Library}
                    title="لا يوجد مجموعات"
                    description={'لم يتم إنشاء أي مجموعات لهذا الكورس بعد. يرجى الذهاب إلى صفحة "إدارة المجموعات" لإضافة مجموعات جديدة.'}
                    className="bg-slate-50 dark:bg-slate-900/50 rounded-2xl border border-dashed border-slate-200 dark:border-slate-800"
                  />
                )}
              </>
            )}
          </Card>
        ) : (
          <EmptyState
            icon={Library}
            title="يرجى اختيار الكورس لعرض الهيكل"
            className="h-125 justify-center bg-slate-50 dark:bg-slate-900/50 rounded-3xl border border-dashed border-slate-200 dark:border-slate-800"
          />
        )}
      </motion.div>
    </div>
  );
}

/** Skeleton stand-in for one `SortableGroup` card while the structure is loading. */
function SkeletonGroupCard() {
  return (
    <div className="mb-4 rounded-2xl border border-slate-200 dark:border-slate-800 p-4 sm:p-5">
      <div className="flex items-center gap-3">
        <Skeleton variant="block" className="w-5 h-5 rounded" />
        <div className="flex-1 space-y-2">
          <Skeleton variant="text" width="35%" />
          <div className="flex gap-2">
            <Skeleton variant="block" className="h-5 w-16 rounded-md" />
            <Skeleton variant="block" className="h-5 w-16 rounded-md" />
          </div>
        </div>
        <Skeleton variant="block" className="w-9 h-9 rounded-xl shrink-0" />
      </div>
    </div>
  );
}
