import { motion } from 'motion/react';
import { GraduationCap, Users, Sparkles, ArrowLeft } from 'lucide-react';
import SectionDivider from './SectionDivider';

const BENEFITS = [
  { icon: Users, text: 'الوصول إلى آلاف الطلاب في المرحلتين الإعدادية والثانوية' },
  { icon: GraduationCap, text: 'أدوات تدريس رقمية جاهزة: كورسات، فيديوهات، امتحانات ومتابعة أداء' },
  { icon: Sparkles, text: 'نظام أرباح شفاف ولوحة تحكم كاملة لإدارة محتواك وطلابك' },
];

export default function JoinUsSection({ onNavigate }: { onNavigate?: (view: string) => void }) {
  return (
    <section
      id="join-us"
      className="pt-24 border-t border-slate-100 dark:border-slate-900/65 transition-colors duration-300 relative overflow-hidden"
      dir="rtl"
    >
      <div className="container mx-auto px-6 md:px-12 lg:px-16 relative z-10">

        {/* Kicker — plain page background here; the brand-blue fill only
            starts below this title block (see the full-bleed panel). */}
        <motion.div
          initial={{ opacity: 0, y: 12 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          className="flex items-center gap-3 justify-center mb-4"
        >
          <span className="h-[3px] w-9 rounded-full bg-gradient-to-l from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
          <span className="text-base font-black tracking-wide text-blue-700 dark:text-blue-400">
            فرص التدريس والتعاون
          </span>
          <span className="h-[3px] w-9 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
        </motion.div>

        <h2 className="text-4xl md:text-5xl font-black text-slate-900 dark:text-white text-center tracking-tight flex items-center justify-center gap-4">
          <span>كُن أنت</span>
          <span className="text-6xl md:text-8xl pb-2 md:pb-4 leading-none inline-block font-black" style={{ color: '#3544e1' }}>
            السند
          </span>
        </h2>
        <p className="text-center text-slate-500 dark:text-slate-400 text-base md:text-lg font-bold mb-16">
          رسالتك تُغير جيلاً.... كُن السند وشاركنا صناعة المستقبل
        </p>
      </div>

      {/* Full-bleed brand-blue panel — the background colour starts HERE,
          below the title block above, not from the top of the section. */}
      <div
        className="relative left-1/2 -translate-x-1/2 w-screen overflow-hidden py-16 md:py-24"
        style={{ backgroundColor: '#3544e1' }}
      >
        <SectionDivider variant="arc" position="top" />
        <SectionDivider variant="arc" position="bottom" />

        <div className="absolute -top-24 -right-24 w-96 h-96 rounded-full bg-white/5 blur-[120px] pointer-events-none" />
        <div className="absolute bottom-0 left-0 w-[28rem] h-[28rem] rounded-full bg-white/5 blur-[130px] pointer-events-none" />

        <div className="container mx-auto px-6 md:px-12 lg:px-16 relative z-10">
          {/* Two-column body: the teacher illustration sits on the physical
              LEFT (default DOM order — unaffected by `dir="rtl"`), the pitch +
              benefits + CTA on the physical right, matching the same
              left-image/right-copy convention HeroSection already uses. */}
          <div className="grid lg:grid-cols-2 gap-14 lg:gap-10 items-center">

          <motion.div
            initial={{ opacity: 0, y: 30 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-100px' }}
            transition={{ duration: 0.6, ease: 'easeOut' }}
            className="order-last lg:order-first text-center lg:text-right"
          >
            <span className="inline-block text-xs font-black tracking-wide text-white bg-white/10 border border-white/15 rounded-full px-4 py-1.5 mb-5">
              انضم إلى نخبة المعلمين
            </span>

            <h3 className="text-2xl md:text-3xl lg:text-4xl font-black text-white leading-[1.2] mb-4">
              يمكنك الآن الانضمام إلى كادر معلمي منصة سند
            </h3>

            <p className="text-base md:text-lg text-white/80 leading-relaxed font-medium mb-8 max-w-xl mx-auto lg:mx-0">
              منصة متكاملة توفر لك كل ما تحتاجه لتدريس مناهج المرحلتين الإعدادية والثانوية بأدوات رقمية ذكية وسهلة الاستخدام.
            </p>

            <ul className="space-y-2.5 text-right w-full max-w-md mx-auto lg:mx-0 mb-10">
              {BENEFITS.map((benefit) => (
                <li
                  key={benefit.text}
                  className="flex items-center gap-3.5 bg-white/10 border border-white/15 rounded-2xl px-4 py-3.5"
                >
                  <span className="shrink-0 w-10 h-10 rounded-xl bg-white/15 text-white flex items-center justify-center">
                    <benefit.icon className="w-5 h-5" />
                  </span>
                  <span className="text-sm md:text-base font-semibold text-white leading-relaxed">
                    {benefit.text}
                  </span>
                </li>
              ))}
            </ul>

            <motion.button
              onClick={() => onNavigate?.('teacher-application')}
              whileHover={{ y: -3 }}
              whileTap={{ scale: 0.97 }}
              className="group/join inline-flex items-center gap-3.5 pr-3 pl-9 py-3 bg-white font-black text-sm md:text-base rounded-full shadow-xl shadow-black/20 cursor-pointer"
              style={{ color: '#3544e1' }}
            >
              <span className="shrink-0 w-10 h-10 rounded-full flex items-center justify-center bg-[#3544e1]/10 transition-transform duration-300 group-hover/join:-translate-x-1">
                <ArrowLeft className="w-5 h-5" />
              </span>
              ابدأ التدريس معنا الآن
            </motion.button>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 30 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-100px' }}
            transition={{ duration: 0.6, delay: 0.1, ease: 'easeOut' }}
            className="flex justify-center lg:justify-start"
          >
            <img
              src="/branding/teacherpic.svg"
              alt="معلم يشرح على منصة سند"
              className="w-full max-w-2xl lg:max-w-3xl h-auto object-contain"
            />
          </motion.div>

          </div>
        </div>
      </div>
    </section>
  );
}
