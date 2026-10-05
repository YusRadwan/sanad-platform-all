import { motion } from 'motion/react';
import {
  Compass,
  Users,
  BookmarkCheck,
  ChevronLeft,
} from 'lucide-react';
import SectionDivider from './SectionDivider';

interface Step {
  icon: typeof Compass;
  title: string;
  description: string;
  /** Solid gradient fill for the 3D icon plaque, unique per step. */
  gradient: string;
  /** Colour of the large faint step-number watermark behind the text. */
  numberColor: string;
}

// Three steps (the 4th — "ذاكر وامتحن" — was dropped). REDESIGNED (second
// pass) into a horizontal "floating isometric card deck": three genuinely
// tilted (rotateX + rotateY, not the old single-axis rotateY) glass cards
// side by side, each with a faux 3D-cube icon plaque (an offset duplicate
// face behind the real one, not just a raised square), staggered at
// different heights so the row reads as a diagonal "journey" left to right
// (RTL: card 1 sits highest on the right, card 3 lowest on the left) with
// small connector chevrons between them. On hover a card un-tilts flat and
// lifts — the tilt/untilt IS the 3D language here, replacing the old
// straight-slide-in cards. Deliberately a different visual language from
// FeaturesSection.tsx's glossy icon SPHERES.
const STEPS: Step[] = [
  {
    icon: Compass,
    title: 'اختر مرحلتك ومادتك',
    description: 'في الأول، حدد مرحلتك الدراسية (الإعدادية - الثانوية) وبعدها اختار المادة اللي حابب تبدأ تذاكرها.',
    gradient: 'from-blue-500 to-blue-700',
    numberColor: '#2563eb',
  },
  {
    icon: Users,
    title: 'تصفح المعلمين و الكورسات',
    description: 'اختر من بين أفضل المدرسين، وابدأ التعلم عبر كورسات مقسّمة بذكاء تغطي الشرح والمراجعات خطوة بخطوة.',
    gradient: 'from-teal-500 to-teal-700',
    numberColor: '#0d9488',
  },
  {
    icon: BookmarkCheck,
    title: 'اشترك في الكورس',
    description: 'لقيت كورس عاجبك؟ اشترك دلوقتي وابدأ تتعلم فوراً، وتقدر تتابع من الموبايل أو اللابتوب في أي وقت.',
    gradient: 'from-amber-500 to-amber-700',
    numberColor: '#b45309',
  },
];

