import { describe, it, expect } from 'vitest';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { MemoryRouter, Routes, Route } from 'react-router';
import { AuthContext } from '../context/AuthContext';
import PublicHomeRoute from './PublicHomeRoute';

// React only honours act() when this flag is set; without it every render here
// logs "The current testing environment is not configured to support act(...)"
// and effects — including the one <Navigate> uses to perform the redirect —
// are not flushed deterministically.
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

type Role = 'student' | 'instructor' | 'admin';

const HOMEPAGE = 'PUBLIC-HOMEPAGE-CONTENT';
const INSTRUCTOR_DASHBOARD = 'INSTRUCTOR-DASHBOARD';

/**
 * Renders the real "/" route behind the guard, with AuthContext supplying the
 * session under test, and reports which screen the router settled on. If the
 * guard redirects, the /instructor route renders instead and the homepage
 * marker never appears in the output at all — which is the actual requirement
 * ("an instructor must not SEE the homepage"), not merely that a later
 * navigation happens to move them off it.
 */
function renderHomeAs(isLoggedIn: boolean, userRole: Role): string {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);

  act(() => {
    root.render(
      <AuthContext.Provider
        value={{
          isLoggedIn,
          username: 'tester',
          userRole,
          adminImpersonationUsername: null,
          dispatch: () => {},
        }}
      >
        <MemoryRouter initialEntries={['/']}>
          <Routes>
            <Route
              path="/"
              element={
                <PublicHomeRoute>
                  <div>{HOMEPAGE}</div>
                </PublicHomeRoute>
              }
            />
            <Route path="/instructor" element={<div>{INSTRUCTOR_DASHBOARD}</div>} />
          </Routes>
        </MemoryRouter>
      </AuthContext.Provider>
    );
  });

  const html = container.innerHTML;
  act(() => root.unmount());
  container.remove();
  return html;
}

describe('PublicHomeRoute', () => {
  it('never renders the homepage for a logged-in instructor, and lands them on /instructor', () => {
    const html = renderHomeAs(true, 'instructor');
    expect(html).not.toContain(HOMEPAGE);
    expect(html).toContain(INSTRUCTOR_DASHBOARD);
  });

  it('renders the homepage for a logged-out guest', () => {
    const html = renderHomeAs(false, 'student');
    expect(html).toContain(HOMEPAGE);
    expect(html).not.toContain(INSTRUCTOR_DASHBOARD);
  });

  it('renders the homepage for a logged-in student (unchanged)', () => {
    const html = renderHomeAs(true, 'student');
    expect(html).toContain(HOMEPAGE);
  });

  it('renders the homepage for a logged-in admin (unchanged)', () => {
    const html = renderHomeAs(true, 'admin');
    expect(html).toContain(HOMEPAGE);
  });

  // A stale/forged sessionStorage role must not be the thing that keeps an
  // instructor out — it is only the seed. This documents that an instructor
  // whose role has not yet been resolved is treated as a normal visitor for
  // this one public page until AuthContext's /auth/me sync corrects it.
  it('renders the homepage while the session role is still unresolved', () => {
    const html = renderHomeAs(false, 'instructor');
    expect(html).toContain(HOMEPAGE);
  });
});
