/**
 * Generic, self-evident placeholder artwork for content that has no real
 * uploaded image yet.
 *
 * WHY THIS EXISTS
 * ---------------
 * The app used to fall back to hard-coded Unsplash stock photographs
 * (`https://images.unsplash.com/photo-1516321497487-...`, `photo-1472099645785-...`,
 * `photo-1535713875002-...`, and a six-entry `STOCK_IMAGES` table in
 * CourseManagement) whenever a course had no thumbnail or a teacher had no
 * avatar. That is misleading in two distinct ways:
 *
 *   1. It presents a stranger's stock photograph to the student as if it were
 *      the real photograph of their teacher, or the real cover of the course
 *      they are paying for. The user cannot tell "no image was uploaded" apart
 *      from "this is the image".
 *   2. It made every page load fetch third-party assets from images.unsplash.com,
 *      leaking referrer/IP data about our users to an external CDN on a platform
 *      that otherwise serves all of its media from its own origin.
 *
 * These placeholders replace that. They are:
 *   - inline `data:` URIs, so there is NO network request and no third-party
 *     dependency — they also render instantly and work offline;
 *   - deliberately flat, neutral and icon-based, with explicit Arabic wording,
 *     so a viewer immediately reads them as "nothing was uploaded here" rather
 *     than mistaking them for real content;
 *   - plain strings, so every existing consumer (`<img src={...}>`, the
 *     `Course.image` / `teacher.image` fields, the course preview card) keeps
 *     working with no type or API change.
 *
 * They are NOT an admin-configurable "default image" setting. That would need
 * new backend settings infrastructure, which is well beyond a cleanup pass; a
 * pure-frontend placeholder state is the smaller, safer fix and it fully solves
 * the "fake content presented as real" problem.
 */

/** Wraps raw SVG markup into a data: URI usable directly as an <img> src. */
const toDataUri = (svg: string): string =>
  `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg.replace(/\s+/g, ' ').trim())}`;

/**
 * Landscape 16:10 placeholder for a course thumbnail / cover.
 * Neutral slate card, dashed frame, a simple "image" glyph and Arabic caption.
 */
export const COURSE_IMAGE_PLACEHOLDER = toDataUri(`
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="500" viewBox="0 0 800 500" role="img" aria-label="لا توجد صورة للكورس">
  <rect width="800" height="500" fill="#e2e8f0"/>
  <rect x="18" y="18" width="764" height="464" rx="28" fill="none" stroke="#94a3b8" stroke-width="4" stroke-dasharray="18 14"/>
  <g transform="translate(400 214)" fill="none" stroke="#64748b" stroke-width="12" stroke-linecap="round" stroke-linejoin="round">
    <rect x="-84" y="-64" width="168" height="128" rx="16"/>
    <circle cx="-34" cy="-20" r="16"/>
    <path d="M-84 40 L-24 -14 L26 32 L54 8 L84 36"/>
  </g>
  <text x="400" y="358" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Tahoma, sans-serif" font-size="34" font-weight="700" fill="#64748b">لا توجد صورة للكورس</text>
  <text x="400" y="402" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Tahoma, sans-serif" font-size="24" font-weight="600" fill="#94a3b8">لم يتم رفع صورة بعد</text>
</svg>
`);

/**
 * Square placeholder for a person's avatar (teacher or student).
 * Generic user glyph — never a photograph of an actual human being.
 */
export const AVATAR_PLACEHOLDER = toDataUri(`
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400" role="img" aria-label="لا توجد صورة شخصية">
  <rect width="400" height="400" fill="#e2e8f0"/>
  <circle cx="200" cy="158" r="62" fill="#94a3b8"/>
  <path d="M84 344c0-64 52-108 116-108s116 44 116 108z" fill="#94a3b8"/>
</svg>
`);

/**
 * Wide placeholder for a teacher profile banner.
 */
export const BANNER_PLACEHOLDER = toDataUri(`
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="400" viewBox="0 0 1200 400" role="img" aria-label="لا توجد صورة غلاف">
  <rect width="1200" height="400" fill="#cbd5e1"/>
  <rect x="16" y="16" width="1168" height="368" rx="24" fill="none" stroke="#94a3b8" stroke-width="4" stroke-dasharray="18 14"/>
  <text x="600" y="215" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Tahoma, sans-serif" font-size="34" font-weight="700" fill="#64748b">لا توجد صورة غلاف</text>
</svg>
`);
