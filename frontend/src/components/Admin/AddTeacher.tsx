import React, { useState, useRef, useEffect } from 'react';
import { motion } from 'motion/react';
import { useLocation, useNavigate } from 'react-router';
import { AddTeacherProps } from '../../types/index';
import { adminService } from '../../services/adminService';
import { catalogService, CatalogItem, CatalogType } from '../../services/catalogService';
import TeacherGeneralInfo from './AddTeacherComponents/TeacherGeneralInfo';
import TeacherClassifications from './AddTeacherComponents/TeacherClassifications';
import TeacherWhatsAppSupport from './AddTeacherComponents/TeacherWhatsAppSupport';
import TeacherMediaUpload from './AddTeacherComponents/TeacherMediaUpload';
import TeacherSocialLinks from './AddTeacherComponents/TeacherSocialLinks';



import {
  ArrowLeft, CheckCircle2, UserPlus, GraduationCap, MessageCircle, Upload, Share2, Sparkles,
} from 'lucide-react';
import { Card, PageHeader, Button } from '../UI';



import { useCenteredNotification } from '../../context/CenteredNotificationContext';
import { validateImageFile } from '../../utils/fileValidation';
import { uploadApiService } from '../../services/uploadApiService';

/**
 * Purely decorative — the five form sections in display order, each with the
 * one accent color that identifies it throughout the page (the top step
 * rail, and the numbered label repeated directly above its own card).
 * Carries no behaviour: reordering this array only changes what the rail
 * shows, never which section renders where (that stays hard-coded in the
 * JSX below, exactly as it already was).
 */
const FORM_STEPS = [
  { label: 'البيانات الأساسية', icon: UserPlus, color: 'blue' as const },
  { label: 'التصنيفات الأكاديمية', icon: GraduationCap, color: 'violet' as const },
  { label: 'دعم واتساب', icon: MessageCircle, color: 'emerald' as const },
  { label: 'الوسائط', icon: Upload, color: 'amber' as const },
  { label: 'الروابط الاجتماعية', icon: Share2, color: 'sky' as const },
];

const STEP_TONES = {
  blue: { bg: 'bg-blue-500', text: 'text-blue-600 dark:text-blue-400', ring: 'shadow-blue-500/30', line: 'from-blue-500/50' },
  violet: { bg: 'bg-violet-500', text: 'text-violet-600 dark:text-violet-400', ring: 'shadow-violet-500/30', line: 'from-violet-500/50' },
  emerald: { bg: 'bg-emerald-500', text: 'text-emerald-600 dark:text-emerald-400', ring: 'shadow-emerald-500/30', line: 'from-emerald-500/50' },
  amber: { bg: 'bg-amber-500', text: 'text-amber-600 dark:text-amber-400', ring: 'shadow-amber-500/30', line: 'from-amber-500/50' },
  sky: { bg: 'bg-sky-500', text: 'text-sky-600 dark:text-sky-400', ring: 'shadow-sky-500/30', line: 'from-sky-500/50' },
} as const;

/** One numbered, color-coded badge — reused by the top rail and by each section's own label. */
function StepBadge({ index, size = 'md' }: { index: number; size?: 'sm' | 'md' }) {
  const step = FORM_STEPS[index];
  const tone = STEP_TONES[step.color];
  const Icon = step.icon;
  const dims = size === 'sm' ? 'w-8 h-8' : 'w-10 h-10';
  return (
    <div className={`shrink-0 ${dims} rounded-2xl ${tone.bg} text-white flex items-center justify-center shadow-lg ${tone.ring}`}>
      <Icon className={size === 'sm' ? 'w-4 h-4' : 'w-5 h-5'} aria-hidden="true" />
    </div>
  );
}

