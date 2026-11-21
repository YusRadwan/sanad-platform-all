import { motion } from 'motion/react';
import { BookOpen, GraduationCap, BarChart3, Home } from 'lucide-react';
import SectionHeading from './SectionHeading';

const features = [
  {
    icon: BookOpen,
    title: 'مناهج متكاملة',
    description: 'شرح وتدريبات تغطي كافة جوانب المنهج الدراسي.',
    tint: 'text-blue-600 dark:text-blue-300',
  },
  {
    icon: GraduationCap,
    title: 'نخبة المعلمين',
    description: 'دروس مقدمة من أفضل المعلمين ذوي الخبرة.',
    tint: 'text-teal-600 dark:text-teal-300',
  },
  {
    icon: BarChart3,
    title: 'تقييم مستمر',
    description: 'اختبارات وواجبات دورية لمتابعة مستواك الدراسي أولاً بأول.',
    tint: 'text-emerald-600 dark:text-emerald-300',
  },
  {
    icon: Home,
    title: 'توفير الوقت والجهد',
    description: 'دراسة مرنة من منزلك وبأعلى كفاءة ممكنة.',
    tint: 'text-amber-600 dark:text-amber-300',
  },
];

/**
 * "لماذا منصة سند؟" — the platform's four promises.
 *
 * REFINED: this used to be the third full-bleed brand-blue band in a row
 * (How-it-works → Teachers → Features → Courses → Join-us all sat on blue
 * panels with wavy edges), carrying eight infinitely-floating background
 * glyphs, glossy 3D icon spheres, a purple/fuchsia gradient card and a 3D
 * hover tilt. Five identical bands erased the page's rhythm, so this section
 * now sits on the plain page surface: the reading pace alternates
 * blue / neutral / blue, and the four promises read as a calm, scannable
 * list instead of a fourth decorated card row. Copy is unchanged.
 */
export default function FeaturesSection() {
  return (
    <section
      id="features"
      aria-labelledby="features-title"
      className="pt-24 pb-8 md:pb-12 transition-colors duration-300 relative"
    >
      <div className="container mx-auto px-6 md:px-12 lg:px-16">
        <SectionHeading
          id="features-title"
          before="لماذا منصة"
          highlight={
            <>
              سند
              <span className="text-4xl md:text-5xl text-slate-900 dark:text-white">؟</span>
            </>
          }
          lead="نهتم بأدق التفاصيل لنضمن لك تجربة تعليمية وتطبيقية تفاعلية."
        />

        <ul className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-px max-w-6xl mx-auto rounded-3xl overflow-hidden bg-slate-200/70 dark:bg-slate-800 ring-1 ring-slate-200/70 dark:ring-slate-800">
          {features.map((feature, index) => {
            const Icon = feature.icon;
            return (
              <motion.li
                key={feature.title}
                initial={{ opacity: 0, y: 16 }}
                whileInView={{ opacity: 1, y: 0 }}
                viewport={{ once: true, margin: '-50px' }}
                transition={{ duration: 0.5, delay: index * 0.08, ease: [0.16, 1, 0.3, 1] }}
                className="p-7 md:p-8 bg-white dark:bg-slate-900"
              >
                <h3 className="flex items-center gap-3 text-lg md:text-xl font-black text-slate-900 dark:text-white mb-3">
                  <Icon className={`w-6 h-6 shrink-0 ${feature.tint}`} strokeWidth={2} aria-hidden="true" />
                  {feature.title}
                </h3>
                <p className="text-base leading-relaxed text-slate-600 dark:text-slate-300">{feature.description}</p>
              </motion.li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
