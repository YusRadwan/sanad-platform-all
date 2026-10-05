import { Shield } from 'lucide-react';
import { formatDate } from '../utils/dateUtils';
import Header from '../components/Header';
import Footer from '../components/Footer';
import { useNavigate } from 'react-router';

export default function PrivacyPolicyPage() {
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
              <Shield className="w-8 h-8" />
            </div>
            <div>
              <h1 className="text-3xl font-black text-slate-900 dark:text-white">سياسة الخصوصية</h1>
              <p className="text-slate-500 dark:text-slate-400 mt-2">آخر تحديث: {formatDate(Date.now())}</p>
            </div>
          </div>

          <div className="prose prose-slate dark:prose-invert max-w-none">
            <p className="text-lg leading-relaxed text-slate-700 dark:text-slate-300">
              نحن نأخذ خصوصيتك على محمل الجد. توضح هذه السياسة كيف نقوم بجمع واستخدام وحماية معلوماتك الشخصية عند استخدامك لمنصة سند التعليمية.
            </p>
            <h3 className="text-xl font-bold mt-8 mb-4">1. جمع المعلومات</h3>
            <p className="leading-relaxed text-slate-700 dark:text-slate-300">
              نقوم بجمع المعلومات التي تقدمها لنا مباشرة عند التسجيل، مثل الاسم والبريد الإلكتروني ورقم الهاتف. كما نقوم تلقائياً بجمع بيانات حول كيفية استخدامك للمنصة، مثل الكورسات التي تشاهدها والامتحانات التي تجريها.
            </p>
            <h3 className="text-xl font-bold mt-8 mb-4">2. استخدام المعلومات</h3>
            <p className="leading-relaxed text-slate-700 dark:text-slate-300">
              نستخدم معلوماتك لتحسين تجربتك التعليمية، وتقديم محتوى مخصص، والتواصل معك بخصوص التحديثات والإشعارات المهمة. لا نقوم ببيع معلوماتك لأطراف خارجية.
            </p>
            <h3 className="text-xl font-bold mt-8 mb-4">3. أمان البيانات</h3>
            <p className="leading-relaxed text-slate-700 dark:text-slate-300">
              نستخدم إجراءات أمنية متقدمة لحماية بياناتك من الوصول غير المصرح به أو التعديل أو الإفشاء أو الإتلاف. نحن نلتزم بأعلى معايير الأمان التقنية.
            </p>
          </div>
        </div>
      </main>
      
      <Footer />
    </div>
  );
}
