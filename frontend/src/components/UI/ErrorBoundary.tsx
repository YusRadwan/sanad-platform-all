import { Component, ErrorInfo, ReactNode } from 'react';
import { logger } from '../../services/logger';
import { AlertCircle, RefreshCcw, Home } from 'lucide-react';

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends Component<Props, State> {
  public state: State = {
    hasError: false
  };

  public static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    logger.error('Uncaught Application Error:', error, { errorInfo });
  }

  private handleRefresh = () => {
    window.location.reload();
  };

  private handleGoHome = () => {
    window.location.href = '/';
  };

  public render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex items-center justify-center p-6 font-cairo" dir="rtl">
          <div className="max-w-md w-full bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 p-8 shadow-2xl shadow-slate-200/50 dark:shadow-none text-center">
            <div className="w-20 h-20 bg-red-100 dark:bg-red-500/10 rounded-full flex items-center justify-center mx-auto mb-6">
              <AlertCircle className="w-10 h-10 text-red-600 dark:text-red-500" />
            </div>
            
            <h1 className="text-2xl font-black text-slate-900 dark:text-white mb-4">
              عذراً، حدث خطأ غير متوقع
            </h1>
            
            <p className="text-slate-600 dark:text-slate-400 font-bold mb-8 leading-relaxed">
              لقد واجهنا مشكلة تقنية أثناء تشغيل التطبيق. يرجى المحاولة مرة أخرى أو العودة للصفحة الرئيسية.
            </p>

            <div className="space-y-3">
              <button
                onClick={this.handleRefresh}
                className="w-full flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-2xl transition-all shadow-lg shadow-blue-500/25 active:scale-95"
              >
                <RefreshCcw className="w-5 h-5" />
                تحديث الصفحة
              </button>
              
              <button
                onClick={this.handleGoHome}
                className="w-full flex items-center justify-center gap-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-black py-4 rounded-2xl transition-all active:scale-95"
              >
                <Home className="w-5 h-5" />
                العودة للرئيسية
              </button>
            </div>

            <div className="mt-8 pt-8 border-t border-slate-100 dark:border-slate-800">
              <p className="text-xs font-bold text-slate-400">
                إذا استمرت المشكلة، يرجى التواصل مع الدعم الفني
              </p>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
