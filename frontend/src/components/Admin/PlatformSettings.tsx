import React, { useState, useEffect } from 'react';
import { formatDate } from '../../utils/dateUtils';
import { motion, AnimatePresence } from 'motion/react';
import { SocialLink, SupportContact } from '../../types/index';
import { settingsApiService } from '../../services/settingsApiService';
import { useCenteredNotification } from '../../context/CenteredNotificationContext';

// NOTE: the "شعار المنصة" tab was removed from this page, and so was the
// `platform_logo` settings key it used to write. The platform logo is a STATIC
// asset — frontend/public/branding/logo.png (with favicon.png beside it) — and
// that folder is now the only source of truth; POST /settings rejects the key
// outright, so this page must not send it. `validateImageFile`, and the
// Image/Upload/Trash2/RefreshCw icons, were used only by that tab and are no
// longer imported here (the util itself is still used by other components and
// was left in place).
import { Settings, Save, Shield, FileText, Phone, Mail, Clock, Facebook, Instagram, Twitter, Youtube, Linkedin, MessageCircle, Link as LinkIcon, Video, Ghost, Plus, X, MousePointerClick } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
  PageHeader,
  Card,
  CardHeader,
  Button,
  Input,
  Textarea,
  FormField,
  cn,
} from '../UI';

/**
 * The platform's on/off switch. This is a *switch*, not a checkbox, so it is
 * deliberately NOT the design system's `Checkbox` primitive — it keeps the
 * exact `peer` markup this page already shipped (including the RTL-correct
 * `after:right-[2px]` / `peer-checked:after:-translate-x-full` travel), and
 * only lifts the repeated class string into one place. Purely presentational:
 * `checked`/`onChange` are passed straight through to the same state setters
 * that were wired inline before.
 */
function SettingsSwitch({
  checked,
  onChange,
  label,
}: {
  // Widened to `| undefined` on purpose: `SocialLink.enabled` /
  // `SupportContact.enabled` are optional in the shared types, and the markup
  // this replaces passed the raw field to `checked` exactly the same way.
  checked: boolean | undefined;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  label?: string;
}) {
  return (
    <label className="relative inline-flex items-center cursor-pointer shrink-0">
      <input
        type="checkbox"
        className="sr-only peer"
        checked={checked}
        onChange={onChange}
        aria-label={label}
      />
      <div className="w-11 h-6 bg-slate-200 peer-focus-visible:outline-none peer-focus-visible:ring-4 peer-focus-visible:ring-blue-500/30 rounded-full peer dark:bg-slate-700 peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:right-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-slate-600 peer-checked:bg-blue-600 transition-colors" />
    </label>
  );
}

/** Tinted callout wrapping a section's master enable/disable switch. */
function MasterToggleRow({
  title,
  description,
  checked,
  onChange,
}: {
  title: string;
  description: string;
  checked: boolean;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
}) {
  return (
    <div className="flex items-center justify-between gap-4 p-4 mb-6 rounded-2xl bg-blue-50/60 dark:bg-blue-500/5 border border-blue-100 dark:border-blue-500/20">
      <div className="min-w-0">
        <h4 className="text-sm sm:text-base font-bold text-blue-900 dark:text-blue-300 leading-tight">{title}</h4>
        <p className="text-xs font-medium text-blue-700/80 dark:text-blue-400/80 mt-1 leading-relaxed">
          {description}
        </p>
      </div>
      <SettingsSwitch checked={checked} onChange={onChange} label={title} />
    </div>
  );
}

/** Shared row shell for one social link / one support contact. */
function SettingRow({ icon: Icon, label, minLabelWidth, children }: {
  icon: any;
  label: string;
  minLabelWidth: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-center gap-4 p-4 rounded-2xl border border-slate-200/70 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40 transition-colors hover:border-slate-300 dark:hover:border-slate-700">
      <div className={cn('flex items-center gap-3', minLabelWidth)}>
        <div className="w-10 h-10 rounded-xl bg-white dark:bg-slate-900 shadow-sm border border-slate-200 dark:border-slate-700 flex items-center justify-center text-slate-500 dark:text-slate-400 shrink-0">
          <Icon className="w-4.5 h-4.5" />
        </div>
        <span className="text-sm font-bold text-slate-700 dark:text-slate-300">{label}</span>
      </div>
      {children}
    </div>
  );
}

