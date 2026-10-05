import { motion } from 'motion/react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '../UI';

/**
 * The shared stat-card shape used on both the admin and the instructor
 * dashboards (design only — each caller picks its own icon/color/real
 * value). A large pale color circle bleeds off the card's left edge, a
 * SOLID color square with a white icon sits at the top-right beside the
 * label, and the value is large and bold beneath. `caption` is a plain
 * real-data line (e.g. "منها 3 كورسات منشورة") — there is deliberately NO
 * trend/percentage indicator, because none of this platform's endpoints
 * compute a period-over-period comparison; inventing one would be
 * fabricated data.
 */
export type StatCardColor = 'blue' | 'emerald' | 'purple' | 'amber' | 'violet' | 'rose' | 'indigo' | 'teal';

const COLORS: Record<StatCardColor, { solid: string; blob: string }> = {
  blue: { solid: 'bg-blue-500', blob: 'bg-blue-50 dark:bg-blue-500/10' },
  emerald: { solid: 'bg-emerald-500', blob: 'bg-emerald-50 dark:bg-emerald-500/10' },
  purple: { solid: 'bg-purple-500', blob: 'bg-purple-50 dark:bg-purple-500/10' },
  amber: { solid: 'bg-amber-500', blob: 'bg-amber-50 dark:bg-amber-500/10' },
  violet: { solid: 'bg-violet-500', blob: 'bg-violet-50 dark:bg-violet-500/10' },
  rose: { solid: 'bg-rose-500', blob: 'bg-rose-50 dark:bg-rose-500/10' },
  indigo: { solid: 'bg-indigo-500', blob: 'bg-indigo-50 dark:bg-indigo-500/10' },
  teal: { solid: 'bg-teal-500', blob: 'bg-teal-50 dark:bg-teal-500/10' },
};

export interface StatCardProps {
  icon: LucideIcon;
  label: string;
  value: string | number;
  color: StatCardColor;
  /** Plain real-data caption under the value. Never a fabricated trend/percentage. */
  caption?: string;
  index?: number;
}

export default function StatCard({ icon: Icon, label, value, color, caption, index = 0 }: StatCardProps) {
  const tone = COLORS[color];
  return (
    <motion.div
      initial={{ opacity: 0, y: 15 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: index * 0.08, duration: 0.35 }}
      className="h-full"
    >
      <Card className="group relative h-full overflow-hidden p-3.5 transition-shadow hover:shadow-md">
        <div className={`absolute top-1/2 -left-10 -translate-y-1/2 w-32 h-32 rounded-full transition-transform group-hover:scale-110 ${tone.blob}`} aria-hidden="true" />

        <div className="relative flex items-start justify-between gap-3 mb-2">
          <span className="text-sm font-bold text-slate-500 dark:text-slate-400">{label}</span>
          <div className={`shrink-0 w-9 h-9 rounded-xl flex items-center justify-center text-white shadow-sm transition-transform group-hover:scale-110 ${tone.solid}`}>
            <Icon className="w-4 h-4" aria-hidden="true" />
          </div>
        </div>

        <strong className="relative block text-2xl font-black text-slate-900 dark:text-white font-mono leading-none tracking-tight">
          {value}
        </strong>

        {caption && (
          <span className="relative block text-xs font-bold text-slate-400 dark:text-slate-500 mt-1.5">
            {caption}
          </span>
        )}
      </Card>
    </motion.div>
  );
}
