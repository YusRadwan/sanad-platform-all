import { Share2, Facebook, Youtube, MessageCircle, Send, ToggleLeft, ToggleRight } from 'lucide-react';
import { Card, CardHeader, Input } from '../../UI';

interface SocialPlatformData {
  url: string;
  enabled: boolean;
}

interface SocialLinks {
  facebook: SocialPlatformData;
  youtube: SocialPlatformData;
  tiktok: SocialPlatformData;
  whatsapp: SocialPlatformData;
  telegram: SocialPlatformData;
}

interface TeacherSocialLinksProps {
  socialLinks: SocialLinks;
  handleSocialUrlChange: (platform: keyof SocialLinks, value: string) => void;
  toggleSocialEnabled: (platform: keyof SocialLinks) => void;
}

/**
 * Visual-only migration. Same three props, same `Object.keys(socialLinks)`
 * iteration, same `handleSocialUrlChange(key, value)` / `toggleSocialEnabled(key)`
 * calls, and the same `disabled={!data.enabled}` binding on the URL field.
 *
 * Six dead Tailwind classes were fixed here: `border-slate-100`,
 * `text-slate-800`, `text-slate-800`, `dark:border-slate-800`,
 * `text-slate-400` and `text-slate-300` are not real Tailwind shades — they
 * emitted no CSS at all, which is why the disabled state and the label colours
 * looked unstyled (especially in dark mode).
 */
export default function TeacherSocialLinks({
  socialLinks,
  handleSocialUrlChange,
  toggleSocialEnabled
}: TeacherSocialLinksProps) {
  return (
    <Card>
      <CardHeader
        icon={Share2}
        title="روابط وقنوات المعلم على مواقع التواصل ومستوى ظهورها"
        description="أدخل الروابط بدقة، وتحكم في ظهور الأزرار على صفحة المدرس بضغطة واحدة"
      />

      <div className="space-y-3">
        {Object.keys(socialLinks).map((platform) => {
          const key = platform as keyof SocialLinks;
          const data = socialLinks[key];
          return (
            <div
              key={platform}
              className="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 p-4 rounded-2xl bg-slate-50 dark:bg-slate-950/50 border border-slate-200 dark:border-slate-800 transition-all"
            >
              {/* Platform Title */}
              <div className="flex items-center gap-3 shrink-0">
                <span className={`w-9 h-9 rounded-xl flex items-center justify-center text-white shrink-0 ${
                  key === 'facebook' ? 'bg-blue-600' :
                  key === 'youtube' ? 'bg-rose-600' :
                  key === 'tiktok' ? 'bg-slate-900 dark:bg-slate-800' :
                  key === 'whatsapp' ? 'bg-emerald-600' : 'bg-sky-500'
                }`}>
                  {key === 'facebook' && <Facebook className="w-4 h-4" />}
                  {key === 'youtube' && <Youtube className="w-4 h-4" />}
                  {key === 'tiktok' && <Share2 className="w-4 h-4" />}
                  {key === 'whatsapp' && <MessageCircle className="w-4 h-4" />}
                  {key === 'telegram' && <Send className="w-4 h-4" />}
                </span>
                <div className="text-right">
                  <span className="text-xs font-black text-slate-900 dark:text-white capitalize">{key}</span>
                  <span className="text-[10px] text-slate-500 dark:text-slate-400 block font-medium leading-tight">حساب المدرس الخاص</span>
                </div>
              </div>

              {/* Url Entry Form */}
              <div className="flex-1 w-full">
                <Input
                  type="text"
                  size="sm"
                  value={data.url}
                  disabled={!data.enabled}
                  onChange={(e) => handleSocialUrlChange(key, e.target.value)}
                  aria-label={`رابط ${key}`}
                  className="text-left"
                  dir="ltr"
                />
              </div>

              {/* Show / Hide switch */}
              <div className="flex items-center gap-2.5 shrink-0 self-end md:self-auto">
                <span className="text-[10px] font-bold text-slate-500 dark:text-slate-400">
                  {data.enabled ? 'مفعل (مرئي للطلاب)' : 'معطل (مخفي للبروفايل)'}
                </span>

                <button
                  type="button"
                  onClick={() => toggleSocialEnabled(key)}
                  aria-pressed={data.enabled}
                  aria-label={data.enabled ? 'إخفاء من البروفايل' : 'إظهار في البروفايل'}
                  className="text-slate-400 hover:text-blue-600 transition-colors cursor-pointer bg-transparent border-0 p-0"
                >
                  {data.enabled ? (
                    <ToggleRight className="w-9 h-9 text-blue-600" />
                  ) : (
                    <ToggleLeft className="w-9 h-9 text-slate-300 dark:text-slate-700" />
                  )}
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </Card>
  );
}
