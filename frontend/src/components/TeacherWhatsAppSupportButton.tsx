import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown } from 'lucide-react';
import { buildWhatsAppLink } from '../utils/whatsappLink';
import WhatsAppIcon from './icons/WhatsAppIcon';

/**
 * Per-TEACHER WhatsApp support numbers, shown as one compact icon/button on
 * `TeacherProfilePage.tsx`. NOT the platform-wide support numbers from
 * Settings — these come from `teacher.whatsappNumbers`, attached to this
 * specific teacher via AddTeacherComponents/TeacherWhatsAppSupport.tsx's
 * "أرقام الدعم الفني الخاصة بالمعلم للمنصة" field, e.g. "مسؤول المادة:
 * 01012345678" — a single free-text label+number string per entry, not two
 * separate fields (that's how the admin form collects and stores them).
 *
 * SECOND PASS: the trigger now carries the real WhatsApp glyph (shared with
 * `WhatsAppWidget.tsx` via `icons/WhatsAppIcon.tsx`) inside a solid brand-green
 * circular badge, instead of a generic lucide chat-bubble icon standing in for
 * it — the point of the button is instantly recognisable as "WhatsApp
 * support," not decoded from its label text.
 *
 * Renders nothing if the teacher has no numbers attached — a teacher with no
 * assistants configured is reached directly, with no dead UI left behind.
 */
interface TeacherWhatsAppSupportButtonProps {
  numbers: string[];
}

/**
 * The admin form's own helper text and placeholder disagree on the separator
 * ("تسمية متبوعة بالرقم: مسؤول المادة: 01012345678" vs. the field's
 * placeholder "مسؤول المادة - 01012345678") — so entries in the wild may use
 * either ':' or '-'. Split on whichever comes first; if neither is present,
 * treat the whole string as the number with no label.
 */
function parseEntry(raw: string): { label: string | null; number: string } {
  const trimmed = raw.trim();
  const separatorIndex = (() => {
    const colon = trimmed.indexOf(':');
    const dash = trimmed.indexOf('-');
    if (colon === -1) return dash;
    if (dash === -1) return colon;
    return Math.min(colon, dash);
  })();

  if (separatorIndex === -1) {
    return { label: null, number: trimmed };
  }

  return {
    label: trimmed.slice(0, separatorIndex).trim() || null,
    number: trimmed.slice(separatorIndex + 1).trim(),
  };
}

