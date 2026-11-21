import React, { useState } from 'react';
import { motion } from 'motion/react';
import { User, Lock, Eye, EyeOff, AlertCircle, ArrowLeft } from 'lucide-react';
import { LoginPageProps } from '../types/index';
import { authService } from '../services/authService';
import { useCenteredNotification } from '../context/CenteredNotificationContext';






export default function LoginPage({ onBackToHome, onNavigateToSignup, onLoginSuccess, onNavigateToStudent, onNavigateToInstructor, onNavigateToAdmin }: LoginPageProps & { onNavigateToInstructor?: () => void, onNavigateToAdmin?: () => void }) {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);

  // Validation and Submission feedback
  const [error, setError] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const { notify } = useCenteredNotification();

  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setIsLoading(true);

    if (!username.trim() || !password.trim()) {
      const message = 'الرجاء إدخال اسم المستخدم أو رقم الهاتف وكلمة المرور';
      setError(message);
      notify(message, 'error');
      setIsLoading(false);
      return;
    }

    try {
      const data = await authService.login(username.trim(), password);

      // Keys MUST be the literal values of users.role, whose ENUM is
      // ('student', 'instructor', 'admin') — see migration 0001. This map
      // previously keyed on 'teacher' and 'staff', neither of which the
      // database can ever produce, so a real instructor fell through to the
      // `|| 'طالب'` default and was redirected to the STUDENT dashboard.
      const roleMapping: Record<string, 'طالب' | 'معلم' | 'إداري'> = {
        'admin': 'إداري',
        'instructor': 'معلم',
        'student': 'طالب'
      };

      const finalRole = roleMapping[data.user.role] || 'طالب';

      if (rememberMe) {
        authService.setRememberMe(true);
        authService.setStoredUsername(data.user.username);
      }

      notify('تم تسجيل الدخول بنجاح', 'success');

      if (onLoginSuccess) {
        onLoginSuccess(finalRole, data.user.fullName || username || 'مستخدم');
      }
      if (finalRole === 'معلم' && onNavigateToInstructor) {
        onNavigateToInstructor();
      } else if (finalRole === 'إداري' && onNavigateToAdmin) {
        onNavigateToAdmin();
      } else if (finalRole === 'طالب' && onNavigateToStudent) {
        onNavigateToStudent();
      } else {
        onBackToHome?.();
      }
    } catch (err: any) {
      console.error('Login error:', err);
      const message =
        err.response && err.response.data && err.response.data.message
          ? err.response.data.message
          : 'حدث خطأ أثناء الاتصال بالخادم. يرجى المحاولة مرة أخرى.';
      setError(message);
      notify(message, 'error');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div dir="rtl" className="min-h-[calc(100vh-72px)] flex bg-[var(--paper)]">
      {/* RIGHT half — login-form panel. Default DOM order renders it
          physically on the right under `dir="rtl"`, same convention
          SignupPage.tsx uses for its own two-column split. */}
      <div className="relative w-full flex items-center justify-center py-12 px-5 sm:px-8">
      <div className="max-w-md w-full">
          <motion.div
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4 }}
            className="bg-[var(--paper-card)] border border-[var(--hair-strong)] rounded-[20px] p-6 sm:p-8 relative"
            id="unified_login_card"
          >
            {/* Header info */}
            <div className="mb-6 flex flex-col gap-1">
              <h1 className="m-0 font-kufi text-[30px] font-semibold leading-[1.35]">دخول</h1>
              <p className="m-0 text-[15px] text-slate-500 dark:text-slate-400">ادخل على حسابك وكمّل كورساتك</p>
            </div>

            {/* Error view */}
            {error && (
              <motion.div 
                initial={{ opacity: 0, y: -5 }}
                animate={{ opacity: 1, y: 0 }}
                className="mb-5 p-4 rounded-xl bg-rose-50 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-900/30 text-rose-600 dark:text-rose-400 text-xs font-bold flex items-center gap-2.5"
              >
                <AlertCircle className="w-5 h-5 shrink-0" />
                <span>{error}</span>
              </motion.div>
            )}

            {/* Complete unified multirole login form */}
            <form onSubmit={handleLoginSubmit} className="space-y-4" id="unified_login_form">
              
              {/* Field 1: Unified Username */}
              <div className="space-y-1.5">
                <label htmlFor="login_username" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                  اسم المستخدم أو رقم الهاتف
                </label>
                <div className="relative">
                  <input
                    id="login_username"
                    type="text"
                    required
                    placeholder="أدخل رقم الهاتف  أو اسم المستخدم  "
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    className="w-full px-11 py-3 bg-slate-50 dark:bg-slate-950 rounded-2xl border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-semibold"
                  />
                  <User className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                </div>
                <p className="text-[13px] text-slate-500 dark:text-slate-400 leading-relaxed">
                  للطلاب: رقم الهاتف المسجل. للأساتذة والإدارة: اسم المستخدم المعطى لكم.
                </p>
              </div>

              {/* Field 2: Password */}
              <div className="space-y-1.5">
                <div className="flex justify-between items-center">
                  <label htmlFor="login_password" className="block text-sm font-bold text-slate-700 dark:text-slate-300">
                    كلمة المرور
                  </label>
                </div>

                <div className="relative">
                  <input
                    id="login_password"
                    type={showPassword ? "text" : "password"}
                    required
                    placeholder="أدخل كلمة المرور الخاصة بحسابك"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    className="w-full pr-11 pl-11 py-3 bg-slate-50 dark:bg-slate-950 rounded-2xl border border-slate-200 dark:border-slate-800 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 font-semibold"
                  />
                  <Lock className="absolute right-4 top-3.5 w-4.5 h-4.5 text-slate-400 dark:text-slate-500 pointer-events-none" />
                  
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute left-4 top-3.5 text-slate-400 hover:text-blue-500 transition-all p-0.5 rounded cursor-pointer"
                    aria-label={showPassword ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
                  >
                    {showPassword ? <EyeOff className="w-4.5 h-4.5" /> : <Eye className="w-4.5 h-4.5" />}
                  </button>
                </div>
              </div>

              {/* Field 3: Remember Me */}
              <div className="flex items-center gap-2.5 pb-2">
                <input 
                  type="checkbox"
                  id="remember_me"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  className="w-4.5 h-4.5 text-blue-600 border-slate-300 dark:border-slate-800 rounded focus:ring-blue-500 focus:ring-opacity-20 cursor-pointer accent-blue-600"
                />
                <label htmlFor="remember_me" className="text-sm font-semibold text-slate-600 dark:text-slate-400 cursor-pointer select-none">
                  تذكرني
                </label>
              </div>

              {/* Field 4: Login Button */}
              <button
                type="submit"
                disabled={isLoading}
                className={`btn-primary w-full h-12 text-base ${isLoading ? 'opacity-70 cursor-not-allowed' : ''}`}
              >
                {isLoading ? (
                  <div className="flex items-center gap-2">
                    <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    <span>جاري التحميل...</span>
                  </div>
                ) : (
                  <>
                    <span>تسجيل الدخول</span>
                    <ArrowLeft className="w-5 h-5" aria-hidden="true" />
                  </>
                )}
              </button>

            </form>

            {/* Field 5: Signup Link */}
            <div className="mt-5 pt-3 border-t border-slate-100 dark:border-slate-800/60 text-center text-sm">
              <span className="text-slate-500 dark:text-slate-400">معندكش حساب؟ </span>
              <button 
                onClick={onNavigateToSignup}
                className="link-draw font-medium cursor-pointer p-1 text-[color:var(--sanad-ink)] dark:text-[color:var(--sanad-ink-on-dark)]"
              >
                إنشاء حساب
              </button>
            </div>

          </motion.div>
      </div>
      </div>

    </div>
  );
}
