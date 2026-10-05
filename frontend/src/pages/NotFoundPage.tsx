import { Compass } from 'lucide-react';
import { useNavigate } from 'react-router';
import Header from '../components/Header';
import Footer from '../components/Footer';

export default function NotFoundPage() {
  const navigate = useNavigate();

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950 font-cairo flex flex-col" dir="rtl">
      <Header
        currentView="home"
        onNavigate={(page: string) => navigate(page === 'home' ? '/' : `/${page}`)}
      />

      <main className="flex-1 container mx-auto px-6 py-24 flex items-center justify-center">
        <div className="max-w-lg mx-auto text-center bg-white dark:bg-slate-900 rounded-3xl p-8 md:p-12 shadow-sm border border-slate-200 dark:border-slate-800">
          <div className="w-16 h-16 mx-auto rounded-2xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-500 mb-6">
            <Compass className="w-8 h-8" />
          </div>
          <h1 className="text-3xl font-black text-slate-900 dark:text-white mb-3">404 - الصفحة غير موجودة</h1>
          <p className="text-slate-500 dark:text-slate-400 mb-8">
            الرابط الذي تحاول الوصول إليه غير موجود أو تم نقله.
          </p>
          <button
            onClick={() => navigate('/')}
            className="px-6 py-3 rounded-xl bg-blue-600 text-white font-bold hover:bg-blue-700 transition-colors"
          >
            العودة للصفحة الرئيسية
          </button>
        </div>
      </main>

      <Footer />
    </div>
  );
}
