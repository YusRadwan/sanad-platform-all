import React, { createContext, useReducer, useEffect, ReactNode, Dispatch } from 'react';
import { authService } from '../services/authService';

interface AuthState {
  isLoggedIn: boolean;
  username: string;
  userRole: 'student' | 'instructor' | 'admin';
  adminImpersonationUsername: string | null;
}

type AuthAction =
  | { type: 'LOGIN'; payload: { username: string; userRole: 'student' | 'instructor' | 'admin' } }
  | { type: 'LOGOUT' }
  | { type: 'START_IMPERSONATION'; payload: { targetUsername: string; adminUsername: string } }
  | { type: 'STOP_IMPERSONATION'; payload: { adminUsername: string } }
  | { type: 'SYNC_USER'; payload: { username: string; userRole: 'student' | 'instructor' | 'admin' } };

const initialState: AuthState = {
  isLoggedIn: authService.getIsLoggedIn(),
  username: authService.getUsername() || '',
  userRole: (authService.getRole() as any) || 'student',
  adminImpersonationUsername: authService.getImpersonationUsername(),
};

function authReducer(state: AuthState, action: AuthAction): AuthState {
  switch (action.type) {
    case 'LOGIN':
      return {
        ...state,
        isLoggedIn: true,
        username: action.payload.username,
        userRole: action.payload.userRole,
      };
    case 'LOGOUT':
      return {
        ...state,
        isLoggedIn: false,
        username: '',
        userRole: 'student',
        adminImpersonationUsername: null,
      };
    case 'START_IMPERSONATION':
      return {
        ...state,
        isLoggedIn: true,
        userRole: 'student',
        username: action.payload.targetUsername,
        adminImpersonationUsername: action.payload.adminUsername,
      };
    case 'STOP_IMPERSONATION':
      return {
        ...state,
        isLoggedIn: true,
        userRole: 'admin',
        username: action.payload.adminUsername,
        adminImpersonationUsername: null,
      };
    case 'SYNC_USER':
      return {
        ...state,
        username: action.payload.username,
        userRole: action.payload.userRole,
      };
    default:
      return state;
  }
}

interface AuthContextProps extends AuthState {
  dispatch: Dispatch<AuthAction>;
}

export const AuthContext = createContext<AuthContextProps | undefined>(undefined);

export const AuthProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [state, dispatch] = useReducer(authReducer, initialState);

  useEffect(() => {
    const syncUser = async () => {
      if (!state.isLoggedIn) return;
      try {
        const data = await authService.getMe();
        if (data && data.user) {
          authService.setUser(data.user);
          dispatch({
            type: 'SYNC_USER',
            payload: {
              // AuthService::publicUser() has no `name` key — it returns
              // full_name/fullName/username. Reading `.name` therefore synced
              // `undefined` over the stored display name on every mount, which
              // blanked the header greeting and made the avatar initial
              // (`name.charAt(0)`) read off undefined.
              username: data.user.full_name || data.user.fullName || data.user.username || '',
              userRole: data.user.role as any
            }
          });
        }
      } catch (error) {
        if ((error as any).response?.status !== 401 && (error as any).response?.status !== 400) console.error('Failed to sync user profile:', error);
      }
    };
    
    syncUser();
  }, [state.isLoggedIn]);

  return (
    <AuthContext.Provider value={{ ...state, dispatch }}>
      {children}
    </AuthContext.Provider>
  );
};