export default function TeacherWhatsAppSupportButton({ numbers }: TeacherWhatsAppSupportButtonProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [menuPos, setMenuPos] = useState<{ top: number; left: number; openUpward: boolean } | null>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  const entries = (numbers || []).filter((n) => n && n.trim()).map(parseEntry).filter((e) => e.number);

  /*
   * The trigger lives inside TeacherProfilePage.tsx's cover-photo card,
   * which sets `overflow-hidden` on its outer rounded container so the cover
   * image's corners stay clipped. That same overflow-hidden also clips any
   * normally-positioned (`absolute` inside a `relative` ancestor) dropdown
   * that tries to open below the button, since the button sits close to
   * that card's own bottom edge — the menu was being cut off rather than
   * genuinely "appearing upward", but the visible result (a sliver of the
   * menu, or none of it) looked exactly like a mispositioned popup.
   *
   * Rendering through a portal into document.body with `position: fixed`
   * coordinates taken from the button's own bounding rect sidesteps every
   * ancestor's overflow/stacking context entirely — the menu is never a
   * descendant of the clipping box. `openUpward` flips the anchor when the
   * button is too close to the bottom of the viewport for the menu to fit
   * below it, so the menu is always fully visible regardless of scroll
   * position.
   */
  const openMenu = () => {
    const rect = buttonRef.current?.getBoundingClientRect();
    if (!rect) return;

    const estimatedMenuHeight = 56 + entries.length * 52;
    const spaceBelow = window.innerHeight - rect.bottom;
    const openUpward = spaceBelow < estimatedMenuHeight && rect.top > estimatedMenuHeight;

    setMenuPos({
      top: openUpward ? rect.top - 8 : rect.bottom + 8,
      left: rect.left,
      openUpward,
    });
    setIsOpen(true);
  };

  const closeMenu = () => setIsOpen(false);

  useEffect(() => {
    if (!isOpen) return;

    const handlePointerDown = (e: PointerEvent) => {
      const target = e.target as Node;
      if (buttonRef.current?.contains(target) || menuRef.current?.contains(target)) return;
      closeMenu();
    };

    // Scroll/resize can happen anywhere on the page, not just inside this
    // component's own ancestors (the trigger is often mid-page) — closing
    // rather than re-measuring keeps this simple and avoids a stale,
    // detached-looking menu trailing the button as the page moves.
    window.addEventListener('pointerdown', handlePointerDown);
    window.addEventListener('scroll', closeMenu, true);
    window.addEventListener('resize', closeMenu);

    return () => {
      window.removeEventListener('pointerdown', handlePointerDown);
      window.removeEventListener('scroll', closeMenu, true);
      window.removeEventListener('resize', closeMenu);
    };
  }, [isOpen]);

  if (entries.length === 0) return null;

  return (
    <>
      {/*
        Solid brand-green pill instead of a white outline pill: this button
        sits inside TeacherProfilePage.tsx's blue-to-indigo gradient panel,
        right next to the social icons — a white pill read as just another
        quiet chip in that context, when it is actually the one actionable
        "contact support" affordance on the page and should look like it.
        The WhatsApp glyph stays in its own circular badge for brand
        recognition; a count badge appears only when there is more than one
        number, so the trigger doesn't imply a list when there's nothing to
        disambiguate.
      */}
      <button
        ref={buttonRef}
        type="button"
        onClick={() => (isOpen ? closeMenu() : openMenu())}
        aria-expanded={isOpen}
        title="الدعم الفني الخاص بهذا المعلم"
        className="relative flex items-center gap-2.5 pl-4 pr-2.5 py-2 rounded-full bg-linear-to-br from-emerald-400 to-[#1DA851] shadow-md shadow-emerald-950/20 hover:shadow-lg hover:shadow-emerald-950/30 hover:-translate-y-0.5 transition-all duration-300 cursor-pointer active:scale-95 active:translate-y-0 group"
      >
        <span className="relative flex items-center justify-center w-8 h-8 rounded-full bg-white text-emerald-600 shrink-0 shadow-sm group-hover:scale-105 transition-transform duration-300">
          <WhatsAppIcon className="w-4.5 h-4.5" />
          {entries.length > 1 && (
            <span className="absolute -top-1 -left-1 flex items-center justify-center min-w-4.5 h-4.5 px-1 rounded-full bg-amber-400 text-emerald-950 text-[9px] font-black ring-2 ring-white">
              {entries.length}
            </span>
          )}
        </span>
        <span className="text-xs font-extrabold text-white">الدعم الفني للمعلم</span>
        <ChevronDown className={`w-3.5 h-3.5 text-white/80 transition-transform duration-200 ${isOpen ? 'rotate-180' : ''}`} />
      </button>

      {isOpen && menuPos && createPortal(
        <div
          ref={menuRef}
          style={{
            position: 'fixed',
            top: menuPos.openUpward ? undefined : menuPos.top,
            bottom: menuPos.openUpward ? window.innerHeight - menuPos.top : undefined,
            left: menuPos.left,
          }}
          className="z-50 w-72 max-w-[90vw] rounded-2xl bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 shadow-xl p-2 space-y-1"
        >
          {entries.map((entry, idx) => (
            <a
              key={idx}
              href={buildWhatsAppLink(entry.number)}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition-colors group/item"
            >
              <span className="flex items-center justify-center w-8 h-8 rounded-full bg-linear-to-br from-emerald-400 to-[#1DA851] text-white shrink-0 ring-2 ring-white dark:ring-slate-900 shadow-sm shadow-emerald-600/25 group-hover/item:scale-110 transition-transform">
                <WhatsAppIcon className="w-4 h-4" />
              </span>
              <span className="flex flex-col items-start text-right overflow-hidden">
                {entry.label && (
                  <span className="text-[10px] font-black text-slate-400 dark:text-slate-500 truncate w-full">{entry.label}</span>
                )}
                <span className="text-xs font-bold text-slate-700 dark:text-slate-200 truncate w-full" dir="ltr">{entry.number}</span>
              </span>
            </a>
          ))}
        </div>,
        document.body
      )}
    </>
  );
}