// NOT seed/demo data despite the previous `mockSocialLinks` name: every entry
// carries an empty url and enabled:false. These two arrays are the catalogue of
// supported platforms/contact types and their lucide icons — icons cannot come
// from the API, so a saved row is matched back to its icon by platform/label
// below. Real values always come from GET /settings.
const SOCIAL_PLATFORM_CATALOG: SocialLink[] = [
  { id: '1', platform: 'Facebook', icon: Facebook, url: '', enabled: false, order: 1 },
  { id: '2', platform: 'Instagram', icon: Instagram, url: '', enabled: false, order: 2 },
  { id: '3', platform: 'X (Twitter)', icon: Twitter, url: '', enabled: false, order: 3 },
  { id: '4', platform: 'YouTube', icon: Youtube, url: '', enabled: false, order: 4 },
  { id: '5', platform: 'LinkedIn', icon: Linkedin, url: '', enabled: false, order: 5 },
  { id: '6', platform: 'WhatsApp', icon: MessageCircle, url: '', enabled: false, order: 6 },
  { id: '7', platform: 'Telegram', icon: MessageCircle, url: '', enabled: false, order: 7 },
  { id: '8', platform: 'TikTok', icon: Video, url: '', enabled: false, order: 8 },
  { id: '9', platform: 'Snapchat', icon: Ghost, url: '', enabled: false, order: 9 },
  { id: '10', platform: 'Discord', icon: MessageCircle, url: '', enabled: false, order: 10 },
  { id: '11', platform: 'Gmail', icon: Mail, url: '', enabled: false, order: 11 },
];

const SUPPORT_CONTACT_CATALOG: SupportContact[] = [
  { id: '3', type: 'أرقام واتساب', icon: MessageCircle, value: '', values: [], enabled: false, order: 1 },
];

interface PlatformSettingsProps {
  onLogout: () => void;
}

type SettingsTabId = 'social' | 'privacy' | 'terms' | 'support';
type SettingsTone = 'blue' | 'violet' | 'amber' | 'emerald';

const SETTINGS_TONE_STYLES: Record<SettingsTone, { chip: string; activeRail: string; railBar: string }> = {
  blue: {
    chip: 'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
    activeRail: 'bg-blue-50/70 dark:bg-blue-500/10 border-blue-300 dark:border-blue-500/40',
    railBar: 'bg-blue-500',
  },
  violet: {
    chip: 'bg-violet-50 dark:bg-violet-500/10 text-violet-600 dark:text-violet-400',
    activeRail: 'bg-violet-50/70 dark:bg-violet-500/10 border-violet-300 dark:border-violet-500/40',
    railBar: 'bg-violet-500',
  },
  amber: {
    chip: 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
    activeRail: 'bg-amber-50/70 dark:bg-amber-500/10 border-amber-300 dark:border-amber-500/40',
    railBar: 'bg-amber-500',
  },
  emerald: {
    chip: 'bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    activeRail: 'bg-emerald-50/70 dark:bg-emerald-500/10 border-emerald-300 dark:border-emerald-500/40',
    railBar: 'bg-emerald-500',
  },
};

/**
 * Same "persistent rail, buttons visible first, content appears after a
 * click" shape used by the add/edit-course workspace
 * (Instructor/CourseManagement.tsx's RailButton + WelcomeWorkspace) — applied
 * here to the four settings sections instead of the three course actions.
 */
