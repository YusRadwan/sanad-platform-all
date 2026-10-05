import { motion } from 'motion/react';
import {
  BookOpen,
  GraduationCap,
  BarChart3,
  Home,
  PenTool,
  Lightbulb,
  FileCheck2,
  Calculator,
  Ruler,
  NotebookPen,
} from 'lucide-react';
import SectionDivider from './SectionDivider';

const iconMap = {
  book: BookOpen,
  teacher: GraduationCap,
  chart: BarChart3,
  home: Home,
};

const features = [
  {
    iconName: 'book' as const,
    title: 'مناهج متكاملة',
    description: 'شرح وتدريبات تغطي كافة جوانب المنهج الدراسي.',
  },
  {
    iconName: 'teacher' as const,
    title: 'نخبة المعلمين',
    description: 'دروس مقدمة من أفضل المعلمين ذوي الخبرة.',
  },
  {
    iconName: 'chart' as const,
    title: 'تقييم مستمر',
    description: 'اختبارات وواجبات دورية لمتابعة مستواك الدراسي أولاً بأول.',
  },
  {
    iconName: 'home' as const,
    title: 'توفير الوقت والجهد',
    description: 'دراسة مرنة من منزلك وبأعلى كفاءة ممكنة.',
  },
];

// Design configuration for each card theme to make them unique and hand-crafted
const cardThemes = [
  {
    gradient: 'from-blue-500 to-indigo-600',
    lightGlow: 'bg-blue-500/15 dark:bg-blue-500/20',
    iconRing: 'ring-blue-100 dark:ring-blue-500/20',
  },
  {
    gradient: 'from-purple-500 to-fuchsia-600',
    lightGlow: 'bg-purple-500/15 dark:bg-purple-500/20',
    iconRing: 'ring-purple-100 dark:ring-purple-500/20',
  },
  {
    gradient: 'from-emerald-500 to-teal-600',
    lightGlow: 'bg-emerald-500/15 dark:bg-emerald-500/20',
    iconRing: 'ring-emerald-100 dark:ring-emerald-500/20',
  },
  {
    gradient: 'from-amber-500 to-orange-600',
    lightGlow: 'bg-amber-500/15 dark:bg-amber-500/20',
    iconRing: 'ring-amber-100 dark:ring-amber-500/20',
  },
];

/**
 * A single ambient background glyph — a faint, slowly drifting education-themed
 * icon (book/pen/lightbulb/exam) behind the cards. Purely decorative texture,
 * never sharp/opaque enough to compete with the real content in front of it.
 */
function FloatingGlyph({
  icon: Icon,
  className,
  size = 40,
  duration = 8,
  delay = 0,
  xRange = 10,
  rotateRange = 8,
}: {
  icon: typeof BookOpen;
  className: string;
  size?: number;
  duration?: number;
  delay?: number;
  xRange?: number;
  rotateRange?: number;
}) {
  return (
    <motion.div
      className={`absolute pointer-events-none text-white/30 ${className}`}
      animate={{
        y: [0, -18, 0],
        x: [0, xRange, 0],
        rotate: [0, rotateRange, 0],
      }}
      transition={{
        duration,
        delay,
        repeat: Infinity,
        ease: 'easeInOut',
      }}
    >
      <Icon size={size} strokeWidth={1.5} />
    </motion.div>
  );
}

