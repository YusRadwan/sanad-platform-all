import { FileText } from 'lucide-react';
import { formatDate } from '../utils/dateUtils';
import Header from '../components/Header';
import Footer from '../components/Footer';
import { useNavigate } from 'react-router';

export default function TermsConditionsPage() {
  const navigate = useNavigate();

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950 font-cairo" dir="rtl">
      <Header 
        currentView="home"
        onNavigate={(page: string) => navigate(page === 'home' ? '/' : `/${page}`)}
      />
      
      <main className="container mx-auto px-6 py-16">
        <div className="max-w-4xl mx-auto bg-white dark:bg-slate-900 rounded-3xl p-8 md:p-12 shadow-sm border border-slate-200 dark:border-slate-800">
          <div className="flex items-center gap-4 mb-8 border-b border-slate-100 dark:border-slate-800 pb-8">
            <div className="w-16 h-16 rounded-2xl bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-500">
              <FileText className="w-8 h-8" />
            </div>
            <div>
              <h1 className="text-3xl font-black text-slate-900 dark:text-white">الشروط والأحكام</h1>
              <p className="text-slate-500 dark:text-slate-400 mt-2">آخر تحديث: {formatDate(Date.now())}</p>
            </div>
          </div>

          <div className="prose prose-slate dark:prose-invert max-w-none">
            <p className="text-lg leading-relaxed text-slate-700 dark:text-slate-300">
              باستخدامك لمنصة سند التعليمية، فإنك توافق على الالتزام بالشروط والأحكام التالية. يرجى قراءتها بعناية.
            </p>
            <h3 className="text-xl font-bold mt-8 mb-4">1. حساب المستخدم</h3>
            <p className="leading-relaxed text-slate-700 dark:text-slate-300">
              أنت مسؤول عن الحفاظ على سرية حسابك وكلمة المرور الخاصة بك. يجب أن تكون المعلومات التي تقدمها دقيقة وحديثة. لا يجوز مشاركة الحساب مع أشخاص آخرين.
            </p>
            <h3 className="text-xl font-bold mt-8 mb-4">2. الملكية الفكرية</h3>
            <p className="leading-relaxed text-slate-700 dark:text-slate-300">
              جميع المحتويات الموجودة على المنصة (بما في ذلك الفيديوهات، المذكرات، والاختبارات) هي ملكية حصرية للمنصة والمعلمين. يُمنع منعاً باتاً نسخ أو توزيع أو إعادة بيع أي محتوى بدون إذن صريح.
            </p>
            <h3 className="text-xl font-bold mt-8 mb-4">3. سياسة الدفع والاسترداد</h3>
            <p className="leading-relaxed text-slate-700 dark:text-slate-300">
              جميع عمليات الدفع لشراء الكورسات والاشتراكات غير قابلة للاسترداد بعد تفعيل الكورس، إلا في حالات استثنائية وبناءً على قرار الإدارة.
            </p>
          </div>
        </div>
      </main>
      
      <Footer />
    </div>
  );
}
