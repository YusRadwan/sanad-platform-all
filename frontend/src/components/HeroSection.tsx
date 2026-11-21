import { motion } from 'motion/react';
import { useNavigate } from 'react-router';
import PlatformLogo from './PlatformLogo';
import { HeroSectionProps } from '../types/index';
import { authService } from '../services/authService';




export default function HeroSection(_props: HeroSectionProps) {
  const navigate = useNavigate();
  const isLoggedIn = authService.getIsLoggedIn();

  return (
    <section className="relative isolate -mt-10 md:-mt-14 pt-10 sm:pt-0 pb-14 sm:pb-6 lg:pb-0 overflow-hidden">
      {/* Decorative line-art background — behind every other layer here, at
          low opacity so it reads as texture rather than competing with the
          headline/CTA. The artwork's swooping lines are concentrated in the
          top half of its canvas, so `object-top` keeps that visible instead
          of `object-cover`'s default centre crop, which would cut the
          pattern off entirely on a wide, short section like this one. */}
      <img
        src="/branding/linehero.svg"
        alt=""
        aria-hidden="true"
        className="absolute inset-0 w-full h-full object-cover object-top opacity-25 dark:opacity-30 -z-30 pointer-events-none select-none"
      />

      {/* One soft brand glow behind the headline. The section used to stack
          two static 500-600px blurs, a "tech" grid overlay and three
          infinitely drifting blurred orbs on top of the line-art — five
          full-screen blur layers that cost real frame time on the cheap
          Android phones most students use, for texture the line-art
          already provides. */}
      <div aria-hidden="true" className="absolute top-0 right-1/4 w-[500px] h-[500px] bg-blue-600/10 dark:bg-blue-600/15 rounded-full blur-[120px] -z-10 pointer-events-none" />

      <div className="container mx-auto px-6 md:px-20 lg:px-28 relative z-10">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-2 items-center text-right">
          
          {/* Right Column: Hero Typography & CTA (RTL Column is first) */}
          <div className="lg:col-span-7 flex flex-col items-center lg:items-start text-center lg:text-right pt-6 lg:pt-0">
            
            {/* Aesthetic Badge — a minimal editorial "kicker" instead of a
                pill/icon badge: a short gradient accent bar beside plain
                tracked text, no background, no border, no icon. */}
            <motion.div
              initial={{ opacity: 0, y: 15 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.5, ease: 'easeOut' }}
              className="mb-5"
            >
              <span className="font-tajawal text-base md:text-lg font-bold text-slate-600 dark:text-slate-300">
                بيئة تفاعلية صُممت لأجلك
              </span>
            </motion.div>

            {/* Plain static headline — no motion, no gradient/glow/shadow. */}
            <h1 className="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-black text-slate-900 dark:text-white leading-normal tracking-tight mb-4 font-sans flex items-baseline justify-center lg:justify-start gap-3 flex-nowrap">
              <span className="text-3xl sm:text-4xl md:text-5xl lg:text-6xl">منصة</span>
              <span className="text-6xl sm:text-7xl md:text-8xl lg:text-9xl font-black text-sanad">سند</span>
            </h1>

            {/* Subtitle with the exact requested description */}
            <motion.p
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.5, delay: 0.2, ease: 'easeOut' }}
              className="text-2xl md:text-3xl lg:text-4xl font-bold text-slate-700 dark:text-slate-200 mb-2 leading-relaxed"
            >
              سندك الحقيقي نحو التفوق والنجاح
            </motion.p>

            {/* Primary CTA — sends the visitor straight to account creation.
                Hidden once a user is logged in; a signed-in visitor has
                already started their journey, so this call-to-action no
                longer applies to them. */}
            {!isLoggedIn && (
              <>
                <motion.p
                  initial={{ opacity: 0, y: 15 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.5, delay: 0.25, ease: 'easeOut' }}
                  className="text-lg md:text-xl font-extrabold text-slate-600 dark:text-slate-300 mt-5"
                >
                  جاهز تبدأ رحلة التفوق؟
                </motion.p>

                <div className="flex items-center flex-wrap justify-center lg:justify-start gap-4 mt-3">
                <motion.button
                  type="button"
                  onClick={() => navigate('/signup')}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.5, delay: 0.3, ease: 'easeOut' }}
                  className="btn-sanad inline-flex items-center justify-center min-h-12 px-9 py-4 rounded-full font-black text-base md:text-lg cursor-pointer"
                >
                  <span>ابدأ رحلتك التعليمية</span>
                </motion.button>

                <motion.button
                  type="button"
                  onClick={() => navigate('/login')}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.5, delay: 0.35, ease: 'easeOut' }}
                  className="btn-sanad-outline inline-flex items-center justify-center min-h-12 px-9 py-4 rounded-full bg-white dark:bg-slate-900 font-black text-base md:text-lg border-2 cursor-pointer"
                >
                  عندك حساب؟ ادخل
                </motion.button>
                </div>
              </>
            )}

          </div>

          {/* Left Column: Illuminated Platform Logo (with floating, glow & interactive lighting effects) */}
          <div aria-hidden="true" className="lg:col-span-5 hidden sm:flex justify-center lg:justify-start items-center relative min-h-[220px] lg:min-h-[330px] lg:translate-x-10">
            {/* Glowing floating wrapper */}
            <motion.div
              animate={{ 
                y: [0, -12, 0],
                rotate: [0, 1.5, -1.5, 0]
              }}
              transition={{ 
                duration: 6, 
                repeat: Infinity, 
                ease: "easeInOut" 
              }}
              className="relative z-10 flex flex-col items-center justify-center p-2 select-none"
            >
              <div className="relative p-4">
                <PlatformLogo className="w-72 h-72 md:w-132 md:h-132 lg:w-160 lg:h-160 max-w-none object-contain" />
              </div>
            </motion.div>
          </div>

        </div>


      </div>
    </section>
  );
}