function SettingsRailButton({
  icon: Icon,
  title,
  description,
  tone,
  active,
  unsaved,
  onSelect,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  tone: SettingsTone;
  active: boolean;
  unsaved: boolean;
  onSelect: () => void;
}) {
  const toneStyle = SETTINGS_TONE_STYLES[tone];
  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={active}
      className={cn(
        'group relative w-full flex items-center gap-3.5 p-4 rounded-2xl border text-right transition-all cursor-pointer overflow-hidden',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/40',
        active
          ? cn(toneStyle.activeRail, 'shadow-sm')
          : 'bg-white dark:bg-slate-900 border-slate-200/70 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800/60',
      )}
    >
      <span className={cn('absolute inset-y-0 right-0 w-1 transition-opacity', toneStyle.railBar, active ? 'opacity-100' : 'opacity-0')} />
      <div className={cn('shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-transform group-hover:scale-105', toneStyle.chip)}>
        <Icon className="w-5 h-5" aria-hidden="true" />
      </div>
      <div className="min-w-0 flex-1">
        <h3 className="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
          {title}
          {unsaved && (
            <span className="w-2 h-2 rounded-full bg-rose-500 shrink-0" title="تغييرات غير محفوظة" aria-label="تغييرات غير محفوظة" />
          )}
        </h3>
        <p className="text-xs font-medium text-slate-500 dark:text-slate-400 truncate">{description}</p>
      </div>
    </button>
  );
}

/** Placeholder shown in the workspace before any rail item is chosen. */
function SettingsWelcome() {
  return (
    <Card radius="panel" className="flex flex-col items-center justify-center text-center gap-4 py-16 border-dashed">
      <div className="w-16 h-16 rounded-2xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
        <MousePointerClick className="w-7 h-7" />
      </div>
      <div className="space-y-1.5">
        <h3 className="text-base font-black text-slate-800 dark:text-slate-100">اختر قسماً من القائمة الجانبية للبدء</h3>
        <p className="text-sm font-medium text-slate-500 dark:text-slate-400 max-w-sm">
          التواصل الاجتماعي، سياسة الخصوصية، الشروط والأحكام، أو الدعم والتواصل.
        </p>
      </div>
    </Card>
  );
}

const SETTINGS_SECTIONS: Array<{ id: SettingsTabId; icon: LucideIcon; title: string; description: string; tone: SettingsTone }> = [
  { id: 'social', icon: LinkIcon, title: 'التواصل الاجتماعي', description: 'روابط حسابات المنصة في التذييل', tone: 'blue' },
  { id: 'privacy', icon: Shield, title: 'سياسة الخصوصية', description: 'النص المعروض للزوار', tone: 'violet' },
  { id: 'terms', icon: FileText, title: 'الشروط والأحكام', description: 'النص المعروض للزوار', tone: 'amber' },
  { id: 'support', icon: Phone, title: 'الدعم والتواصل', description: 'أرقام وطرق التواصل مع الدعم', tone: 'emerald' },
];

