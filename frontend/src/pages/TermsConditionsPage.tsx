import LegalPage from './LegalPage';

/**
 * Public legal page. Rendered inside App's own Header/Footer — it used to
 * mount a second Header and Footer of its own, so the page showed two of each.
 * The "آخر تحديث" line is gone too: it printed today's date on every visit,
 * which is not when the text was last changed.
 */
export default function TermsConditionsPage() {
  return (
    <LegalPage title="الشروط والأحكام">

            <p>
              باستخدامك لمنصة سند التعليمية، فإنك توافق على الالتزام بالشروط والأحكام التالية. يرجى قراءتها بعناية.
            </p>
            <h2>1. حساب المستخدم</h2>
            <p>
              أنت مسؤول عن الحفاظ على سرية حسابك وكلمة المرور الخاصة بك. يجب أن تكون المعلومات التي تقدمها دقيقة وحديثة. لا يجوز مشاركة الحساب مع أشخاص آخرين.
            </p>
            <h2>2. الملكية الفكرية</h2>
            <p>
              جميع المحتويات الموجودة على المنصة (بما في ذلك الفيديوهات، المذكرات، والاختبارات) هي ملكية حصرية للمنصة والمعلمين. يُمنع منعاً باتاً نسخ أو توزيع أو إعادة بيع أي محتوى بدون إذن صريح.
            </p>
            <h2>3. سياسة الدفع والاسترداد</h2>
            <p>
              جميع عمليات الدفع لشراء الكورسات والاشتراكات غير قابلة للاسترداد بعد تفعيل الكورس، إلا في حالات استثنائية وبناءً على قرار الإدارة.
            </p>
    </LegalPage>
  );
}