// `onLogout` / `onAddTeacher` / `onUpdateTeacher` remain on AddTeacherProps
// (the interface is shared) but are deliberately not destructured here: this
// page persists through adminService.createTeacher/updateTeacher and logs out
// via the shared AdminSystem header, so none of the three callbacks is used.
export default function AddTeacher({ onNavigateHome }: AddTeacherProps) {
  const { notify } = useCenteredNotification();
  const location = useLocation();
  const navigate = useNavigate();

  // --- Admin-managed option lists for the form selects (subjects / grades).
  //
  // These used to be client-side-only React state seeded from
  // `src/constants/{subjects,grades}.ts`, so every add/delete the admin
  // performed here was thrown away the moment the form closed. They are now
  // real persisted rows fetched from GET /catalogs/{type}, and add/delete
  // call POST/DELETE on the same resource — the change is visible to every
  // future admin session. ---
  const [subjects, setSubjects] = useState<CatalogItem[]>([]);
  const [newSubject, setNewSubject] = useState('');
  const [showSubjectManager, setShowSubjectManager] = useState(false);

  const [sections, setSections] = useState<CatalogItem[]>([]);
  const [grades, setGrades] = useState<CatalogItem[]>([]);
  const [newGrade, setNewGrade] = useState('');
  const [showGradeManager, setShowGradeManager] = useState(false);

  const [isLoadingCatalogs, setIsLoadingCatalogs] = useState(true);

  // --- TEACHER FORM INITIAL STATE ---
  const [teacherName, setTeacherName] = useState('');
  const [teacherPhone, setTeacherPhone] = useState('');
  const [teacherUsername, setTeacherUsername] = useState('');
  const [teacherPassword, setTeacherPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  // "زر تفعيل نظام السنتر" — absent = false on create (StoreTeacherRequest's
  // own default), so a fresh add starts unchecked; edit mode hydrates it
  // below from editTeacher.centerEnabled (TeacherResource).
  const [centerEnabled, setCenterEnabled] = useState(false);

  // Selected parameters
  const [selectedSubjects, setSelectedSubjects] = useState<string[]>([]);
  const [selectedGrades, setSelectedGrades] = useState<string[]>([]);

  // WhatsApp Support dynamic entries
  const [whatsappNumbers, setWhatsappNumbers] = useState<string[]>([]);
  const [newWhatsappInput, setNewWhatsappInput] = useState('');

  // Local preview state (base64 data URLs) shown before the file is uploaded
  // through uploadApiService to POST /uploads.
  const [cardImage, setCardImage] = useState<string>('');
  const [bannerImage, setBannerImage] = useState<string>('');

  const cardFileRef = useRef<HTMLInputElement>(null);
  const bannerFileRef = useRef<HTMLInputElement>(null);

  // Dynamic social links toggle with url state
  const [socialLinks, setSocialLinks] = useState({
    facebook: { url: 'https://facebook.com/', enabled: true },
    youtube: { url: 'https://youtube.com/', enabled: true },
    tiktok: { url: 'https://tiktok.com/', enabled: false },
    whatsapp: { url: 'https://wa.me/', enabled: true },
    telegram: { url: 'https://t.me/', enabled: false },
  });

  const editTeacher = location.state?.editTeacher;
  const isEditMode = !!editTeacher;

  // Load the two persisted catalogs once. Runs before/independently of the
  // edit-mode hydration below, which only ever sets SELECTED values — the
  // selected names are plain strings matched against these lists by name, so
  // the two effects cannot race into an inconsistent state.
  useEffect(() => {
    let cancelled = false;

    (async () => {
      try {
        const [sectionItems, gradeItems, subjectItems] = await Promise.all([
          catalogService.list('grade_sections'),
          catalogService.list('grades'),
          catalogService.list('subjects'),
        ]);
        if (cancelled) return;
        setSections(sectionItems);
        setGrades(gradeItems);
        setSubjects(subjectItems);
      } catch {
        if (!cancelled) {
          notify('تعذر تحميل قوائم التصنيفات من الخادم', 'error');
        }
      } finally {
        if (!cancelled) setIsLoadingCatalogs(false);
      }
    })();

    return () => { cancelled = true; };
  }, [notify]);

  /**
   * Persist a new subject/grade option, then reflect it in the open form.
   * `sectionId` is required for `type === 'grades'` (open decision 10.12).
   */
  const handleAddCatalogOption = async (type: CatalogType, rawName: string, sectionId?: string) => {
    const name = rawName.trim();
    if (!name) return;

    try {
      const created = await catalogService.create(type, name, sectionId);

      if (type === 'grades') {
        // Grades are NOT re-sorted alphabetically — the server already
        // placed this row at the end of its section's manual order
        // (CatalogType::Grades->creationAttributes()), so appending it here
        // keeps the client in sync with that real position.
        setGrades(prev => [...prev, created]);
        setNewGrade('');
      }
      if (type === 'subjects') {
        setSubjects(prev => [...prev, created].sort((a, b) => a.name.localeCompare(b.name, 'ar')));
        setNewSubject('');
      }

      notify('تمت الإضافة وحفظها بنجاح', 'success');
    } catch (err: any) {
      // 422 duplicate / too long carry a field message; anything else falls
      // back to the envelope's `message`.
      const fieldError = err.response?.data?.data?.errors?.name?.[0] || err.response?.data?.data?.errors?.section_id?.[0];
      notify(fieldError || err.response?.data?.message || 'تعذر إضافة الخيار', 'error');
    }
  };

  /**
   * Rename a catalog option in place.
   *
   * This is the operation that used to be unavailable. A rename is safe now
   * because the server cascades it: it rewrites the old name inside every
   * `teachers.grades`/`.subjects` JSON array in the same transaction, so no
   * teacher is left carrying a name the catalog no longer has. Courses need no
   * cascade at all — they reference the catalog by foreign key.
   *
   * ┌──────────────────────────────────────────────────────────────────────┐
   * │ THE OPEN FORM'S OWN SELECTION IS REMAPPED TOO, NOT JUST THE LIST.    │
   * │                                                                      │
   * │ `selectedGrades` / `selectedSubjects` hold                            │
   * │ NAME STRINGS, matched against the catalog by name. Renaming only the  │
   * │ `grades`/`subjects` list would leave the admin's current selection    │
   * │ pointing at a name that no longer exists — the chip would silently    │
   * │ deselect itself, and saving would persist the stale name straight     │
   * │ back onto the teacher the server just finished cleaning up. This is   │
   * │ the client-side half of the same cascade.                            │
   * └──────────────────────────────────────────────────────────────────────┘
   */
  const handleEditCatalogOption = async (type: CatalogType, item: CatalogItem, rawName: string) => {
    const name = rawName.trim();
    if (!name || name === item.name) return;

    try {
      const updated = await catalogService.rename(type, item.id, name);
      const remap = (prev: string[]) => prev.map(v => (v === item.name ? updated.name : v));

      if (type === 'grades') {
        // In place, not re-sorted — a grade's manual `order` is independent
        // of its name, so renaming must never move it in the list.
        setGrades(prev => prev.map(o => (o.id === item.id ? updated : o)));
        setSelectedGrades(remap);
      }
      if (type === 'subjects') {
        setSubjects(prev =>
          prev
            .map(o => (o.id === item.id ? updated : o))
            .sort((a, b) => a.name.localeCompare(b.name, 'ar'))
        );
        setSelectedSubjects(remap);
      }

      notify('تم تعديل الاسم وحفظه بنجاح', 'success');
    } catch (err: any) {
      // 422 duplicate / too long carry a field message; anything else falls
      // back to the envelope's `message`.
      const fieldError = err.response?.data?.data?.errors?.name?.[0];
      notify(fieldError || err.response?.data?.message || 'تعذر تعديل الخيار', 'error');
    }
  };

  /**
   * Delete a catalog option. The server answers 409 when a teacher or a live
   * course still references it, so the entry is only removed from the local
   * list once the delete actually succeeded.
   */
  const handleDeleteCatalogOption = async (type: CatalogType, item: CatalogItem) => {
    try {
      await catalogService.remove(type, item.id);

      if (type === 'grades') {
        setGrades(prev => prev.filter(g => g.id !== item.id));
        setSelectedGrades(prev => prev.filter(g => g !== item.name));
      }
      if (type === 'subjects') {
        setSubjects(prev => prev.filter(s => s.id !== item.id));
        setSelectedSubjects(prev => prev.filter(s => s !== item.name));
      }

      notify('تم حذف الخيار بنجاح', 'success');
    } catch (err: any) {
      notify(err.response?.data?.message || 'تعذر حذف الخيار', 'error');
    }
  };

  /**
   * Persist a full reorder of ONE SECTION's grades. `orderedIds` is always
   * that section's complete list (TeacherClassifications computes it from
   * the current `grades` state before calling this), so the server's
   * exact-permutation check always passes here — a genuine mismatch would be
   * a real bug, not a race, and surfaces as the usual error toast.
   *
   * The returned items cover only that one section, so they REPLACE that
   * section's slice of `grades` rather than the whole array — otherwise
   * every other section's grades would be dropped from local state.
   */
  const handleReorderOption = async (sectionId: string, orderedIds: string[]) => {
    try {
      const items = await catalogService.reorder('grades', orderedIds, sectionId);
      setGrades(prev => [...prev.filter(g => g.sectionId !== sectionId), ...items]);
    } catch (err: any) {
      notify(err.response?.data?.message || 'تعذر إعادة الترتيب', 'error');
    }
  };

  /** Persist a new section (open decision 10.12). */
  const handleAddSection = async (rawName: string) => {
    const name = rawName.trim();
    if (!name) return;

    try {
      const created = await catalogService.create('grade_sections', name);
      setSections(prev => [...prev, created]);
      notify('تمت إضافة القسم وحفظه بنجاح', 'success');
    } catch (err: any) {
      const fieldError = err.response?.data?.data?.errors?.name?.[0];
      notify(fieldError || err.response?.data?.message || 'تعذر إضافة القسم', 'error');
    }
  };

  /** Rename a section in place — never reassigns its grades. */
  const handleEditSection = async (item: CatalogItem, rawName: string) => {
    const name = rawName.trim();
    if (!name || name === item.name) return;

    try {
      const updated = await catalogService.rename('grade_sections', item.id, name);
      setSections(prev => prev.map(s => (s.id === item.id ? updated : s)));
      notify('تم تعديل اسم القسم وحفظه بنجاح', 'success');
    } catch (err: any) {
      const fieldError = err.response?.data?.data?.errors?.name?.[0];
      notify(fieldError || err.response?.data?.message || 'تعذر تعديل القسم', 'error');
    }
  };

  /** Delete a section. The server answers 409 while it still holds grades. */
  const handleDeleteSection = async (item: CatalogItem) => {
    try {
      await catalogService.remove('grade_sections', item.id);
      setSections(prev => prev.filter(s => s.id !== item.id));
      notify('تم حذف القسم بنجاح', 'success');
    } catch (err: any) {
      notify(err.response?.data?.message || 'تعذر حذف القسم', 'error');
    }
  };

  /** Persist a full reorder of the flat, unscoped sections list. */
  const handleReorderSection = async (orderedIds: string[]) => {
    try {
      const items = await catalogService.reorder('grade_sections', orderedIds);
      setSections(items);
    } catch (err: any) {
      notify(err.response?.data?.message || 'تعذر إعادة ترتيب الأقسام', 'error');
    }
  };

  useEffect(() => {
    if (editTeacher) {
      setTeacherName(editTeacher.name || '');
      setTeacherPhone(editTeacher.phone || '');
      setTeacherUsername(editTeacher.username || editTeacher.id || '');
      setTeacherPassword('');
      setCenterEnabled(editTeacher.centerEnabled === true);

      if (editTeacher.subjects && editTeacher.subjects.length > 0) {
        setSelectedSubjects(editTeacher.subjects);
      } else if (editTeacher.role) {
        const parsedSubjects = subjects
          .map(sub => sub.name)
          .filter(name => editTeacher.role.includes(name));
        setSelectedSubjects(parsedSubjects.length > 0 ? parsedSubjects : [editTeacher.role]);
      } else {
        setSelectedSubjects([]);
      }

      setSelectedGrades(editTeacher.grades || []);
      setWhatsappNumbers(editTeacher.whatsappNumbers || []);
      setCardImage(editTeacher.image || editTeacher.avatar_url || '');
      setBannerImage(editTeacher.bannerImage || editTeacher.banner_url || '');

      const updatedSocial = {
        facebook: { url: 'https://facebook.com/', enabled: false },
        youtube: { url: 'https://youtube.com/', enabled: false },
        tiktok: { url: 'https://tiktok.com/', enabled: false },
        whatsapp: { url: 'https://wa.me/', enabled: false },
        telegram: { url: 'https://t.me/', enabled: false },
      };

      if (editTeacher.socialLinks) {
        editTeacher.socialLinks.forEach((item: any) => {
          const plat = item.platform as keyof typeof updatedSocial;
          if (updatedSocial[plat]) {
            updatedSocial[plat] = {
              url: item.url || '',
              enabled: true
            };
          }
        });
      }
      setSocialLinks(updatedSocial);
    }
  }, [editTeacher]);

  // --- FORM FUNCTIONS ---
  const toggleSocialEnabled = (platform: keyof typeof socialLinks) => {
    setSocialLinks(prev => ({
      ...prev,
      [platform]: {
        ...prev[platform],
        enabled: !prev[platform].enabled
      }
    }));
  };

  const handleSocialUrlChange = (platform: keyof typeof socialLinks, value: string) => {
    setSocialLinks(prev => ({
      ...prev,
      [platform]: {
        ...prev[platform],
        url: value
      }
    }));
  };

  const handleAddWhatsapp = () => {
    if (newWhatsappInput.trim()) {
      setWhatsappNumbers([...whatsappNumbers, newWhatsappInput.trim()]);
      setNewWhatsappInput('');
    }
  };

  const handleRemoveWhatsapp = (index: number) => {
    setWhatsappNumbers(whatsappNumbers.filter((_, idx) => idx !== index));
  };

  const handleCardImageUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const validation = validateImageFile(file);
    if (!validation.valid) {
      notify(validation.error!, 'error');
      e.target.value = '';
      return;
    }
    try {
      setCardImage(await uploadApiService.uploadPublicImage(file, 'avatar'));
    } catch {
      notify('حدث خطأ أثناء رفع صورة الكارد', 'error');
      e.target.value = '';
    }
  };

  const handleBannerImageUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    const validation = validateImageFile(file);
    if (!validation.valid) {
      notify(validation.error!, 'error');
      e.target.value = '';
      return;
    }
    try {
      setBannerImage(await uploadApiService.uploadPublicImage(file, 'banner'));
    } catch {
      notify('حدث خطأ أثناء رفع صورة البانر', 'error');
      e.target.value = '';
    }
  };

  const handleFormSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!teacherName || !teacherPhone || !teacherUsername || (!isEditMode && !teacherPassword)) {
      notify('الرجاء تعبئة كافة الحقول الأساسية المطلوبة', 'error');
      return;
    }

    const payload: any = {
      username: teacherUsername,
      fullName: teacherName,
      phone: teacherPhone,
      // Real derived address: the form has no email field, and
      // TeacherService::create applies the identical `username@sanad.edu`
      // default server-side when none is sent. Not placeholder data.
      email: `${teacherUsername}@sanad.edu`,
      bio: selectedSubjects.join('، ') + ' - ' + selectedGrades.join('، '),
      subjects: selectedSubjects,
      grades: selectedGrades,
      whatsappNumbers: whatsappNumbers,
      avatarUrl: cardImage,
      bannerUrl: bannerImage,
      centerEnabled,
      socialLinks: Object.entries(socialLinks)
        .filter(([_, val]) => (val as any).enabled)
        .map(([platform, val]) => ({ platform, url: (val as any).url }))
    };

    if (teacherPassword) {
      payload.password = teacherPassword;
    }

    try {
      if (isEditMode && editTeacher) {
        await adminService.updateTeacher(editTeacher.id, payload);
      } else {
        await adminService.createTeacher(payload);
      }

      navigate('/admin/manage-teachers', {
        state: { 
          toast: { 
            text: isEditMode ? `تم تحديث بيانات المدرس "${payload.fullName}" بنجاح!` : `تم إضافة المدرس "${payload.fullName}" بنجاح!`, 
            type: 'success' 
          } 
        } 
      });
    } catch (err: any) {
      console.error('Failed to save teacher:', err);
      notify(err.response?.data?.message || 'فشل في حفظ بيانات المدرس. يرجى المحاولة مرة أخرى.', 'error');
    }
  };

  return (
    <motion.div
      key="admin_add_teacher_section"
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: -15 }}
      transition={{ duration: 0.2 }}
      className="relative space-y-8"
    >
      {/* Decorative background glows — same low-opacity blurred-blob
          technique already used on LoginPage.tsx/SignupPage.tsx, reused here
          rather than inventing a new visual language. */}
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-emerald-500/6 dark:bg-emerald-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Header breadcrumb */}
      <PageHeader
        className="mb-0"
        icon={UserPlus}
        breadcrumbs={[{ label: 'إدارة المدرسين' }, { label: 'منظومة التسجيل العليا' }]}
        title={isEditMode ? 'تعديل بيانات المعلم' : 'إضافة مدرس جديد'}
        description={isEditMode ? 'تعديل بيانات المعلم الشخصية' : 'تسجيل حساب معلم جديد'}
        actions={
          <Button variant="outline" trailingIcon={ArrowLeft} onClick={onNavigateHome}>
            رجوع للرئيسية
          </Button>
        }
      />


      {/* Main Form Entry */}
      <form onSubmit={handleFormSubmit} className="space-y-8 text-right">

        {[
          <TeacherGeneralInfo
            key="general"
            teacherName={teacherName}
            setTeacherName={setTeacherName}
            teacherPhone={teacherPhone}
            setTeacherPhone={setTeacherPhone}
            teacherUsername={teacherUsername}
            setTeacherUsername={setTeacherUsername}
            teacherPassword={teacherPassword}
            setTeacherPassword={setTeacherPassword}
            showPassword={showPassword}
            setShowPassword={setShowPassword}
            isEditMode={isEditMode}
            centerEnabled={centerEnabled}
            setCenterEnabled={setCenterEnabled}
          />,
          <TeacherClassifications
            key="classifications"
            subjects={subjects}
            selectedSubjects={selectedSubjects}
            setSelectedSubjects={setSelectedSubjects}
            showSubjectManager={showSubjectManager}
            setShowSubjectManager={setShowSubjectManager}
            newSubject={newSubject}
            setNewSubject={setNewSubject}
            sections={sections}
            grades={grades}
            selectedGrades={selectedGrades}
            setSelectedGrades={setSelectedGrades}
            showGradeManager={showGradeManager}
            setShowGradeManager={setShowGradeManager}
            newGrade={newGrade}
            setNewGrade={setNewGrade}
            onAddOption={handleAddCatalogOption}
            onEditOption={handleEditCatalogOption}
            onDeleteOption={handleDeleteCatalogOption}
            onReorderOption={handleReorderOption}
            onAddSection={handleAddSection}
            onEditSection={handleEditSection}
            onDeleteSection={handleDeleteSection}
            onReorderSection={handleReorderSection}
            isLoadingCatalogs={isLoadingCatalogs}
          />,
          <TeacherWhatsAppSupport
            key="whatsapp"
            newWhatsappInput={newWhatsappInput}
            setNewWhatsappInput={setNewWhatsappInput}
            whatsappNumbers={whatsappNumbers}
            handleAddWhatsapp={handleAddWhatsapp}
            handleRemoveWhatsapp={handleRemoveWhatsapp}
          />,
          <TeacherMediaUpload
            key="media"
            cardImage={cardImage}
            setCardImage={setCardImage}
            bannerImage={bannerImage}
            setBannerImage={setBannerImage}
            cardFileRef={cardFileRef}
            bannerFileRef={bannerFileRef}
            handleCardImageUpload={handleCardImageUpload}
            handleBannerImageUpload={handleBannerImageUpload}
          />,
          <TeacherSocialLinks
            key="social"
            socialLinks={socialLinks}
            handleSocialUrlChange={handleSocialUrlChange}
            toggleSocialEnabled={toggleSocialEnabled}
          />,
        ].map((section, i) => (
          <motion.div
            key={FORM_STEPS[i].label}
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.08, duration: 0.35 }}
          >
            <div className="flex items-center gap-3 mb-3 px-1">
              <StepBadge index={i} />
              <span className={`text-sm font-black ${STEP_TONES[FORM_STEPS[i].color].text}`}>
                {i + 1}. {FORM_STEPS[i].label}
              </span>
              <div className={`h-px flex-1 bg-gradient-to-l ${STEP_TONES[FORM_STEPS[i].color].line} to-transparent`} />
            </div>
            {section}
          </motion.div>
        ))}

        {/* SAVE BUTTON */}
        <motion.div
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: FORM_STEPS.length * 0.08, duration: 0.35 }}
        >
          <Card
            radius="panel"
            className="bg-gradient-to-l from-blue-50 via-slate-50 to-slate-50 dark:from-blue-500/10 dark:via-slate-900 dark:to-slate-900 border-blue-100 dark:border-blue-500/20 flex flex-col sm:flex-row items-center justify-between gap-4"
          >
            <div className="flex items-center gap-2.5 text-slate-500 dark:text-slate-400 text-xs font-bold">
              <Sparkles className="w-4 h-4 text-blue-500 shrink-0" />
              <span>راجع البيانات أعلاه ثم احفظ لإتمام {isEditMode ? 'التحديث' : 'الإضافة'}.</span>
            </div>
            <Button
              type="submit"
              variant="success"
              size="lg"
              icon={CheckCircle2}
              className="shrink-0 shadow-lg shadow-emerald-500/20 hover:shadow-xl hover:shadow-emerald-500/30 transition-shadow"
            >
              {isEditMode ? 'حفظ التغييرات وتحديث بيانات المدرس' : 'حفظ وإضافة المدرس للمنصة'}
            </Button>
          </Card>
        </motion.div>

      </form>
    </motion.div>
  );
}