export default function PlatformSettings(_props: PlatformSettingsProps) {
  const [activeTab, setActiveTab] = useState<SettingsTabId | null>(null);
  // States
  const [socialLinks, setSocialLinks] = useState<SocialLink[]>(SOCIAL_PLATFORM_CATALOG);
  const [supportContacts, setSupportContacts] = useState<SupportContact[]>(SUPPORT_CONTACT_CATALOG);
  const [privacyPolicy, setPrivacyPolicy] = useState('');
  const [termsConditions, setTermsConditions] = useState('');
  // Section master switches
  const [socialSectionEnabled, setSocialSectionEnabled] = useState(false);
  const [privacySectionEnabled, setPrivacySectionEnabled] = useState(false);
  const [termsSectionEnabled, setTermsSectionEnabled] = useState(false);
  const [supportSectionEnabled, setSupportSectionEnabled] = useState(false);

  // Unsaved Changes Tracking
  const [hasUnsavedChanges, setHasUnsavedChanges] = useState({
    social: false,
    privacy: false,
    terms: false,
    support: false
  });

  // Load from API on mount
  useEffect(() => {
    const fetchSettings = async () => {
      try {
        // Defaults matter on a fresh database: /settings answers `{}` when
        // nothing has been saved yet, and the bare destructure below then
        // made `settings.social_section_enabled` throw a TypeError that the
        // catch swallowed — the whole load silently aborted.
        const stored = (await settingsApiService.getSettings()) ?? {};
        const { settings = {}, socialLinks = [], supportNumbers = [] } = stored as any;

        if (settings.social_section_enabled !== undefined) setSocialSectionEnabled(settings.social_section_enabled === 'true');
        if (settings.privacy_section_enabled !== undefined) setPrivacySectionEnabled(settings.privacy_section_enabled === 'true');
        if (settings.terms_section_enabled !== undefined) setTermsSectionEnabled(settings.terms_section_enabled === 'true');
        if (settings.support_section_enabled !== undefined) setSupportSectionEnabled(settings.support_section_enabled === 'true');
        if (settings.privacy_policy) setPrivacyPolicy(settings.privacy_policy);
        if (settings.terms_conditions) setTermsConditions(settings.terms_conditions);

        // ROUND-TRIP FIX. handleSave() below persists these two arrays as
        // {platform,url,enabled,order} and {type,value,values,enabled,order},
        // and /settings is a plain JSON key-value store that hands back exactly
        // what was stored. This mapping, however, read `is_active`,
        // `order_index`, `label` and `number` — column names from a normalised
        // table API that does not exist here. Consequences, all reproduced
        // against the live endpoint:
        //   • every social link came back `enabled: undefined` → the whole
        //     social tab appeared switched OFF after a reload;
        //   • `c.label.includes(...)` threw a TypeError on `undefined`, and the
        //     surrounding catch swallowed it, so the support tab silently reset
        //     to empty defaults on every load after a save.
        // Both naming shapes are accepted now, so previously-saved rows in the
        // older shape still load.
        if (socialLinks && socialLinks.length > 0) {
          const mapped = socialLinks.map((l: any) => {
            const original = SOCIAL_PLATFORM_CATALOG.find(m => m.platform === l.platform);
            return {
              id: l.id ?? l.platform,
              platform: l.platform,
              url: l.url ?? '',
              enabled: l.enabled ?? l.is_active ?? false,
              order: l.order ?? l.order_index ?? 0,
              icon: original ? original.icon : LinkIcon
            };
          });
          setSocialLinks(mapped);
        }

        if (supportNumbers && supportNumbers.length > 0) {
          const mapped = supportNumbers.map((c: any) => {
            const type = c.type ?? c.label ?? '';
            const original = SUPPORT_CONTACT_CATALOG.find(m => m.type === type);
            const values: string[] = Array.isArray(c.values)
              ? c.values
              : (c.value ?? c.number ? String(c.value ?? c.number).split(',') : []);
            return {
              id: c.id ?? type,
              type,
              value: values.length === 1 ? values[0] : (c.value ?? ''),
              values: values.length > 1 || type.includes('واتساب') ? values : undefined,
              enabled: c.enabled ?? c.is_active ?? false,
              order: c.order ?? c.order_index ?? 0,
              icon: original ? original.icon : Phone
            };
          });
          setSupportContacts(mapped);
        }
      } catch (e) {
        console.error('Failed to load settings:', e);
      }
    };
    
    fetchSettings();
  }, []);

  // Handle unsaved changes warning
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (Object.values(hasUnsavedChanges).some(v => v)) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
  
  return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [hasUnsavedChanges]);

  const { notify } = useCenteredNotification();
  const showNotification = (message: string, type: 'success' | 'error' | 'info' = 'success') => {
    notify(message, type === 'error' ? 'error' : 'success');
  };

  const handleSave = async (tab: keyof typeof hasUnsavedChanges) => {
    try {
      // POST /settings is a key-value upsert: whatever object is sent under
      // the `settings` key REPLACES the stored one wholesale. Sending only
      // the active tab's keys therefore wiped every other tab's saved values
      // — saving the privacy tab silently deleted the social
      // toggle and the terms text. The full current state is always sent now,
      // so a save is a complete snapshot rather than a destructive patch.
      const payload: any = {
        settings: {
          social_section_enabled: socialSectionEnabled ? 'true' : 'false',
          privacy_section_enabled: privacySectionEnabled ? 'true' : 'false',
          terms_section_enabled: termsSectionEnabled ? 'true' : 'false',
          support_section_enabled: supportSectionEnabled ? 'true' : 'false',
          privacy_policy: privacyPolicy,
          terms_conditions: termsConditions,
        },
        socialLinks: socialLinks.map(({ platform, url, enabled, order }) => ({ platform, url, enabled, order })),
        supportNumbers: supportContacts.map(({ type, value, values, enabled, order }) => ({ type, value, values, enabled, order })),
      };

      await settingsApiService.updateSettings(payload);

      setHasUnsavedChanges(prev => ({ ...prev, [tab]: false }));
      showNotification('تم حفظ التغييرات بنجاح');
    } catch (e) {
      console.error('Save failed', e);
      showNotification('حدث خطأ أثناء حفظ التغييرات', 'error');
    }
  };

  // Render Social Media Settings
  const renderSocialSettings = () => (
    <>
      <CardHeader
        icon={LinkIcon}
        title="حسابات التواصل الاجتماعي"
        description="إدارة الروابط التي تظهر في تذييل الموقع (Footer)"
        action={
          <Button
            icon={Save}
            onClick={() => handleSave('social')}
            disabled={!hasUnsavedChanges.social}
            variant={hasUnsavedChanges.social ? 'primary' : 'secondary'}
            className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
          >
            حفظ التغييرات
          </Button>
        }
      />

      {/* Master Toggle */}
      <MasterToggleRow
        title="تفعيل قسم التواصل الاجتماعي في تذييل الموقع"
        description="عند إلغاء التفعيل، سيتم إخفاء قسم التواصل الاجتماعي بالكامل من تذييل الموقع."
        checked={socialSectionEnabled}
        onChange={(e) => {
          setSocialSectionEnabled(e.target.checked);
          setHasUnsavedChanges(prev => ({ ...prev, social: true }));
        }}
      />

      <div className="space-y-3">
        {/* Sort a COPY: Array.sort() mutates in place, and `socialLinks` is
            state — reordering it during render mutates state, which React 19's
            concurrent renderer does not support. `index` must then be resolved
            against the real state array, because every onChange below writes
            through `socialLinks[index]`; taking the position in the sorted view
            would target the wrong row whenever `order` disagrees with the
            stored array order. `id` is always populated (catalog literals and
            the `l.id ?? l.platform` load path) and is already the React key. */}
        {[...socialLinks].sort((a, b) => (a.order || 0) - (b.order || 0)).map((link) => {
          const index = socialLinks.findIndex(l => l.id === link.id);
          const Icon = link.icon;
          return (
            <SettingRow key={link.id} icon={Icon} label={link.platform} minLabelWidth="min-w-[150px]">
              <div className="flex-1">
                <Input
                  type="url"
                  size="sm"
                  value={link.url}
                  onChange={(e) => {
                    const newLinks = [...socialLinks];
                    newLinks[index].url = e.target.value;
                    setSocialLinks(newLinks);
                    setHasUnsavedChanges(prev => ({ ...prev, social: true }));
                  }}
                  placeholder={`رابط ${link.platform}`}
                  className="bg-white dark:bg-slate-900"
                  dir="ltr"
                />
              </div>

              <div className="flex items-center gap-4 sm:w-auto w-full justify-between sm:justify-end">
                <div className="flex items-center gap-2">
                  <label className="text-xs font-bold text-slate-500 dark:text-slate-400 whitespace-nowrap">الترتيب:</label>
                  <div className="w-16 shrink-0">
                    <Input
                      type="number"
                      size="sm"
                      value={link.order}
                      onChange={(e) => {
                        const newLinks = [...socialLinks];
                        newLinks[index].order = parseInt(e.target.value) || 0;
                        setSocialLinks(newLinks);
                        setHasUnsavedChanges(prev => ({ ...prev, social: true }));
                      }}
                      aria-label={`ترتيب ${link.platform}`}
                      className="text-center bg-white dark:bg-slate-900 px-1"
                    />
                  </div>
                </div>

                <SettingsSwitch
                  label={`تفعيل ${link.platform}`}
                  checked={link.enabled}
                  onChange={(e) => {
                    const newLinks = [...socialLinks];
                    newLinks[index].enabled = e.target.checked;
                    setSocialLinks(newLinks);
                    setHasUnsavedChanges(prev => ({ ...prev, social: true }));
                  }}
                />
              </div>
            </SettingRow>
          );
        })}
      </div>
    </>
  );

  // Render Policy/Terms Editor
  const renderEditor = (
    type: 'privacy' | 'terms', 
    title: string, 
    icon: any, 
    value: string, 
    setValue: (val: string) => void
  ) => {
    const Icon = icon;
    const isUnsaved = hasUnsavedChanges[type];

    return (
      <>
        <CardHeader
          icon={Icon}
          title={title}
          description={
            <span className="flex items-center gap-1.5">
              <Clock className="w-3.5 h-3.5 shrink-0" />
              آخر تحديث: {formatDate(Date.now())}
            </span>
          }
          action={
            <Button
              icon={Save}
              onClick={() => handleSave(type)}
              disabled={!isUnsaved}
              variant={isUnsaved ? 'primary' : 'secondary'}
              className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
            >
              حفظ التغييرات
            </Button>
          }
        />

        {/* Master Toggle */}
        <MasterToggleRow
          title={type === 'privacy' ? 'تفعيل رابط سياسة الخصوصية في تذييل الموقع' : 'تفعيل رابط الشروط والأحكام في تذييل الموقع'}
          description={
            type === 'privacy'
              ? 'عند إلغاء التفعيل، سيتم إخفاء رابط سياسة الخصوصية بالكامل من تذييل الموقع.'
              : 'عند إلغاء التفعيل، سيتم إخفاء رابط الشروط والأحكام بالكامل من تذييل الموقع.'
          }
          checked={type === 'privacy' ? privacySectionEnabled : termsSectionEnabled}
          onChange={(e) => {
            if (type === 'privacy') {
              setPrivacySectionEnabled(e.target.checked);
            } else {
              setTermsSectionEnabled(e.target.checked);
            }
            setHasUnsavedChanges(prev => ({ ...prev, [type]: true }));
          }}
        />

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Editor Side */}
          <FormField label="محرر النص">
            <Textarea
              value={value}
              onChange={(e) => {
                setValue(e.target.value);
                setHasUnsavedChanges(prev => ({ ...prev, [type]: true }));
              }}
              className="h-[500px] resize-none font-mono bg-slate-50 dark:bg-slate-800/60"
              placeholder={`اكتب ${title} هنا...`}
            />
          </FormField>

          {/* Preview Side */}
          <FormField label="معاينة مباشرة (Live Preview)">
            <div className="w-full h-[500px] p-6 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl overflow-y-auto custom-scrollbar">
              <div className="prose prose-slate dark:prose-invert max-w-none text-sm font-normal leading-relaxed whitespace-pre-wrap">
                {value}
              </div>
            </div>
          </FormField>
        </div>
      </>
    );
  };

  // Render Support Contacts
  const renderSupportSettings = () => (
    <>
      <CardHeader
        icon={Phone}
        title="معلومات الدعم الفني"
        description="إدارة أرقام وإيميلات التواصل الخاصة بخدمة العملاء"
        action={
          <Button
            icon={Save}
            onClick={() => handleSave('support')}
            disabled={!hasUnsavedChanges.support}
            variant={hasUnsavedChanges.support ? 'primary' : 'secondary'}
            className="shadow-lg shadow-blue-500/20 hover:shadow-xl hover:shadow-blue-500/30 transition-shadow"
          >
            حفظ التغييرات
          </Button>
        }
      />

      {/* Master Toggle */}
      <MasterToggleRow
        title="تفعيل قسم الدعم والتواصل في تذييل الموقع"
        description="عند إلغاء التفعيل، سيتم إخفاء قسم الدعم والتواصل بالكامل من تذييل الموقع."
        checked={supportSectionEnabled}
        onChange={(e) => {
          setSupportSectionEnabled(e.target.checked);
          setHasUnsavedChanges(prev => ({ ...prev, support: true }));
        }}
      />

      <div className="space-y-3">
        {/* Same in-place-sort-on-state fix as the social links block above. */}
        {[...supportContacts].sort((a, b) => (a.order || 0) - (b.order || 0)).map((contact) => {
          const index = supportContacts.findIndex(c => c.id === contact.id);
          const Icon = contact.icon;
          return (
            <SettingRow key={contact.id} icon={Icon} label={contact.type} minLabelWidth="min-w-[200px]">
              <div className="flex-1 space-y-2 min-w-0">
                {contact.values ? (
                  <>
                    {contact.values.map((val, vIndex) => (
                      <div key={vIndex} className="flex items-center gap-2">
                        <Input
                          type="text"
                          size="sm"
                          value={val}
                          onChange={(e) => {
                            const newContacts = [...supportContacts];
                            const newValues = [...(newContacts[index].values || [])];
                            newValues[vIndex] = e.target.value;
                            newContacts[index].values = newValues;
                            setSupportContacts(newContacts);
                            setHasUnsavedChanges(prev => ({ ...prev, support: true }));
                          }}
                          placeholder={`أدخل ${contact.type}`}
                          className="bg-white dark:bg-slate-900"
                          dir="ltr"
                        />
                        <Button
                          variant="ghost"
                          size="sm"
                          iconOnly
                          icon={X}
                          aria-label="حذف الرقم"
                          onClick={() => {
                            const newContacts = [...supportContacts];
                            const newValues = [...(newContacts[index].values || [])];
                            newValues.splice(vIndex, 1);
                            newContacts[index].values = newValues;
                            setSupportContacts(newContacts);
                            setHasUnsavedChanges(prev => ({ ...prev, support: true }));
                          }}
                          className="shrink-0 text-rose-500 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"
                        />
                      </div>
                    ))}
                    <Button
                      variant="ghost"
                      size="sm"
                      icon={Plus}
                      onClick={() => {
                        const newContacts = [...supportContacts];
                        newContacts[index].values = [...(newContacts[index].values || []), ''];
                        setSupportContacts(newContacts);
                        setHasUnsavedChanges(prev => ({ ...prev, support: true }));
                      }}
                      className="text-blue-600 hover:text-blue-700 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-500/10 px-2"
                    >
                      إضافة رقم جديد
                    </Button>

                    {contact.type.includes('واتساب') && (
                      <p className="text-xs text-emerald-700 dark:text-emerald-400 mt-3 font-medium bg-emerald-50/70 dark:bg-emerald-500/5 p-3 rounded-xl border border-emerald-100 dark:border-emerald-500/20 leading-relaxed">
                        💡 <strong className="font-bold">ملاحظة لكود الدولة:</strong> يمكنك إدخال الأرقام محلياً (مثل <span className="font-mono bg-white dark:bg-slate-800 px-1 py-0.5 rounded border border-emerald-200 dark:border-emerald-500/20" dir="ltr">01012345678</span>) وسيتعامل النظام تلقائياً لإضافة رمز الدولة (<span dir="ltr">+20</span>) لتسهيل فتحها مباشرة على تطبيق واتساب، أو يمكنك إدخال الرقم بكود الدولة مباشرة بدون علامة + (مثال: <span className="font-mono bg-white dark:bg-slate-800 px-1 py-0.5 rounded border border-emerald-200 dark:border-emerald-500/20" dir="ltr">201012345678</span> أو <span className="font-mono bg-white dark:bg-slate-800 px-1 py-0.5 rounded border border-emerald-200 dark:border-emerald-500/20" dir="ltr">9665xxxxxxxx</span>).
                      </p>
                    )}
                  </>
                ) : (
                  <Input
                    type="text"
                    size="sm"
                    value={contact.value}
                    onChange={(e) => {
                      const newContacts = [...supportContacts];
                      newContacts[index].value = e.target.value;
                      setSupportContacts(newContacts);
                      setHasUnsavedChanges(prev => ({ ...prev, support: true }));
                    }}
                    placeholder={`أدخل ${contact.type}`}
                    className="bg-white dark:bg-slate-900"
                    dir="auto"
                  />
                )}
              </div>

              <div className="flex items-center gap-4 sm:w-auto w-full justify-between sm:justify-end">
                <div className="flex items-center gap-2">
                  <label className="text-xs font-bold text-slate-500 dark:text-slate-400 whitespace-nowrap">الترتيب:</label>
                  <div className="w-16 shrink-0">
                    <Input
                      type="number"
                      size="sm"
                      value={contact.order}
                      onChange={(e) => {
                        const newContacts = [...supportContacts];
                        newContacts[index].order = parseInt(e.target.value) || 0;
                        setSupportContacts(newContacts);
                        setHasUnsavedChanges(prev => ({ ...prev, support: true }));
                      }}
                      aria-label={`ترتيب ${contact.type}`}
                      className="text-center bg-white dark:bg-slate-900 px-1"
                    />
                  </div>
                </div>

                <SettingsSwitch
                  label={`تفعيل ${contact.type}`}
                  checked={contact.enabled}
                  onChange={(e) => {
                    const newContacts = [...supportContacts];
                    newContacts[index].enabled = e.target.checked;
                    setSupportContacts(newContacts);
                    setHasUnsavedChanges(prev => ({ ...prev, support: true }));
                  }}
                />
              </div>
            </SettingRow>
          );
        })}
      </div>
    </>
  );

  return (
    <div className="space-y-6 lg:space-y-8 relative">
      <div className="absolute -top-6 right-1/4 w-[340px] h-[340px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[100px] -z-10 pointer-events-none" />
      <div className="absolute top-40 left-1/4 w-[380px] h-[380px] bg-violet-500/8 dark:bg-violet-500/12 rounded-full blur-[110px] -z-10 pointer-events-none" />
      <div className="absolute bottom-0 right-1/3 w-[300px] h-[300px] bg-sky-500/6 dark:bg-sky-500/10 rounded-full blur-[100px] -z-10 pointer-events-none" />

      {/* Page Header */}
      <PageHeader
        className="mb-0"
        icon={Settings}
        title="إعدادات المنصة"
        description="تكوين إعدادات المنصة العامة"
      />

      {/* PERSISTENT RAIL + WORKSPACE — same shape as the add/edit-course
          workspace: the four section buttons are always visible up front,
          and a section's content only appears in the workspace once its
          rail button is clicked. */}
      <div className="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-6 items-start">
        <motion.div
          initial={{ opacity: 0, x: 12 }}
          animate={{ opacity: 1, x: 0 }}
          transition={{ duration: 0.3 }}
          className="flex flex-col gap-3 lg:sticky lg:top-6"
        >
          {SETTINGS_SECTIONS.map((section) => (
            <SettingsRailButton
              key={section.id}
              icon={section.icon}
              title={section.title}
              description={section.description}
              tone={section.tone}
              active={activeTab === section.id}
              unsaved={hasUnsavedChanges[section.id]}
              onSelect={() => setActiveTab(section.id)}
            />
          ))}
        </motion.div>

        <div className="min-w-0">
          <AnimatePresence mode="wait">
            {activeTab === null ? (
              <motion.div key="welcome" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.3 }}>
                <SettingsWelcome />
              </motion.div>
            ) : (
              <motion.div key={activeTab} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} transition={{ duration: 0.3 }}>
                <Card padding="lg" className="relative overflow-hidden">
                  {/* Colored top accent matching the active section's icon chip. */}
                  <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-l from-blue-600 via-indigo-500 to-violet-500" />
                  {activeTab === 'social' && renderSocialSettings()}
                  {activeTab === 'privacy' && renderEditor('privacy', 'سياسة الخصوصية', Shield, privacyPolicy, setPrivacyPolicy)}
                  {activeTab === 'terms' && renderEditor('terms', 'الشروط والأحكام', FileText, termsConditions, setTermsConditions)}
                  {activeTab === 'support' && renderSupportSettings()}
                </Card>
              </motion.div>
            )}
          </AnimatePresence>
        </div>
      </div>
    </div>
  );
}
