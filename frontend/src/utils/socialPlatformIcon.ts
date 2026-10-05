import {
  Facebook,
  Instagram,
  Twitter,
  Youtube,
  Linkedin,
  Ghost,
  Video,
  Mail,
  Send,
  MessageCircle,
  Globe,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

/**
 * Single source of truth for "social platform name → lucide icon".
 *
 * Two different, deliberately separate data sources feed social links in this
 * app — `settings.social_links` (platform-wide, rendered by Footer.tsx) and
 * `teacher.socialLinks` (per-teacher, configured by an admin when adding the
 * teacher, rendered by TeacherProfilePage.tsx). The DATA stays separate; only
 * the icon lookup is shared, so a platform an admin can pick never renders
 * without an icon in one place while having one in the other.
 *
 * The platform string comes from admin input, so matching is done on a
 * lowercased + trimmed value and unknown platforms fall back to a neutral
 * globe rather than rendering nothing.
 */
const SOCIAL_PLATFORM_ICONS: Record<string, LucideIcon> = {
  facebook: Facebook,
  instagram: Instagram,
  twitter: Twitter,
  'x (twitter)': Twitter,
  x: Twitter,
  youtube: Youtube,
  linkedin: Linkedin,
  telegram: Send,
  whatsapp: MessageCircle,
  discord: MessageCircle,
  tiktok: Video,
  snapchat: Ghost,
  gmail: Mail,
  email: Mail,
  website: Globe,
};

/** Fallback used for any platform not listed above. */
export const DEFAULT_SOCIAL_PLATFORM_ICON: LucideIcon = Globe;

export function getSocialPlatformIcon(platform: string): LucideIcon {
  const key = (platform || '').toLowerCase().trim();
  return SOCIAL_PLATFORM_ICONS[key] || DEFAULT_SOCIAL_PLATFORM_ICON;
}

export default getSocialPlatformIcon;
