export interface Course {
  id: string | number;
  title: string;
  description?: string;
  price?: number;
  discount_price?: number;
  discountPrice?: number;
  thumbnail_url?: string;
  image?: string;
  teacher_id?: string | number;
  grade_id?: string | number;
  subject_id?: string | number;
  is_published?: boolean;
  created_at?: string;
  updated_at?: string;
  deleted_at?: string | null;
  teacher_name?: string;
  teacher_avatar?: string;
  specialization?: string;
  grade_name?: string;
  subject_name?: string;
  grade?: string;
  subject?: string;
  teacher?: {
    name: string;
    image: string;
  };
  enrolled_students?: number;
  lessons?: number;
  students?: number;
  rating?: number;
  stage?: string;
  isFree?: boolean;
  /** Center System — course scope: platform-only, center-only, or both. */
  visibility?: 'platform' | 'center' | 'both';
}

export interface Section {
  id: string;
  course_id: string;
  title: string;
  order_index: number;
  items?: ContentItem[];
}

export interface ContentItem {
  id: string;
  section_id?: string;
  title: string;
  type?: 'video' | 'link' | 'note' | 'exam' | 'file';
  order_index?: number;
  content?: string | object;
  isHidden?: boolean;
  courseId?: string;
  grade?: string;
}

export interface VideoItem extends ContentItem {
  video_url?: string;
  url?: string;
  duration?: number | string;
  is_preview?: boolean;
  provider?: string;
  groupId?: string;
  courseId?: string;
  grade?: string;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
  thumbnailUrl?: string;
  isFree?: boolean;
  description?: string;
}

export interface LinkItem extends ContentItem {
  url: string;
  groupId?: string;
  description?: string;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
}

export interface Note extends ContentItem {
  content_text?: string;
  groupId?: string;
  description?: string;
  fileName?: string;
  filePath?: string;
  startDate?: string;
  startTime?: string;
  endDate?: string;
  endTime?: string;
}

export interface Group { id: string; name: string; courseId?: string; }
export interface SyllabusGroup { id: string; title: string; isHidden?: boolean; items: ContentItem[]; }

// Component Props Interfaces
export interface CourseCardProps {
  course: Course;
  index?: number;
  isLoggedIn?: boolean;
  onViewCourse?: (id: string | number) => void;
  onNavigate?: (id: string | number) => void;
}

export interface CourseDetailsPageProps {
  course: Course;
  onBack: () => void;
}

export interface CourseStructureManagementProps {
  username?: string;
  courses?: Course[];
}

export interface CourseManagementProps {
  courses?: Course[];
  username?: string;
  onAddCourse?: (course: Course) => void | Promise<void>;
  onEditCourse?: (course: Course) => void | Promise<void>;
  onDeleteCourse?: (courseId: string | number) => void | Promise<void>;
}

export interface SortableContentItemProps {
  item: ContentItem;
  onToggleVisibility: (itemId: string) => void;
  /** Whether this item is first/last within its group, to disable the matching move button. */
  isFirst?: boolean;
  isLast?: boolean;
  /** Moves the item up/down within its group by delegating to the same reorder logic drag-and-drop uses. */
  onMoveItem?: (itemId: string, direction: 'up' | 'down') => void;
}

export interface SortableGroupProps {
  group: SyllabusGroup;
  onUpdateTitle: (groupId: string, newTitle: string) => void;
  onItemsReorder: (groupId: string, newItems: ContentItem[]) => void;
  onItemToggleVisibility: (groupId: string, itemId: string) => void;
  /** Whether this group is first/last in the list, to disable the matching move button. */
  isFirst?: boolean;
  isLast?: boolean;
  /** Moves the group up/down by delegating to the same reorder logic drag-and-drop uses. */
  onMoveGroup?: (groupId: string, direction: 'up' | 'down') => void;
}

export interface GroupManagementProps {
  courses: Course[];
  username: string;
}

export interface LinksManagementProps {
  courses: Course[];
  username: string;
}

export interface NotesManagementProps {
  courses: Course[];
  username: string;
}

export interface VideoManagementProps {
  courses: Course[];
  username: string;
}
