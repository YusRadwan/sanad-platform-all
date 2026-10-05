import React, { useEffect, useState } from 'react';
import { GraduationCap, BookOpen, Trash2, PlusCircle, Pencil, Check, X, ChevronUp, ChevronDown, ChevronLeft, Layers } from 'lucide-react';
import { Card, CardHeader, Skeleton } from '../../UI';
import { CatalogItem } from '../../../services/catalogService';

/**
 * The subjects list and the grade catalog are PERSISTED catalog rows
 * (backend CatalogController -> `grades` / `subjects` / `grade_sections`
 * tables) instead of client-side arrays seeded from `src/constants/*.ts`.
 * Adding or deleting here calls the real API through the parent's
 * `onAddOption` / `onDeleteOption` (and the section-specific equivalents),
 * so the change is visible in every future admin session, not just the open
 * form.
 *
 * Grades are the one catalog SCOPED by another (open decision 10.12): every
 * grade belongs to a section (`sectionId`), and its manual `order` is
 * independent PER SECTION. The grade column below is therefore a two-level
 * accordion — sections at the top, each expandable to show (and manage)
 * only its own grades — rather than the single flat list it used to be.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ RENAME IS BACK (the pencil control), AND IT IS NOT THE OLD ONE.          │
 * │                                                                          │
 * │ The pencil was removed here for a real reason: teacher assignments are    │
 * │ stored as NAME STRINGS in `teachers.grades`/`.subjects`, so renaming a    │
 * │ catalog row through the old control silently orphaned every teacher       │
 * │ carrying the old name. Delete + re-add was the supported path.            │
 * │                                                                          │
 * │ The control is reinstated only because the SERVER now makes it safe:      │
 * │ PATCH /catalogs/{type}/{id} renames the row AND rewrites those JSON       │
 * │ arrays in one transaction. Courses were never at risk — they reference    │
 * │ the catalog by FOREIGN KEY and read the name through a live lookup.       │
 * │                                                                          │
 * │ DO NOT re-add a rename control anywhere that does not go through          │
 * │ onEditOption -> catalogService.rename. A local-only rename is the exact   │
 * │ bug this history is about.                                                │
 * └──────────────────────────────────────────────────────────────────────────┘
 */

interface TeacherClassificationsProps {
  subjects: CatalogItem[];
  selectedSubjects: string[];
  setSelectedSubjects: React.Dispatch<React.SetStateAction<string[]>>;
  showSubjectManager: boolean;
  setShowSubjectManager: (val: boolean) => void;
  newSubject: string;
  setNewSubject: (val: string) => void;

  sections: CatalogItem[];
  grades: CatalogItem[];
  selectedGrades: string[];
  setSelectedGrades: React.Dispatch<React.SetStateAction<string[]>>;
  showGradeManager: boolean;
  setShowGradeManager: (val: boolean) => void;
  newGrade: string;
  setNewGrade: (val: string) => void;

  /** Persist a new subject/grade option. `sectionId` is required for grades. */
  onAddOption: (type: 'grades' | 'subjects', name: string, sectionId?: string) => Promise<void>;
  /**
   * Rename an option by row id. The server cascades the new name into every
   * teacher's stored grades/subjects, so this is safe in a way the removed
   * client-side rename never was.
   */
  onEditOption: (type: 'grades' | 'subjects', item: CatalogItem, name: string) => Promise<void>;
  /** Delete an option by row id. Rejected by the server (409) when in use. */
  onDeleteOption: (type: 'grades' | 'subjects', item: CatalogItem) => Promise<void>;
  /**
   * Persist a full reorder of one SECTION's grades. `orderedIds` is that
   * section's complete list of grade ids in the new display order — the
   * server rejects anything short of an exact permutation of THAT section.
   */
  onReorderOption: (sectionId: string, orderedIds: string[]) => Promise<void>;

