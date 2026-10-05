export const authRepository = {
  getUser: () => {
    try {
      const u = sessionStorage.getItem('user');
      return u ? JSON.parse(u) : null;
    } catch {
      return null;
    }
  },
  setUser: (val: any) => {
    sessionStorage.setItem('user', JSON.stringify(val));
  },
  getIsLoggedIn: () => {
    return sessionStorage.getItem('isLoggedIn') === 'true';
  },
  getAccessToken: () => {
    return sessionStorage.getItem('isLoggedIn') === 'true' ? 'session_active' : '';
  },
  setIsLoggedIn: (val: boolean) => {
    sessionStorage.setItem('isLoggedIn', String(val));
  },
  getUsername: () => {
    return sessionStorage.getItem('username') || '';
  },
  setUsername: (val: string) => {
    sessionStorage.setItem('username', val);
  },
  getRole: () => {
    return sessionStorage.getItem('role') || '';
  },
  setRole: (val: string) => {
    sessionStorage.setItem('role', val);
  },
  getImpersonationUsername: () => {
    return sessionStorage.getItem('impersonationUsername') || '';
  },
  setImpersonationUsername: (val: string) => {
    sessionStorage.setItem('impersonationUsername', val);
  },
  removeImpersonationUsername: () => {
    sessionStorage.removeItem('impersonationUsername');
  },
  clearAuth: () => {
    sessionStorage.removeItem('isLoggedIn');
    sessionStorage.removeItem('username');
    sessionStorage.removeItem('role');
    sessionStorage.removeItem('impersonationUsername');
  },
  getRememberMe: () => {
    return sessionStorage.getItem('rememberMe') === 'true';
  },
  setRememberMe: (val: boolean) => {
    sessionStorage.setItem('rememberMe', String(val));
  },
  removeRememberMe: () => {
    sessionStorage.removeItem('rememberMe');
  },
  getStoredUsername: () => {
    return sessionStorage.getItem('storedUsername') || '';
  },
  setStoredUsername: (val: string) => {
    sessionStorage.setItem('storedUsername', val);
  },
  removeStoredUsername: () => {
    sessionStorage.removeItem('storedUsername');
  },
  getLoginSessions: () => {
    try {
      const stored = sessionStorage.getItem('login_sessions');
      return stored ? JSON.parse(stored) : [];
    } catch {
      return [];
    }
  },
  setLoginSessions: (val: any) => {
    sessionStorage.setItem('login_sessions', JSON.stringify(val));
  },
};