export default function HowItWorksSection() {
  return (
    <section className="pt-24 pb-10 md:pb-12 border-t border-slate-100 dark:border-slate-900/65 transition-colors duration-300 relative overflow-hidden">
      <div className="container mx-auto px-6 md:px-12 lg:px-16 relative z-10">

        {/* Section title header — plain page background, no illustration
            behind it. The image now only starts BELOW this block (see the
            full-bleed panel underneath), instead of running the full
            section height behind the title too. */}
        <div className="text-center max-w-3xl mx-auto mb-16">
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="flex items-center gap-3 justify-center mb-4"
          >
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-l from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
            <span className="text-base font-black tracking-wide text-blue-700 dark:text-blue-400">إزاي سند بتشتغل؟</span>
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
          </motion.div>
          <motion.h2
            initial={{ opacity: 0, y: 15 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: 0.1 }}
            className="text-3xl md:text-5xl font-black text-slate-900 dark:text-white mb-6 tracking-tight flex items-center justify-center flex-wrap gap-3"
          >
            <span>خلّي رحلتك أسهل مع</span>
            <span className="text-6xl md:text-8xl leading-none inline-block" style={{ color: '#3544e1' }}>
              سند
            </span>
          </motion.h2>
          <div className="w-20 h-1.5 bg-gradient-to-l from-blue-600 to-indigo-600 mx-auto mt-8 rounded-full shadow-sm" />
        </div>
      </div>

      {/* Full-bleed panel — starts right here, BELOW the title block above,
          rather than running behind the whole section. Solid brand-blue
          backdrop; the old pinned character illustration is gone (a
          three-column card row has no side gutter left for it) in favour of
          soft ambient depth: blurred colour orbs plus a few thin isometric
          square outlines, reinforcing the "3D" language without needing a
          new image asset. */}
      <div
        className="relative left-1/2 -translate-x-1/2 w-screen overflow-hidden py-16 md:py-24"
        style={{ backgroundColor: '#3544e1' }}
      >
        <SectionDivider variant="wave" position="top" />
        <SectionDivider variant="wave" position="bottom" />

        <div className="absolute -top-16 right-[8%] w-72 h-72 rounded-full bg-white/10 blur-[100px] pointer-events-none" />
        <div className="absolute bottom-0 left-[10%] w-80 h-80 rounded-full bg-indigo-300/15 blur-[110px] pointer-events-none" />
        <div
          aria-hidden="true"
          className="hidden md:block absolute top-14 left-[14%] w-16 h-16 border-2 border-white/15 rounded-2xl pointer-events-none"
          style={{ transform: 'rotate(18deg)' }}
        />
        <div
          aria-hidden="true"
          className="hidden md:block absolute bottom-16 right-[16%] w-10 h-10 border-2 border-white/15 rounded-xl pointer-events-none"
          style={{ transform: 'rotate(-12deg)' }}
        />

        <div className="relative z-10 container mx-auto px-6 md:px-12 lg:px-16">
          {/* Three floating, individually tilted "isometric" cards laid out
              side by side (RTL, so step 1 is the rightmost column) — each at
              a different vertical offset so the row reads as a diagonal
              "journey" rather than a flat grid, with a chevron connector
              between consecutive cards on desktop. Hovering a card straightens
              it flat and lifts it — the tilt/untilt transition is the 3D
              effect here. */}
          <div
            style={{ perspective: '2200px' }}
            className="grid grid-cols-1 sm:grid-cols-3 gap-10 sm:gap-6 md:gap-8 max-w-6xl mx-auto items-start"
          >
            {STEPS.map((step, index) => {
              const Icon = step.icon;
              // Staggered elevation per column: 1st step highest, 3rd lowest —
              // a gentle diagonal cascade suggesting forward progress.
              const liftClass = ['sm:mt-0', 'sm:mt-8', 'sm:mt-16'][index];
              const baseRotateY = index === 1 ? 0 : index === 0 ? -10 : 10;

              return (
                <div key={step.title} className={`relative ${liftClass}`}>
                  <motion.div
                    initial={{ opacity: 0, y: 60, rotateX: 16, rotateY: baseRotateY }}
                    whileInView={{ opacity: 1, y: 0, rotateX: 9, rotateY: baseRotateY * 0.6 }}
                    viewport={{ once: true, margin: '-60px' }}
                    whileHover={{ rotateX: 0, rotateY: 0, y: -10, scale: 1.035 }}
                    transition={{ duration: 0.7, delay: index * 0.15, ease: [0.16, 1, 0.3, 1] }}
                    style={{ transformStyle: 'preserve-3d' }}
                    className="group relative"
                  >
                    {/* Duplicate "depth" faces stacked diagonally behind the
                        real card — the layered-thickness cue that makes this
                        read as a solid 3D slab rather than a flat panel. */}
                    <div
                      aria-hidden="true"
                      className={`absolute inset-0 rounded-[28px] translate-x-3 translate-y-3 bg-gradient-to-br ${step.gradient} opacity-30`}
                    />
                    <div
                      aria-hidden="true"
                      className={`absolute inset-0 rounded-[28px] translate-x-6 translate-y-6 bg-gradient-to-br ${step.gradient} opacity-15`}
                    />

                    <div className="relative rounded-[28px] bg-white p-6 md:p-7 shadow-[0_4px_10px_-2px_rgba(8,15,45,0.2),0_28px_50px_-16px_rgba(8,15,45,0.45)] group-hover:shadow-[0_6px_16px_-2px_rgba(8,15,45,0.26),0_44px_70px_-16px_rgba(8,15,45,0.55)] transition-shadow duration-500 overflow-hidden">
                      {/* Giant faint step-number watermark. */}
                      <span
                        aria-hidden="true"
                        className="absolute -bottom-6 -left-4 text-[110px] font-black leading-none select-none pointer-events-none"
                        style={{ color: step.numberColor, opacity: 0.07 }}
                      >
                        {index + 1}
                      </span>

                      {/* Faux 3D-cube icon plaque: an offset darker "side
                          face" behind the lit front face. */}
                      <div className="relative w-16 h-16 mb-5" style={{ transform: 'translateZ(30px)' }}>
                        <div className={`absolute inset-0 rounded-2xl translate-x-2 translate-y-2 bg-gradient-to-br ${step.gradient} opacity-50 blur-[1.5px]`} />
                        <div
                          className={`relative w-full h-full rounded-2xl flex items-center justify-center bg-gradient-to-br ${step.gradient} text-white shadow-[inset_0_2px_3px_rgba(255,255,255,0.55),inset_0_-5px_9px_rgba(0,0,0,0.2)] group-hover:-rotate-6 group-hover:scale-110 transition-transform duration-500`}
                        >
                          <Icon className="w-7 h-7" strokeWidth={1.8} />
                        </div>
                      </div>

                      <div className="relative" style={{ transform: 'translateZ(18px)' }}>
                        <h3 className="text-lg md:text-xl font-extrabold tracking-tight leading-snug text-slate-900 mb-1.5">
                          {step.title}
                        </h3>
                        <p className="text-sm font-medium leading-relaxed text-slate-600">
                          {step.description}
                        </p>
                      </div>
                    </div>
                  </motion.div>

                  {/* Connector between this card and the next one in reading
                      order (RTL: card 1 right -> card 3 left), hidden on the
                      last card and on mobile where cards stack vertically. */}
                  {index < STEPS.length - 1 && (
                    <div className="hidden sm:flex absolute top-1/2 -translate-y-1/2 -left-8 md:-left-10 z-10 items-center justify-center w-8 h-8 rounded-full bg-white/15 ring-1 ring-inset ring-white/25 backdrop-blur-sm">
                      <ChevronLeft className="w-4 h-4 text-white/80" strokeWidth={2.5} />
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
}
