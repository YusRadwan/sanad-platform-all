import { Link } from 'react-router';

/**
 * 404. Rendered inside App's Header/Footer (it used to mount a second pair of
 * its own). Offers the two places a lost visitor most likely wanted.
 */
export default function NotFoundPage() {
  return (
    <section className="mx-auto flex w-full max-w-[640px] flex-col items-start gap-5 px-5 py-20 sm:px-8 sm:py-28">
      <span className="text-[15px] text-slate-500 dark:text-slate-400">خطأ 404</span>
      <h1 className="m-0 font-kufi text-[34px] font-semibold leading-[1.35] sm:text-[44px]">
        الصفحة دي <span className="marker">مش موجودة</span>
      </h1>
      <p className="m-0 text-[17px] text-slate-600 dark:text-slate-300">ممكن يكون الرابط قديم أو فيه حرف غلط</p>
      <div className="mt-2 flex flex-wrap gap-3">
        <Link to="/" className="btn-primary h-12 px-6 text-base">
          الصفحة الرئيسية
        </Link>
        <Link to="/courses" className="btn-quiet h-12 px-6 text-base">
          كل الكورسات
        </Link>
      </div>
    </section>
  );
}
