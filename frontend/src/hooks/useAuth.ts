import { useContext, useCallback } from 'react';
import { AuthContext } from '../context/AuthContext';
import { authService } from '../services/authService';
import { adminService } from '../services/adminService';
import { useNavigate } from 'react-router';

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  
  const { isLoggedIn, username, userRole, adminImpersonationUsername, dispatch } = context;
  const navigate = useNavigate();

  const handleLogin = useCallback((role: string, name: string) => {
    const resolvedRole = role === 'إداري' ? 'admin' : role === 'معلم' ? 'instructor' : 'student';
    authService.setIsLoggedIn(true);
    authService.setUsername(name);
    authService.setRole(resolvedRole);
    
    dispatch({ type: 'LOGIN', payload: { username: name, userRole: resolvedRole as any } });
  }, [dispatch]);

  const handleLogout = useCallback(() => {
    authService.logout();
    dispatch({ type: 'LOGOUT' });
  }, [dispatch]);

  /**
   * Logs the admin's browser session in as the target student
   * (POST /admin/students/{id}/impersonate — a real session swap on the
   * server, not a client-side pretend-role). `studentId` is the student's
   * user id, not a display name.
   */
  const impersonateUser = useCallback(async (studentId: string) => {
    const adminName = authService.getImpersonationUsername() || authService.getUsername() || 'الأدمن المشرف';
    const targetUser = await adminService.impersonateStudent(studentId);
    const targetName = targetUser.full_name || targetUser.fullName || targetUser.username || '';

    authService.setImpersonationUsername(adminName);
    authService.setIsLoggedIn(true);
    authService.setRole('student');
    authService.setUsername(targetName);
    authService.setUser(targetUser);

    dispatch({
      type: 'START_IMPERSONATION',
      payload: { targetUsername: targetName, adminUsername: adminName },
    });

    navigate('/student');
  }, [dispatch, navigate]);

  /** Restores the admin session that started an active impersonation. */
  const stopImpersonation = useCallback(async () => {
    const adminUser = await authService.stopImpersonation();
    const adminName = adminUser.full_name || adminUser.fullName || adminUser.username || '';

    authService.setRole('admin');
    authService.setUsername(adminName);
    authService.setUser(adminUser);
    authService.removeImpersonationUsername();

    dispatch({ type: 'STOP_IMPERSONATION', payload: { adminUsername: adminName } });

    navigate('/admin');
  }, [dispatch, navigate]);

  return {
    isLoggedIn,
    username,
    userRole,
    adminImpersonationUsername,
    handleLogin,
    handleLogout,
    stopImpersonation,
    impersonateUser,
  };
};