  /** Persist a new section. */
  onAddSection: (name: string) => Promise<void>;
  /** Rename a section — never reassigns its grades. */
  onEditSection: (item: CatalogItem, name: string) => Promise<void>;
  /** Delete a section. Rejected by the server (409) while it still holds grades. */
  onDeleteSection: (item: CatalogItem) => Promise<void>;
  /** Persist a full reorder of the (flat, unscoped) sections list. */
  onReorderSection: (orderedIds: string[]) => Promise<void>;

  /** True while the catalogs are still being fetched. */
  isLoadingCatalogs: boolean;
}

type CatalogKind = 'grades' | 'subjects' | 'grade_sections';

interface CatalogOptionRowProps {
  type: CatalogKind;
  item: CatalogItem;
  onEditOption: (type: CatalogKind, item: CatalogItem, name: string) => Promise<void>;
  onDeleteOption: (type: CatalogKind, item: CatalogItem) => Promise<void>;
  /**
   * Up/down reordering — only ever passed for orderable rows (grades within
   * a section, or sections themselves). When omitted, no reorder buttons
   * render, which is how the subjects list (no `order` column, always
   * alphabetical) stays exactly as it was.
   */
  onMoveUp?: () => void;
  onMoveDown?: () => void;
  canMoveUp?: boolean;
  canMoveDown?: boolean;
}

