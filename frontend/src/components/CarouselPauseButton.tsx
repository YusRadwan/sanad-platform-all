import { Pause, Play } from 'lucide-react';

/**
 * Visible pause/resume control for the homepage's auto-scrolling carousels
 * (WCAG 2.2.2: moving content that lasts more than 5s needs a way to stop it).
 * Sits on the brand-blue panels, so it is drawn light-on-blue.
 */
export default function CarouselPauseButton({
  paused,
  onToggle,
  label,
}: {
  paused: boolean;
  onToggle: () => void;
  /** What is moving, e.g. "المدرسين" — used in the accessible name. */
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-pressed={paused}
      aria-label={paused ? `تشغيل حركة ${label}` : `إيقاف حركة ${label}`}
      className="inline-flex items-center gap-2 min-h-11 px-4 rounded-full text-sm font-bold text-white bg-white/10 ring-1 ring-inset ring-white/25 hover:bg-white/20 transition-colors cursor-pointer"
    >
      {paused ? <Play className="w-4 h-4" aria-hidden="true" /> : <Pause className="w-4 h-4" aria-hidden="true" />}
      <span>{paused ? 'تشغيل الحركة' : 'إيقاف الحركة'}</span>
    </button>
  );
}