export default function FeaturesSection() {
  return (
    <section
      id="features"
      className="pt-24 border-t border-slate-100 dark:border-slate-900/65 transition-colors duration-300 relative overflow-hidden"
    >
      <div className="container mx-auto px-6 md:px-12 lg:px-16 relative z-10">

        {/* Section Title Header block — plain page background; the
            brand-yellow fill only starts below this, in the full-bleed
            panel that wraps the glyphs + cards. */}
        <div className="text-center max-w-3xl mx-auto mb-16">
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            className="flex items-center gap-3 justify-center mb-4"
          >
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-l from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
            <span className="text-base font-black tracking-wide text-blue-700 dark:text-blue-400">لماذا نحن؟</span>
            <span className="h-[3px] w-9 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 dark:from-blue-400 dark:to-indigo-400 shrink-0" />
          </motion.div>
          <motion.h2
            initial={{ opacity: 0, y: 15 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: 0.1 }}
            className="text-4xl md:text-5xl font-black text-slate-900 dark:text-white mb-6 tracking-tight flex items-center justify-center flex-wrap gap-4"
          >
            <span>لماذا منصة</span>
            <span className="text-6xl md:text-8xl pb-2 md:pb-4 leading-none inline-block" style={{ color: '#3544e1' }}>
              سند
            </span>
            <span>؟</span>
          </motion.h2>
          <motion.p
            initial={{ opacity: 0, y: 15 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: 0.2 }}
            className="text-slate-600 dark:text-slate-400 text-lg md:text-xl leading-relaxed max-w-2xl mx-auto"
          >
            نهتم بأدق التفاصيل لنضمن لك تجربة تعليمية وتطبيقية تفاعلية.
          </motion.p>
          <div className="w-20 h-1.5 bg-gradient-to-l from-blue-600 to-indigo-600 mx-auto mt-8 rounded-full shadow-sm" />
        </div>
      </div>

      {/* Full-bleed brand-yellow panel — the background colour starts HERE,
          below the title block above, not from the top of the section. */}
      <div
        className="relative left-1/2 -translate-x-1/2 w-screen overflow-hidden py-16 md:py-20"
        style={{ backgroundColor: '#3544e1' }}
      >
        <SectionDivider variant="tilt" position="top" />
        <SectionDivider variant="tilt" position="bottom" />

        {/* Ambient floating study-themed glyphs — books, pens, exams, ideas —
            in white, standing out against the solid brand-yellow backdrop. */}
        <FloatingGlyph icon={BookOpen} className="top-10 right-[8%]" size={56} duration={9} />
        <FloatingGlyph icon={PenTool} className="top-32 left-[6%]" size={44} duration={7} delay={0.6} xRange={-12} />
        <FloatingGlyph icon={Lightbulb} className="bottom-16 right-[14%]" size={48} duration={10} delay={1.2} rotateRange={-10} />
        <FloatingGlyph icon={FileCheck2} className="bottom-6 left-[10%]" size={46} duration={8.5} delay={0.3} xRange={-8} />
        <FloatingGlyph icon={Calculator} className="top-6 left-[30%]" size={38} duration={11} delay={1.8} />
        <FloatingGlyph icon={Ruler} className="bottom-24 right-[32%]" size={38} duration={9.5} delay={0.9} rotateRange={-14} />
        <FloatingGlyph icon={NotebookPen} className="top-1/2 right-[4%]" size={44} duration={7.5} delay={1.5} xRange={10} />
        <FloatingGlyph icon={GraduationCap} className="top-1/2 left-[3%]" size={48} duration={10.5} delay={0.4} xRange={-10} />

        <div className="container mx-auto px-6 md:px-12 lg:px-16 relative z-10">
          {/* Features Cards Grid List — `perspective` on the grid itself is
              what makes the cards' `rotateX`/`rotateY` hover tilt below
              render as genuine 3D depth instead of a flat skew. */}
          <div
            className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8"
            style={{ perspective: '1400px' }}
          >
            {features.map((feature, index) => {
              const Icon = iconMap[feature.iconName] || BookOpen;
              const theme = cardThemes[index % cardThemes.length];

              return (
                <motion.div
                  key={index}
                  initial={{ opacity: 0, y: 30, scale: 0.95 }}
                  whileInView={{ opacity: 1, y: 0, scale: 1 }}
                  viewport={{ once: true, margin: '-50px' }}
                  whileHover={{ y: -14, scale: 1.04, rotateX: -6, rotateY: 6 }}
                  transition={{ duration: 0.6, delay: index * 0.1, ease: [0.16, 1, 0.3, 1] }}
                  style={{ transformStyle: 'preserve-3d' }}
                  // Two shadow layers standing in for a 3D "card resting
                  // above a surface": a tight dark contact shadow close to
                  // the edge, plus a much softer, further-thrown one that
                  // grows on hover as the card lifts. A plain single shadow
                  // reads as flat no matter how large its blur is.
                  className="group relative flex flex-col items-center text-center pt-7 pb-8 px-7 rounded-[28px] bg-white border border-slate-100/80 shadow-[0_2px_4px_rgba(15,23,42,0.06),0_20px_40px_-16px_rgba(15,23,42,0.18)] hover:shadow-[0_4px_8px_rgba(15,23,42,0.08),0_36px_60px_-18px_rgba(37,99,235,0.35)] transition-shadow duration-500 overflow-hidden"
                >
                  {/* Top-edge highlight, like a beveled/embossed surface
                      catching light — reinforces the raised, physical feel
                      the shadow layers and tilt are going for. */}
                  <div className="absolute inset-x-0 top-0 h-1/2 bg-gradient-to-b from-white to-transparent pointer-events-none rounded-t-[28px]" />

                  {/* Top accent bar — the card's theme colour is now always
                      visible (not only revealed on hover), so the four
                      cards read as distinct at a glance instead of looking
                      identical until interacted with. */}
                  <div className={`absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r ${theme.gradient}`} />

                  {/* Ambient glow bloom appearing on hover */}
                  <div className={`absolute -inset-4 rounded-[40px] bg-gradient-to-br ${theme.gradient} opacity-0 group-hover:opacity-[0.1] blur-2xl transition-opacity duration-500 pointer-events-none`} />

                  {/* Icon badge — sculpted into a glossy 3D sphere: a
                      diagonal highlight near the top-left standing in for a
                      light source, a darker rim at the bottom-right, an
                      inner-shadow for the socket the sphere sits in, and its
                      own drop shadow beneath so it reads as raised off the
                      card rather than a flat filled circle. */}
                  <div className="relative mb-6 mt-2" style={{ transform: 'translateZ(40px)' }}>
                    <div className={`absolute -inset-3 rounded-full ${theme.lightGlow} blur-xl opacity-0 group-hover:opacity-100 transition-opacity duration-500`} />
                    <div className="absolute inset-x-2 -bottom-2 h-4 rounded-full bg-slate-900/20 blur-md" />
                    <div
                      className={`relative w-20 h-20 rounded-full bg-gradient-to-br ${theme.gradient} flex items-center justify-center shadow-[inset_0_2px_4px_rgba(255,255,255,0.5),inset_0_-6px_10px_rgba(0,0,0,0.25),0_10px_18px_-6px_rgba(15,23,42,0.35)] group-hover:scale-110 group-hover:-rotate-3 transition-transform duration-500`}
                    >
                      <div className="absolute inset-1.5 rounded-full bg-gradient-to-br from-white/40 via-white/0 to-transparent pointer-events-none" />
                      <Icon className="w-9 h-9 relative z-10 text-white drop-shadow-sm" strokeWidth={1.75} />
                    </div>
                  </div>

                  {/* Title */}
                  <h3 className="text-lg md:text-xl font-black text-slate-900 mb-2.5" style={{ transform: 'translateZ(20px)' }}>
                    {feature.title}
                  </h3>

                  {/* Description */}
                  <p className="text-slate-500 leading-relaxed text-sm" style={{ transform: 'translateZ(20px)' }}>
                    {feature.description}
                  </p>
                </motion.div>
              );
            })}
          </div>
        </div>
      </div>
    </section>
  );
}