/**
 * One managed option: its name, a pencil that swaps the name for an inline
 * input, and the existing trash button. Shared by every manager below (the
 * subjects list, the sections list, and each section's own grade list) —
 * they differ only in their `type`, and triplicating an editable row is how
 * the copies would drift apart.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ THE EDITOR CLOSES ON `item.name` CHANGING, NOT ON THE PROMISE RESOLVING. │
 * │                                                                          │
 * │ onEditOption mirrors onAddOption/onDeleteOption: it reports failures by   │
 * │ toasting and RESOLVES either way, so awaiting it tells us nothing about   │
 * │ whether the rename actually happened. Closing on resolve would therefore  │
 * │ discard what the admin typed the moment the server rejected a duplicate   │
 * │ name — the one case they most need it kept.                               │
 * │                                                                          │
 * │ Instead the parent is the source of truth: a successful rename replaces   │
 * │ this row's `item` with the renamed one, and the effect below closes the   │
 * │ editor in response. A rejected rename leaves `item.name` untouched, so    │
 * │ the editor simply stays open next to the error toast.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
function CatalogOptionRow({
  type,
  item,
  onEditOption,
  onDeleteOption,
  onMoveUp,
  onMoveDown,
  canMoveUp = false,
  canMoveDown = false,
}: CatalogOptionRowProps) {
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState(item.name);

  // Confirmation channel — see the docblock. Also resyncs the draft when the
  // list is refetched underneath us.
  useEffect(() => {
    setDraft(item.name);
    setIsEditing(false);
  }, [item.name]);

  const commit = () => {
    const name = draft.trim();
    // A no-op (or emptied) rename is a cancel, not a request: the parent would
    // return early anyway and `item.name` would never change, leaving the
    // editor stuck open.
    if (!name || name === item.name) {
      setDraft(item.name);
      setIsEditing(false);
      return;
    }
    void onEditOption(type, item, name);
  };

  const cancel = () => {
    setDraft(item.name);
    setIsEditing(false);
  };

  return (
    <div className="flex items-center justify-between bg-white dark:bg-slate-900 px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-800">
      {isEditing ? (
        <input
          type="text"
          autoFocus
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            if (e.key === 'Escape') { e.preventDefault(); cancel(); }
          }}
          aria-label={`الاسم الجديد لـ ${item.name}`}
          className="flex-1 min-w-0 me-2 text-xs px-2 py-1 rounded-md border border-blue-400 dark:border-blue-500 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-bold focus:outline-none"
        />
      ) : (
        <span className="text-xs font-bold text-slate-700 dark:text-slate-300">{item.name}</span>
      )}

      <div className="flex items-center gap-1.5 shrink-0">
        {isEditing ? (
          <>
            <button
              type="button"
              onClick={commit}
              className="text-xs text-emerald-600 hover:text-emerald-700 p-1 cursor-pointer bg-transparent border-0"
              aria-label={`حفظ اسم ${item.name}`}
            >
              <Check className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={cancel}
              className="text-xs text-slate-400 hover:text-slate-600 p-1 cursor-pointer bg-transparent border-0"
              aria-label={`إلغاء تعديل ${item.name}`}
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </>
        ) : (
          <>
            {(onMoveUp || onMoveDown) && (
              <>
                <button
                  type="button"
                  onClick={onMoveUp}
                  disabled={!canMoveUp}
                  className="text-xs text-slate-500 hover:text-blue-600 disabled:opacity-30 disabled:pointer-events-none p-1 cursor-pointer bg-transparent border-0"
                  aria-label={`نقل ${item.name} لأعلى`}
                  title="نقل لأعلى"
                >
                  <ChevronUp className="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  onClick={onMoveDown}
                  disabled={!canMoveDown}
                  className="text-xs text-slate-500 hover:text-blue-600 disabled:opacity-30 disabled:pointer-events-none p-1 cursor-pointer bg-transparent border-0"
                  aria-label={`نقل ${item.name} لأسفل`}
                  title="نقل لأسفل"
                >
                  <ChevronDown className="w-3.5 h-3.5" />
                </button>
              </>
            )}
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); setIsEditing(true); }}
              className="text-xs text-blue-500 hover:text-blue-600 p-1 cursor-pointer bg-transparent border-0"
              aria-label={`تعديل ${item.name}`}
            >
              <Pencil className="w-3.5 h-3.5" />
            </button>
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); void onDeleteOption(type, item); }}
              className="text-xs text-rose-500 hover:text-rose-600 p-1 cursor-pointer bg-transparent border-0"
              aria-label={`حذف ${item.name}`}
            >
              <Trash2 className="w-3.5 h-3.5" />
            </button>
          </>
        )}
      </div>
    </div>
  );
}

export default function TeacherClassifications({
  subjects,
  selectedSubjects,
  setSelectedSubjects,
  showSubjectManager,
  setShowSubjectManager,
  newSubject,
  setNewSubject,

  sections,
  grades,
  selectedGrades,
  setSelectedGrades,
  showGradeManager,
  setShowGradeManager,
  newGrade,
  setNewGrade,

  onAddOption,
  onEditOption,
  onDeleteOption,
  onReorderOption,

  onAddSection,
  onEditSection,
  onDeleteSection,
  onReorderSection,

  isLoadingCatalogs
}: TeacherClassificationsProps) {

  // Which section is expanded in the manager view — at most one at a time,
  // so the panel never has to scroll through every section's grades at once.
  const [expandedSectionId, setExpandedSectionId] = useState<string | null>(null);
  const [newSectionName, setNewSectionName] = useState('');

  /** Swaps two grades WITHIN one section and persists that section's full new order. */
  const moveGrade = (sectionId: string, index: number, direction: 'up' | 'down') => {
    const sectionGrades = grades.filter(g => g.sectionId === sectionId);
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= sectionGrades.length) return;

    const reordered = [...sectionGrades];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];

    void onReorderOption(sectionId, reordered.map(g => g.id));
  };

  /** Swaps two sections and persists the full new (flat, unscoped) order. */
  const moveSection = (index: number, direction: 'up' | 'down') => {
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= sections.length) return;

    const reordered = [...sections];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];

    void onReorderSection(reordered.map(s => s.id));
  };

  const handleAddSection = () => {
    const name = newSectionName.trim();
    if (!name) return;
    void onAddSection(name);
    setNewSectionName('');
  };

  const handleAddGradeToSection = (sectionId: string) => {
    const name = newGrade.trim();
    if (!name) return;
    void onAddOption('grades', name, sectionId);
  };

  /**
   * `isLoadingCatalogs` used to gate ONLY the "لا توجد ... محفوظة بعد" empty
   * message inside the inline managers. The default selection view — the
   * one the admin actually lands on — rendered an empty <Select> and two empty
   * chip areas for the whole duration of the GET /catalogs/{type} calls,
   * with no indication anything was in flight. It read as "the platform has no
   * grades" rather than "still loading", which is the exact confusion the
   * separate empty-state copy above was written to avoid.
   */
  const chipSkeletons = (
    <div className="flex flex-wrap gap-2" aria-hidden="true">
      {['w-20', 'w-24', 'w-16', 'w-28'].map(w => (
        <Skeleton key={w} variant="block" className={`h-7 rounded-full ${w}`} />
      ))}
    </div>
  );

  const toggleSubjectSelect = (subj: string) => {
    setSelectedSubjects(prev =>
      prev.includes(subj) ? prev.filter(s => s !== subj) : [...prev, subj]
    );
  };

  const toggleGradeSelect = (grd: string) => {
    setSelectedGrades(prev =>
      prev.includes(grd) ? prev.filter(g => g !== grd) : [...prev, grd]
    );
  };

  return (
    <Card>
      <CardHeader
        icon={GraduationCap}
        title="التفاصيل الأكاديمية والتصنيفات (خيارات ديناميكية معدلة تلقائياً)"
        description="حدد التصنيفات، ويمكنك إضافة أو حذف الخيارات المحفوظة بالمنصة مباشرة من هنا (تُحفظ بشكل دائم لكل المستخدمين)"
      />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {/* Scientific Subject Column (Multi select, editable) */}
        <div className="bg-slate-50/50 dark:bg-slate-950/40 border border-slate-200/60 dark:border-slate-800 rounded-2xl p-5 space-y-4">
          <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2.5">
            <div className="flex items-center gap-2 text-slate-800 dark:text-slate-300">
              <BookOpen className="w-4 h-4 text-emerald-500" />
              <span className="text-xs font-black">المواد العلمية (اختيار متعدد)</span>
            </div>

            <button
              type="button"
              onClick={() => setShowSubjectManager(!showSubjectManager)}
              className="text-[10px] font-black text-blue-600 hover:underline dark:text-blue-400 cursor-pointer bg-transparent border-0"
            >
              {showSubjectManager ? 'إغلاق الإدارة' : 'إدارة المواد'}
            </button>
          </div>

          {!showSubjectManager ? (
            /* Multi select representation */
            <div className="space-y-2">
              <span className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">اختر المواد العلمية (اضغط للتحديد):</span>

              {isLoadingCatalogs && chipSkeletons}
              <div className="flex flex-wrap gap-2 max-h-48 overflow-y-auto pr-1">
                {subjects.map((subj) => {
                  const selected = selectedSubjects.includes(subj.name);
                  return (
                    <button
                      type="button"
                      key={subj.id}
                      onClick={() => toggleSubjectSelect(subj.name)}
                      className={`text-[10px] font-black px-2.5 py-1.5 rounded-full border transition-all cursor-pointer ${
                        selected
                          ? 'bg-emerald-600 text-white border-emerald-600'
                          : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-800 hover:bg-slate-100'
                      }`}
                    >
                      {subj.name}
                    </button>
                  );
                })}
              </div>
            </div>
          ) : (
            /* Subject Inline Manager (add/delete persisted options) */
            <div className="space-y-3 pt-1">

              <div className="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                {subjects.map((subj) => (
                  <CatalogOptionRow
                    key={subj.id}
                    type="subjects"
                    item={subj}
                    onEditOption={(_type, item, name) => onEditOption('subjects', item, name)}
                    onDeleteOption={(_type, item) => onDeleteOption('subjects', item)}
                  />
                ))}
                {!isLoadingCatalogs && subjects.length === 0 && (
                  <div className="text-[10px] text-slate-500 dark:text-slate-400 font-bold">لا توجد مواد محفوظة بعد.</div>
                )}
              </div>

              {/* Add Item form */}
              <div className="flex gap-1.5">
                <input
                  type="text"
                  placeholder="إضافة مادة جديدة"
                  value={newSubject}
                  onChange={(e) => setNewSubject(e.target.value)}
                  className="flex-1 text-[11px] px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-bold focus:outline-none focus:border-blue-500"
                />
                <button
                  type="button"
                  onClick={() => { void onAddOption('subjects', newSubject); }}
                  className="bg-blue-600 text-white rounded-lg p-2 hover:bg-blue-700 transition-colors border-0 cursor-pointer shrink-0"
                >
                  <PlusCircle className="w-4 h-4" />
                </button>
              </div>

            </div>
          )}
          <div className="text-[10px] text-slate-500 dark:text-slate-400 font-medium mt-1">تحديد المادة العلمية يقود إلى فهرسة المدرس في نتائج التصفية للمنصة.</div>
        </div>

        {/* Grade Category Column (Multi-select, editable, grouped by section) */}
        <div className="bg-slate-50/50 dark:bg-slate-950/40 border border-slate-200/60 dark:border-slate-800 rounded-2xl p-5 space-y-4">
          <div className="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-2.5">
            <div className="flex items-center gap-2 text-slate-800 dark:text-slate-300">
              <GraduationCap className="w-4 h-4 text-violet-500" />
              <span className="text-xs font-black">الصف الدراسي (اختيار متعدد)</span>
            </div>

            <button
              type="button"
              onClick={() => setShowGradeManager(!showGradeManager)}
              className="text-[10px] font-black text-blue-600 hover:underline dark:text-blue-400 cursor-pointer bg-transparent border-0"
            >
              {showGradeManager ? 'إغلاق الإدارة' : 'إدارة الصفوف والأقسام'}
            </button>
          </div>

          {!showGradeManager ? (
            <div className="space-y-3">
              <span className="text-[10px] font-bold text-slate-500 dark:text-slate-400 block">اختر الصفوف المستهدفة:</span>

              {isLoadingCatalogs && chipSkeletons}
              <div className="space-y-3 max-h-60 overflow-y-auto pr-1">
                {sections.map((section) => {
                  const sectionGrades = grades.filter(g => g.sectionId === section.id);
                  if (sectionGrades.length === 0) return null;
                  return (
                    <div key={section.id} className="space-y-1.5">
                      <span className="text-[10px] font-black text-violet-600 dark:text-violet-400 flex items-center gap-1">
                        <Layers className="w-3 h-3" />
                        {section.name}
                      </span>
                      <div className="flex flex-wrap gap-2">
                        {sectionGrades.map((grd) => {
                          const selected = selectedGrades.includes(grd.name);
                          return (
                            <button
                              type="button"
                              key={grd.id}
                              onClick={() => toggleGradeSelect(grd.name)}
                              className={`text-[10px] font-black px-2.5 py-1.5 rounded-full border transition-all cursor-pointer ${
                                selected
                                  ? 'bg-violet-600 text-white border-violet-600'
                                  : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-800 hover:bg-slate-100'
                              }`}
                            >
                              {grd.name}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ) : (
            /* Sections + grades inline manager (two-level accordion) */
            <div className="space-y-3 pt-1">

              <div className="space-y-1.5 max-h-72 overflow-y-auto pr-1">
                {sections.map((section, index) => {
                  const isExpanded = expandedSectionId === section.id;
                  const sectionGrades = grades.filter(g => g.sectionId === section.id);

                  return (
                    <div key={section.id} className="space-y-1.5">
                      <div
                        onClick={() => setExpandedSectionId(isExpanded ? null : section.id)}
                        className="cursor-pointer"
                      >
                        <CatalogOptionRow
                          type="grade_sections"
                          item={section}
                          onEditOption={(_type, item, name) => onEditSection(item, name)}
                          onDeleteOption={(_type, item) => onDeleteSection(item)}
                          onMoveUp={() => moveSection(index, 'up')}
                          onMoveDown={() => moveSection(index, 'down')}
                          canMoveUp={index > 0}
                          canMoveDown={index < sections.length - 1}
                        />
                      </div>

                      {isExpanded && (
                        <div className="ms-4 ps-3 border-e-2 border-violet-200 dark:border-violet-900/40 space-y-1.5">
                          {sectionGrades.map((grd, gIndex) => (
                            <CatalogOptionRow
                              key={grd.id}
                              type="grades"
                              item={grd}
                              onEditOption={(_type, item, name) => onEditOption('grades', item, name)}
                              onDeleteOption={(_type, item) => onDeleteOption('grades', item)}
                              onMoveUp={() => moveGrade(section.id, gIndex, 'up')}
                              onMoveDown={() => moveGrade(section.id, gIndex, 'down')}
                              canMoveUp={gIndex > 0}
                              canMoveDown={gIndex < sectionGrades.length - 1}
                            />
                          ))}
                          {sectionGrades.length === 0 && (
                            <div className="text-[10px] text-slate-500 dark:text-slate-400 font-bold">لا توجد صفوف في هذا القسم بعد.</div>
                          )}

                          <div className="flex gap-1.5 pt-1">
                            <input
                              type="text"
                              placeholder="إضافة صف جديد لهذا القسم"
                              value={newGrade}
                              onChange={(e) => setNewGrade(e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter') { e.preventDefault(); handleAddGradeToSection(section.id); }
                              }}
                              className="flex-1 text-[11px] px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-bold focus:outline-none focus:border-blue-500"
                            />
                            <button
                              type="button"
                              onClick={() => handleAddGradeToSection(section.id)}
                              className="bg-blue-600 text-white rounded-lg p-2 hover:bg-blue-700 transition-colors border-0 cursor-pointer shrink-0"
                            >
                              <PlusCircle className="w-4 h-4" />
                            </button>
                          </div>
                        </div>
                      )}
                    </div>
                  );
                })}
                {!isLoadingCatalogs && sections.length === 0 && (
                  <div className="text-[10px] text-slate-500 dark:text-slate-400 font-bold">لا توجد أقسام محفوظة بعد.</div>
                )}
              </div>

              {/* Add Section form */}
              <div className="flex gap-1.5 pt-1 border-t border-slate-200 dark:border-slate-800">
                <input
                  type="text"
                  placeholder="إضافة قسم جديد"
                  value={newSectionName}
                  onChange={(e) => setNewSectionName(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') { e.preventDefault(); handleAddSection(); }
                  }}
                  className="flex-1 text-[11px] px-3 py-2 mt-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-800 dark:text-white font-bold focus:outline-none focus:border-blue-500"
                />
                <button
                  type="button"
                  onClick={handleAddSection}
                  className="bg-violet-600 text-white rounded-lg p-2 mt-2 hover:bg-violet-700 transition-colors border-0 cursor-pointer shrink-0"
                >
                  <PlusCircle className="w-4 h-4" />
                </button>
              </div>
              <div className="text-[10px] text-slate-500 dark:text-slate-400 font-bold flex items-center gap-1">
                <ChevronLeft className="w-3 h-3" />
                اضغط على اسم أي قسم أعلاه لعرض/إخفاء صفوفه وإدارتها.
              </div>

            </div>
          )}
          <div className="text-[10px] text-slate-500 dark:text-slate-400 font-medium mt-1">هذه هي نفس الأقسام والصفوف التي يختار منها الطالب عند التسجيل — الترتيب هنا (أزرار أعلى/أسفل) هو ما يراه الطالب.</div>
        </div>

      </div>
    </Card>
  );
}
