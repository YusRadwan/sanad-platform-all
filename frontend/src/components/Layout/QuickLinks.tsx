import { motion } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '../UI';

/**
 * The flat "روابط سريعة" quick-link grid — one shared visual shape used on
 * both the admin and the instructor home pages (design only; each page picks
 * its own set of links/permissions). Plain colored icon, no chip/background,
 * centered above a bold label, on a thin-bordered white card — deliberately
 * flatter than the platform's usual tinted icon-chip pattern.
 */
export interface QuickLink {
  label: string;
  icon: LucideIcon;
  path: string;
  /** Tailwind text-color class for the icon, e.g. 'text-rose-500'. */
  color: string;
}

export interface QuickLinksProps {
  links: QuickLink[];
  onNavigate: (path: string) => void;
  title?: string;
}

export default function QuickLinks({ links, onNavigate, title = 'روابط سريعة' }: QuickLinksProps) {
  return (
    <Card padding="lg">
      <div className="flex items-center justify-end mb-5">
        <h3 className="text-sm font-black text-slate-800 dark:text-slate-200">{title}</h3>
      </div>

      {/* Small horizontal rectangles (icon beside label) instead of tall
          icon-over-label squares — noticeably less vertical space per link. */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {links.map((link, i) => (
          <motion.button
            key={link.path}
            type="button"
            onClick={() => onNavigate(link.path)}
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.04, duration: 0.3 }}
            className="flex items-center gap-2 py-4 px-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 hover:shadow-sm transition-all cursor-pointer"
          >
            <link.icon className={`w-4.5 h-4.5 shrink-0 ${link.color}`} aria-hidden="true" />
            <span className="text-xs font-bold text-slate-700 dark:text-slate-200 truncate">
              {link.label}
            </span>
          </motion.button>
        ))}
      </div>
    </Card>
  );
}
