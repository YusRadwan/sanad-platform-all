import { useState } from 'react';

/**
 * The one account avatar used across every top bar on the platform.
 *
 * Before this component there were five hand-written copies of "a circle with
 * a letter in it": three inside Header.tsx (topbar trigger, dropdown identity
 * block, mobile sheet identity block), one in AdminSystem.tsx's topbar, one in
 * InstructorSystem.tsx's topbar, plus a sixth square variant in AdminSystem's
 * sidebar header. They had drifted into three different fills (flat
 * `bg-blue-600`, a gradient, and a `from-blue-700 to-indigo-600` square) and
 * three different ring treatments, so the same account looked like a different
 * account depending on which surface you were on.
 *
 * The gradient + ring treatment here is Header.tsx's, which was the most
 * refined of the three; the other surfaces now inherit it.
 *
 * IMPORTANT — `initial` is passed in, never derived here. The call sites use
 * genuinely different expressions (`username.trim().charAt(3)` for the admin
 * label, `name.charAt(0)` for a teacher's display name) and unifying that
 * would be a behaviour change, not a visual one. This component only owns how
 * the avatar LOOKS.
 */

export type AvatarSize = 'sm' | 'md' | 'lg' | 'xl';

const SIZE: Record<AvatarSize, string> = {
  sm: 'w-8 h-8 text-xs',
  md: 'w-10 h-10 text-sm',
  lg: 'w-11 h-11 text-base',
  xl: 'w-14 h-14 text-xl',
};

interface AvatarProps {
  /** Single glyph rendered when there is no image. Computed by the caller. */
  initial: string;
  size?: AvatarSize;
  /** Rounded square instead of a circle (sidebar identity blocks). */
  square?: boolean;
  /** Optional photo; falls back to `initial` when empty. */
  imageUrl?: string;
  /** Alt text for `imageUrl`. */
  alt?: string;
  /**
   * The ring sits on the surface BEHIND the avatar, so a panel-mounted avatar
   * needs a different dark-mode ring colour than a bar-mounted one.
   */
  ringClassName?: string;
  /** Escape hatch for responsive overrides (e.g. the admin rail's shrink). */
  className?: string;
}

export default function Avatar({
  initial,
  size = 'sm',
  square = false,
  imageUrl,
  alt,
  ringClassName = 'ring-2 ring-white/70 dark:ring-slate-950/60',
  className = '',
}: AvatarProps) {
  const shape = square ? 'rounded-2xl' : 'rounded-full';

  // `imageUrl` can be a real URL pointing at a file the server no longer has
  // (a 404, not a missing value) — falls back to the initial the same as an
  // empty `imageUrl` would, instead of a broken-image icon in every top bar
  // on the platform. Keyed on `imageUrl` itself so a genuinely new photo
  // (e.g. right after the account holder uploads one) gets a fresh attempt
  // rather than staying stuck on a stale failure from a previous URL.
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  const showImage = Boolean(imageUrl) && imageUrl !== failedUrl;

  return (
    <div
      className={`${SIZE[size]} ${shape} shrink-0 overflow-hidden bg-gradient-to-br from-blue-500 via-blue-600 to-indigo-600 text-white flex items-center justify-center font-black shadow-sm shadow-blue-600/30 ${ringClassName} ${className}`}
    >
      {showImage ? (
        <img
          src={imageUrl}
          alt={alt || ''}
          className="w-full h-full object-cover"
          onError={() => setFailedUrl(imageUrl!)}
        />
      ) : (
        initial
      )}
    </div>
  );
}
