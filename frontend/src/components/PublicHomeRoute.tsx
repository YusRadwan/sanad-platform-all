import { type ReactNode } from 'react';
import { Navigate } from 'react-router';
import { MotionConfig } from 'motion/react';
import { useAuth } from '../hooks/useAuth';

/**
 * Route guard for the public marketing homepage ("/"), the sibling of
 * ProtectedRoute.tsx for the one route that is public but must still be closed
 * to a single role.
 *
 * A logged-in instructor must not be able to see, reach or navigate to the
 * public site homepage — their home is /instructor. App.tsx already carried an
 * "instructor bounce" effect that navigated them away, but a useEffect runs
 * AFTER the route element has rendered, so the hero, teachers and courses
 * sections were painted for a frame before the redirect landed. This guard
 * resolves during render instead, so the homepage is never mounted for an
 * instructor at all. The effect stays as the catch-all for every other
 * non-/instructor path.
 *
 * ROLE SOURCE: AuthContext, which seeds from sessionStorage and then re-syncs
 * from the /auth/me response on mount — the same live, server-backed source
 * ProtectedRoute documents as authoritative. A tampered sessionStorage role is
 * corrected on that round trip. Unlike ProtectedRoute this guard deliberately
 * does NOT block rendering on that request: "/" is the public homepage every
 * logged-out visitor already sees, so there is no data to protect here, and
 * making every guest wait on a network call to see the landing page would be a
 * far worse trade. Students, admins and guests always render children.
 */
export default function PublicHomeRoute({ children }: { children: ReactNode }) {
  const { isLoggedIn, userRole } = useAuth();
  const isInstructorBounce = isLoggedIn && userRole === 'instructor';

  if (isInstructorBounce) {
    return <Navigate to="/instructor" replace />;
  }

  // `font-tajawal` (see index.css) scopes the Tajawal typeface to just the
  // homepage's own sections — the shared Header/Footer chrome and every
  // other route keep the site's default Cairo.
  //
  // `MotionConfig reducedMotion="user"` makes every `motion/react` animation
  // on the homepage (entrance fades/slides, the teachers/courses carousels,
  // decorative floats) automatically honour the OS-level "prefers reduced
  // motion" setting — Motion's own equivalent of the plain-CSS rule in
  // index.css, which cannot reach animations driven by this library.
  return (
    <MotionConfig reducedMotion="user">
      <div className="font-tajawal">{children}</div>
    </MotionConfig>
  );
}
