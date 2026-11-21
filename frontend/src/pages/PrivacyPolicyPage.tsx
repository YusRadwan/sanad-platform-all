import LegalPage from './LegalPage';

/**
 * Public legal page. Rendered inside App's own Header/Footer — it used to
 * mount a second Header and Footer of its own, so the page showed two of each.
 * The "آخر تحديث" line is gone too: it printed today's date on every visit,
 * which is not when the text was last changed.
 */
export default function PrivacyPolicyPage() {
  return (
    <LegalPage title="سياسة الخصوصية">

            <p>
              نحن نأخذ خصوصيتك على محمل الجد. توضح هذه السياسة كيف نقوم بجمع واستخدام وحماية معلوماتك الشخصية عند استخدامك لمنصة سند التعليمية.
            </p>
            <h2>1. جمع المعلومات</h2>
            <p>
              نقوم بجمع المعلومات التي تقدمها لنا مباشرة عند التسجيل، مثل الاسم والبريد الإلكتروني ورقم الهاتف. كما نقوم تلقائياً بجمع بيانات حول كيفية استخدامك للمنصة، مثل الكورسات التي تشاهدها والامتحانات التي تجريها.
            </p>
            <h2>2. استخدام المعلومات</h2>
            <p>
              نستخدم معلوماتك لتحسين تجربتك التعليمية، وتقديم محتوى مخصص، والتواصل معك بخصوص التحديثات والإشعارات المهمة. لا نقوم ببيع معلوماتك لأطراف خارجية.
            </p>
            <h2>3. أمان البيانات</h2>
            <p>
              نستخدم إجراءات أمنية متقدمة لحماية بياناتك من الوصول غير المصرح به أو التعديل أو الإفشاء أو الإتلاف. نحن نلتزم بأعلى معايير الأمان التقنية.
            </p>
    </LegalPage>
  );
}
