import React, { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router';
import { authService } from '../services/authService';
import { authRepository } from '../repositories/authRepository';
import LoadingScreen from './UI/LoadingScreen';

interface ProtectedRouteProps {
  children: React.ReactNode;
  allowedRoles?: string[];
}

const ProtectedRoute: React.FC<ProtectedRouteProps> = ({ children, allowedRoles }) => {
  const [isVerifying, setIsVerifying] = useState(true);
  const [isAuthorized, setIsAuthorized] = useState(false);
  const location = useLocation();

  // Serialize allowedRoles array to avoid running the effect on every parent render
  const allowedRolesKey = allowedRoles ? allowedRoles.join(',') : '';

  useEffect(() => {
    let isMounted = true;
    const checkAuth = async () => {
      const accessToken = authService.getAccessToken();
      
      if (!accessToken) {
        if (isMounted) {
          setIsAuthorized(false);
          setIsVerifying(false);
        }
        return;
      }

      try {
        // Try to get user profile to verify token
        const me = await authService.getMe();

        // AUTHORITY: the role used for this decision comes from the /auth/me
        // RESPONSE, not from sessionStorage. sessionStorage is writable by
        // anyone with a devtools console, so a stored "admin" must never be
        // what decides whether an admin screen renders. The stored copy is
        // re-synced from the server answer here so the (display-only) fallback
        // below can never stay stale either.
        //
        // This is defence in depth on top of, not instead of, the backend:
        // every admin/instructor endpoint is gated server-side by
        // RoleMiddleware::for(...) in backend/routes/api.php, so a forged
        // client-side role yields an empty screen and 403s, never data.
        const serverRole: string = me?.user?.role ?? '';
        if (serverRole) {
          authRepository.setRole(serverRole);
          if (me?.user?.username) authRepository.setUsername(me.user.username);
        }
        const userRole = serverRole || authRepository.getRole();

        if (isMounted) {
          if (allowedRoles && allowedRoles.length > 0) {
            if (allowedRoles.includes(userRole)) {
              setIsAuthorized(true);
            } else {
              setIsAuthorized(false);
            }
          } else {
            setIsAuthorized(true);
          }
        }
      } catch (error) {
        if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) console.error('Auth verification failed:', error);
        if (isMounted) {
          // If verifying fails due to expired or invalid token,
          // clear auth state to cleanly trigger redirect to /login
          authService.logout();
          setIsAuthorized(false);
        }
      } finally {
        if (isMounted) {
          setIsVerifying(false);
        }
      }
    };

    checkAuth();
    return () => {
      isMounted = false;
    };
  }, [allowedRolesKey, location.pathname]);

  if (isVerifying) {
    // Same shared screen the router's Suspense fallback uses — only the caption
    // differs, so an auth check and a chunk load never look like two products.
    return <LoadingScreen message="جاري التحقق من الصلاحيات..." />;
  }

  if (!isAuthorized) {
    const accessToken = authService.getAccessToken();
    if (!accessToken) {
      return <Navigate to="/login" state={{ from: location }} replace />;
    }

    // If logged in but wrong role, redirect to their dashboard, avoiding infinite loop on same path
    const userRole = authRepository.getRole();
    if (userRole === 'admin' && !location.pathname.startsWith('/admin')) {
      return <Navigate to="/admin" replace />;
    }
    if (userRole === 'instructor' && !location.pathname.startsWith('/instructor')) {
      return <Navigate to="/instructor" replace />;
    }
    if (userRole === 'student' && !location.pathname.startsWith('/student')) {
      return <Navigate to="/student" replace />;
    }
    
    // Default safe fallback to break infinite loops
    if (location.pathname !== '/') {
      return <Navigate to="/" replace />;
    }
    return null;
  }

  return <>{children}</>;
};

export default ProtectedRoute;
