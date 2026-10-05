import React, { useState } from 'react';
import { motion } from 'motion/react';
import PlatformLogo from './PlatformLogo';
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
    <div dir="rtl" className="min-h-[85vh] lg:min-h-[calc(100vh-98px)] flex flex-col lg:flex-row bg-slate-50 dark:bg-slate-900/10 font-sans transition-colors duration-300 select-none">
      {/* RIGHT half — login-form panel. Default DOM order renders it
          physically on the right under `dir="rtl"`, same convention
          SignupPage.tsx uses for its own two-column split. */}
      <div className="relative w-full lg:w-1/2 flex items-center justify-center overflow-y-auto py-6 px-4 sm:px-6 lg:px-8">
      {/* Dynamic Background Atmosphere Elements */}
      <div className="absolute top-1/4 right-1/4 w-[280px] h-[280px] bg-blue-600/12 dark:bg-blue-600/15 rounded-full blur-[90px] -z-10 pointer-events-none" />
      <div className="absolute bottom-1/4 left-1/4 w-[320px] h-[320px] bg-indigo-500/8 dark:bg-indigo-500/12 rounded-full blur-[100px] -z-10 pointer-events-none" />

      <div className="max-w-md w-full">
          <motion.div
            initial={{ opacity: 0, y: 15 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4 }}
            className="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800/80 shadow-2xl rounded-3xl p-6 relative overflow-hidden"
            id="unified_login_card"
          >
            {/* Header info */}
            <div className="text-center mb-5 pb-3 border-b border-slate-100 dark:border-slate-800/60">
              <div className="relative w-16 h-16 mx-auto mb-2">
                <PlatformLogo className="w-full h-full object-contain" />
                <div className="absolute -bottom-1 -left-1 w-5 h-5 rounded-full bg-blue-600 dark:bg-blue-500 text-white flex items-center justify-center border-2 border-white dark:border-slate-900 shadow-md">
                  <Lock className="w-3 h-3" />
                </div>
              </div>
              <h1 className="text-xl font-black text-slate-900 dark:text-white">
                تسجيل الدخول
              </h1>
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
                <p className="text-[10px] text-slate-400 dark:text-slate-500 font-bold leading-relaxed">
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
                    aria-label="Toggle Password Visibility"
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
                className={`w-full flex items-center justify-center gap-2.5 text-white py-3 px-6 rounded-2xl font-black text-base cursor-pointer ${isLoading ? 'opacity-70 cursor-not-allowed' : ''}`}
                style={{ backgroundColor: '#3544e1' }}
              >
                {isLoading ? (
                  <div className="flex items-center gap-2">
                    <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin" />
                    <span>جاري التحميل...</span>
                  </div>
                ) : (
                  <>
                    <span>تسجيل الدخول</span>
                    <ArrowLeft className="w-5 h-5 text-white" />
                  </>
                )}
              </button>

            </form>

            {/* Field 5: Signup Link */}
            <div className="mt-5 pt-3 border-t border-slate-100 dark:border-slate-800/60 text-center text-sm">
              <span className="text-slate-500 dark:text-slate-400">ليس لديك حساب؟ </span>
              <button 
                onClick={onNavigateToSignup}
                className="font-extrabold hover:underline cursor-pointer select-none p-1"
                style={{ color: '#3544e1' }}
              >
                إنشاء حساب
              </button>
            </div>

          </motion.div>
      </div>
      </div>

      {/* LEFT half — solid brand-blue panel with the login illustration.
          Default DOM order (second child) renders it physically on the left
          under `dir="rtl"`. Hidden below `lg`, same as SignupPage.tsx's
          own panel — a 50/50 split has no room to spare on a phone. */}
      <div
        className="hidden lg:flex lg:w-1/2 items-center justify-center p-8 shrink-0"
        style={{ backgroundColor: '#3544e1' }}
      >
        <img
          src="/branding/signin.svg"
          alt=""
          aria-hidden="true"
          className="w-full max-w-md h-auto object-contain"
        />
      </div>
    </div>
  );
}
