/**
 * Minimal className joiner for the design-system components.
 *
 * Deliberately NOT `clsx`/`tailwind-merge` — this task forbids new
 * dependencies, and none of the primitives here need conflict resolution:
 * every one of them puts caller-supplied `className` LAST, so a caller's
 * utility naturally wins by CSS source order within the same layer.
 */
export function cn(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(' ');
}
