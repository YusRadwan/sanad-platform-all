import { memo, useState } from 'react';
import { motion } from 'motion/react';
import { TeacherCardProps } from '../types/index';
/**
 * Octagonal "tech frame" clip-path for the teacher photo, and the
 * shield/banner clip-path for the subject badge — both inline `clipPath`
 * styles rather than a Tailwind class, matching the pop-out photo trick
 * already in this file (`clipPath: 'inset(-100% -100% 50% -100%)'`), since
 * Tailwind has no built-in octagon/shield utility.
 */
const OCTAGON_CLIP = 'polygon(26% 0%, 74% 0%, 100% 26%, 100% 74%, 74% 100%, 26% 100%, 0% 74%, 0% 26%)';
const SHIELD_CLIP = 'polygon(0% 0%, 100% 0%, 100% 66%, 50% 100%, 0% 66%)';

function TeacherCard({ teacher, onViewProfile }: TeacherCardProps) {
  // `avatar_url` can point at a file that no longer exists on the server
  // (a 404, not a missing value) — the URL itself is truthy, so the
  // truthiness check below would otherwise still try to render it and show
  // two broken-image icons in the framed crop. Tracking a load failure and
  // treating it the same as "no avatar" falls back to the initials frame
  // instead.
  const [avatarFailed, setAvatarFailed] = useState(false);
  const hasAvatar = Boolean(teacher.avatar_url) && !avatarFailed;

  return (
    <motion.div
      initial={{ opacity: 0, y: 30 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5, ease: 'easeOut' }}
      whileHover={{ y: -6, scale: 1.015 }}
      className="group relative flex flex-col rounded-[2rem] p-4 pt-0 mt-10 shadow-[0_12px_36px_rgba(8,29,84,0.28)] hover:shadow-[0_20px_44px_rgba(8,29,84,0.4)] transition-shadow duration-300"
      style={{
        background:
          'radial-gradient(120% 90% at 15% -10%, rgba(224,250,255,0.55) 0%, rgba(224,250,255,0) 42%), ' +
          'linear-gradient(165deg, #082a63 0%, #0f3f8f 32%, #1f74bd 66%, #52cfe0 100%)',
      }}
    >
      {/* Background texture layer — soft translucent geometric shapes,
          clipped to the card so nothing bleeds past the rounded corners.
          Purely decorative, never opaque enough to compete with the real
          content in front of it. */}
      <div className="absolute inset-0 rounded-[2rem] overflow-hidden pointer-events-none">
        <div className="absolute -top-10 -left-10 w-40 h-40 rotate-12 rounded-[2.5rem] bg-white/10" />
        <div className="absolute top-1/3 -right-12 w-32 h-32 rotate-45 rounded-3xl bg-cyan-200/10" />

        {/* Glass sheen — a soft light streak across the upper half plus a
            diagonal glint that sweeps through on hover. */}
        <div className="absolute inset-x-0 top-0 h-1/2 bg-gradient-to-b from-white/15 to-transparent opacity-80 group-hover:opacity-100 transition-opacity duration-500" />
        <div className="absolute -inset-y-6 -left-1/2 w-1/3 rotate-12 bg-gradient-to-l from-transparent via-white/25 to-transparent opacity-0 group-hover:opacity-100 group-hover:animate-shimmer transition-opacity duration-300" />
      </div>

      {/* Teacher Image — octagonal "tech frame" pop-out, replacing the
          previous plain circle. Same two-layer clipped/unclipped photo
          trick as before (bottom half clipped to the frame, top half left
          free) so the photo still visually pops out above the card,
          just reshaped to an octagon instead of a circle. */}
      <div className="relative w-full aspect-square -mt-[58%] mb-3 flex items-end justify-center pointer-events-none">
        {/* Background Glow */}
        <div className="absolute top-[10%] w-[94%] h-[94%] bg-cyan-300/25 dark:bg-cyan-400/15 rounded-full blur-[40px] animate-pulse group-hover:bg-cyan-300/40 transition-colors duration-500" />

        {/* Outer glass panel — a larger, slightly rotated octagon behind the
            main frame, giving the layered "futuristic panel" depth the
            reference design uses. */}
        <div
          className="absolute bottom-[-4%] left-[-4%] right-[-4%] aspect-square bg-white/10 border border-white/25 backdrop-blur-sm rotate-6 transition-transform duration-500 group-hover:rotate-3"
          style={{ clipPath: OCTAGON_CLIP }}
        />

        {/* Main octagon frame */}
        <div
          className="absolute bottom-0 left-[4%] right-[4%] aspect-square bg-gradient-to-tr from-blue-700 via-sky-500 to-cyan-300 shadow-[0_0_30px_rgba(56,189,248,0.35)] group-hover:shadow-[0_0_44px_rgba(56,189,248,0.55)] transition-all duration-500"
          style={{ clipPath: OCTAGON_CLIP }}
        />

        {hasAvatar ? (
          <>
            {/* Layer 1: Bottom half (Clipped by the octagon frame) */}
            <div
              className="absolute bottom-[4px] left-[calc(4%+4px)] right-[calc(4%+4px)] aspect-square overflow-hidden flex items-end justify-center transform group-hover:-translate-y-1.5 transition-all duration-500 pointer-events-auto"
              style={{ clipPath: OCTAGON_CLIP }}
            >
              <img
                src={teacher.avatar_url}
                alt={teacher.full_name || teacher.name}
                className="w-full h-[130%] object-cover object-bottom drop-shadow-[0_10px_20px_rgba(0,0,0,0.25)]"
                referrerPolicy="no-referrer"
                onError={() => setAvatarFailed(true)}
              />
            </div>

            {/* Layer 2: Top half (Pop-out, no limits) */}
            <div
              className="absolute bottom-[4px] left-[calc(4%+4px)] right-[calc(4%+4px)] aspect-square flex items-end justify-center transform group-hover:-translate-y-1.5 transition-all duration-500 pointer-events-none"
              style={{ clipPath: 'inset(-100% -100% 50% -100%)' }}
            >
              <img
                src={teacher.avatar_url}
                alt={teacher.full_name || teacher.name}
                className="w-full h-[130%] object-cover object-bottom drop-shadow-[0_10px_20px_rgba(0,0,0,0.25)]"
                referrerPolicy="no-referrer"
                onError={() => setAvatarFailed(true)}
              />
            </div>
          </>
        ) : (
          <div
            className="absolute bottom-0 left-[4%] right-[4%] aspect-square bg-gradient-to-br from-white/25 via-transparent to-black/10 flex items-center justify-center transform group-hover:-translate-y-1.5 transition-all duration-500"
            style={{ clipPath: OCTAGON_CLIP }}
          >
            <div className="text-3xl lg:text-4xl font-black text-white select-none drop-shadow-[0_2px_6px_rgba(0,0,0,0.35)]">
              {(teacher.full_name || teacher.name || 'أ').charAt(0)}
            </div>
          </div>
        )}
      </div>

      {/* Content wrapper */}
      <div className="flex flex-col flex-grow text-center items-center relative z-10 w-full">

        {/* Subject badge(s) — shield/banner shape, dark-green, matching the
            reference design. The subject list stays fully dynamic
            (`teacher.subjects`, unchanged data/shape). */}
        {teacher.subjects && teacher.subjects.length > 0 && (
          <div className="-mt-1 mb-3 flex flex-wrap justify-center gap-2">
            {teacher.subjects.map((subj, idx) => (
              <div
                key={idx}
                className="relative flex flex-col items-center gap-1 px-5 pt-2.5 pb-4 bg-gradient-to-b from-emerald-600 to-emerald-800 shadow-[0_6px_16px_rgba(6,78,59,0.4)] transition-transform duration-300 group-hover:scale-105"
                style={{ clipPath: SHIELD_CLIP }}
              >
                <span className="text-sm font-black text-white whitespace-nowrap">{subj}</span>
              </div>
            ))}
          </div>
        )}

        {/* Teacher Name — a light glass pill sitting on the blue background,
            the lettering styled as a crisp light-on-glass surface instead
            of the darker text this card used before, since the whole
            background behind it is now a deep blue rather than a plain
            neutral one. */}
        <div className="relative mb-2.5 px-5 py-2 rounded-2xl bg-white/90 dark:bg-white/15 backdrop-blur-md border border-white/70 dark:border-white/20 shadow-[inset_0_1px_0_rgba(255,255,255,0.8),0_4px_16px_rgba(8,29,84,0.25)] overflow-hidden">
          <div className="absolute -inset-y-4 -left-1/2 w-1/3 rotate-12 bg-gradient-to-l from-transparent via-white/70 to-transparent opacity-0 group-hover:opacity-100 group-hover:animate-shimmer transition-opacity duration-300 pointer-events-none" />
          <h3 className="relative text-2xl font-black tracking-tight text-slate-900 dark:text-white transition-colors duration-300">
            {teacher.full_name || teacher.name}
          </h3>
        </div>

        {/* Action Button */}
        <button
          onClick={() => onViewProfile?.(teacher.id)}
          className="mt-auto w-full py-3 rounded-2xl text-white font-black text-sm tracking-wide cursor-pointer bg-gradient-to-b from-blue-500 to-blue-800 shadow-[inset_0_1px_0_rgba(255,255,255,0.25),0_6px_18px_rgba(8,29,84,0.45)] hover:shadow-[inset_0_1px_0_rgba(255,255,255,0.3),0_8px_22px_rgba(8,29,84,0.6)] hover:brightness-110 active:brightness-95 transition-all duration-300"
        >
          عرض صفحة المدرس
        </button>

      </div>
    </motion.div>
  );
}

export default memo(TeacherCard);
