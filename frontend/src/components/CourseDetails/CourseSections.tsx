import { motion, AnimatePresence } from 'motion/react';
import { useNavigate } from 'react-router';
import { PlayCircle, FileText, Lock, ExternalLink, Download, Award, Presentation } from 'lucide-react';

export default function CourseSections({
  course,
  hasAccess,
  loggedIn,
  userRole,
  mergedGroups,
  totalGroups,
  totalLessons,
  expandedGroupId,
  setExpandedGroupId,
  isPlayingVideo,
  setIsPlayingVideo,
  handleDownload,
  handleOpenLink,
  courseExams,
  globalVideos
}: any) {
  const navigate = useNavigate();
  return (
    <>
        {/* 2. Lessons Curriculum accordion view */}
        <div className="max-w-4xl mx-auto w-full space-y-6">
          
          {mergedGroups.length > 0 && (
            <>
              {/* Lessons List Header */}
              <div className="flex items-center justify-between mb-2">
            <div className="flex items-center gap-2.5">
              <div className="p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                <Presentation className="w-5 h-5" />
              </div>
              <h2 className="text-lg md:text-xl font-black text-slate-900 dark:text-white">محتوى الكورس</h2>
            </div>
            <span className="text-xs font-bold text-slate-400 dark:text-slate-500 bg-slate-100/40 dark:bg-slate-900 px-3 py-1.5 rounded-full border border-slate-200/40">
              {totalGroups} وحدات • {totalLessons} عنصر تعليمي
            </span>
          </div>

          {/* Video Player Modal Space (Simulated secure video view for subscribed students) */}
          {isPlayingVideo && hasAccess && (
            <div className="bg-slate-950 rounded-[28px] overflow-hidden border-2 border-blue-500/60 p-6 flex flex-col items-center justify-center text-center relative aspect-[16/9] w-full select-none shadow-lg">
              <div className="text-white space-y-3 z-10 max-w-md">
                <PlayCircle className="w-16 h-16 text-blue-500 mx-auto animate-pulse" />
                <h4 className="font-extrabold text-base md:text-lg">جاري تشغيل مشغل الفيديو الحصري المؤمن لـ سَنَدْ</h4>
                <p className="text-xs text-slate-400 leading-relaxed">المشاهدة مؤمنة بالكامل لحماية الخصوصية ومستوى الاتصال التعليمي ومنع النسخ.</p>
              </div>
              <div className="absolute top-4 left-4 z-10">
                <button 
                  onClick={() => setIsPlayingVideo(null)}
                  className="bg-slate-900/80 hover:bg-slate-800 text-white text-xs px-3.5 py-2 rounded-xl border border-slate-800 cursor-pointer font-bold"
                >
                  إغلاق المشاهدة
                </button>
              </div>
            </div>
          )}

          {/* Groups Curriculum Structure */}
          <div className="space-y-6 font-sans">
            {mergedGroups.map((group: any, groupIdx: number) => {
              const isGroupExpanded = expandedGroupId === group.id;
              
              if (group.isHidden && userRole !== 'admin' && userRole !== 'instructor') {
                return null;
              }

              const groupNumber = String(groupIdx + 1).padStart(2, '0');

              return (
                <div 
                  key={group.id} 
                  className={`bg-white dark:bg-slate-900 rounded-[30px] overflow-hidden border transition-all duration-500 ${
                    isGroupExpanded 
                      ? 'border-blue-500/40 dark:border-blue-500/40 shadow-[0_25px_60px_rgba(59,130,246,0.12)] scale-[1.01] z-10' 
                      : 'border-slate-200/50 dark:border-slate-800/80 shadow-[0_4px_25px_rgba(0,0,0,0.01)] hover:border-slate-300 dark:hover:border-slate-700 hover:scale-[1.005]'
                  }`}
                >
                  {/* Group Trigger Header */}
                  <button
                    onClick={() => setExpandedGroupId(isGroupExpanded ? null : group.id)}
                    className={`w-full text-right p-6 md:p-7 flex items-center justify-between gap-4 cursor-pointer focus:outline-none transition-all duration-300 relative ${
                      isGroupExpanded 
                        ? 'bg-blue-50/20 dark:bg-blue-950/10' 
                        : 'hover:bg-slate-50/40 dark:hover:bg-slate-800/10'
                    }`}
                  >
                    {/* Exquisite colored right accent border for visual hierarchy */}
                    <div className="absolute right-0 top-0 bottom-0 w-1.5 bg-gradient-to-b from-blue-600 via-blue-500 to-indigo-600 rounded-l-md" />

                    <div className="flex items-center gap-5 pr-2">
                      {/* Premium Circle Index Badge */}
                      <div className={`w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 font-black text-sm transition-all duration-300 border ${
                        isGroupExpanded
                          ? 'bg-gradient-to-br from-blue-500 to-indigo-600 text-white border-transparent shadow-lg shadow-blue-500/20'
                          : 'bg-blue-50/50 dark:bg-blue-950/20 text-blue-600 dark:text-blue-400 border-blue-100/40 dark:border-blue-900/30'
                      }`}>
                        {groupNumber}
                      </div>

                      <div className="space-y-1.5">
                        <h3 className="text-base md:text-lg font-black text-slate-900 dark:text-white leading-tight flex flex-wrap items-center gap-2">
                          <span>{group.title}</span>
                          {group.isHidden && (
                            <span className="text-[10px] px-3 py-1 rounded-full bg-amber-100 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 font-extrabold shadow-sm">مخفي عن الطلاب</span>
                          )}
                        </h3>
                        <span className="text-xs font-semibold text-slate-400 dark:text-slate-500 block">
                          يحتوي على {group.items.filter((i: any) => !i.isHidden || userRole === 'admin' || userRole === 'instructor').length} محتويات تعليمية منظمة
                        </span>
                      </div>
                    </div>

                    <span className={`p-2.5 rounded-2xl border flex items-center justify-center shrink-0 transition-all duration-500 ${
                      isGroupExpanded 
                        ? 'rotate-180 text-white bg-gradient-to-r from-blue-600 to-indigo-600 border-transparent shadow-md shadow-blue-500/25 scale-110' 
                        : 'bg-slate-50 dark:bg-slate-950 border-slate-200 dark:border-slate-800 text-slate-400 dark:text-slate-600 hover:scale-105'
                    }`}>
                      <svg className="w-4.5 h-4.5 fill-none stroke-current stroke-[3px]" viewBox="0 0 24 24">
                        <polyline points="6 9 12 15 18 9" />
                      </svg>
                    </span>
                  </button>

                  {/* Group items wrapper */}
                  <AnimatePresence initial={false}>
                    {isGroupExpanded && (
                      <motion.div
                        variants={{
                          hidden: { height: 0, opacity: 0 },
                          visible: {
                            height: 'auto',
                            opacity: 1,
                            transition: {
                              height: { duration: 0.4, ease: [0.04, 0.62, 0.23, 0.98] },
                              opacity: { duration: 0.2, ease: "linear" },
                              staggerChildren: 0.08,
                              delayChildren: 0.05
                            }
                          },
                          exit: {
                            height: 0,
                            opacity: 0,
                            transition: {
                              height: { duration: 0.3, ease: "easeInOut" },
                              opacity: { duration: 0.15, ease: "linear" }
                            }
                          }
                        }}
                        initial="hidden"
                        animate="visible"
                        exit="exit"
                        className="overflow-hidden border-t border-slate-100 dark:border-slate-800"
                      >
                        {/* Timeline Container */}
                        <div className="p-6 md:p-8 bg-slate-50/30 dark:bg-slate-950/10 space-y-5 font-sans relative">
                          
                          {/* Premium Vertical Connector Timeline Line */}
                          <div className="absolute right-12 md:right-14 top-8 bottom-8 w-0.5 border-r-2 border-dashed border-slate-200 dark:border-slate-800 pointer-events-none hidden lg:block" />

                          {group.items.map((item: any) => {
                            if (item.isHidden && userRole !== 'admin' && userRole !== 'instructor') {
                              return null;
                            }

                            // Define specific premium colors/styling based on item type
                            // (visual only — no fabricated per-type label/description
                            // text anymore; see the render below, which shows only the
                            // item's REAL title and the REAL description the teacher
                            // entered).
                            let iconStyle = '';
                            let itemIcon = <FileText className="w-5.5 h-5.5" />;

                            const examData = item.type === 'exam' ? courseExams.find((ex: any) => ex.id === item.id || ex.title === item.title) : null;

                            switch (item.type) {
                              case 'video':
                                iconStyle = 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/15';
                                itemIcon = <PlayCircle className="w-5.5 h-5.5" />;
                                break;
                              case 'note':
                                iconStyle = 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/15';
                                itemIcon = <FileText className="w-5.5 h-5.5" />;
                                break;
                              case 'file':
                                iconStyle = 'bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/15';
                                itemIcon = <Download className="w-5.5 h-5.5" />;
                                break;
                              case 'exam':
                                iconStyle = 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/15';
                                itemIcon = <Award className="w-5.5 h-5.5" />;
                                break;
                              case 'link':
                                iconStyle = 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400 border-cyan-500/15';
                                itemIcon = <ExternalLink className="w-5.5 h-5.5" />;
                                break;
                            }

                            const isItemFreeVideo = item.type === 'video' && globalVideos.some((v: any) => v.courseId === course.id && v.title === item.title && v.isFree);
                            const itemHasAccess = hasAccess || (isItemFreeVideo && loggedIn);

                            return (
                              <motion.div 
                                key={item.id}
                                variants={{
                                  hidden: { opacity: 0, y: 15, scale: 0.98 },
                                  visible: { 
                                    opacity: 1, 
                                    y: 0, 
                                    scale: 1,
                                    transition: { type: "spring", stiffness: 280, damping: 24 }
                                  }
                                }}
                                className={`flex flex-col lg:flex-row items-start lg:items-center justify-between gap-6 p-6 rounded-[24px] border transition-all duration-300 relative ${
                                  itemHasAccess 
                                    ? 'bg-white dark:bg-slate-900/60 border-slate-200/60 dark:border-slate-800/80 hover:border-blue-500/35 hover:shadow-[0_12px_40px_rgba(59,130,246,0.04)]' 
                                    : 'bg-slate-100/40 dark:bg-slate-900/15 border-slate-100/70 dark:border-slate-800/60 opacity-80'
                                }`}
                              >
                                {/* Timeline Connected Node Dot */}
                                <div className={`absolute right-[-14px] top-1/2 -translate-y-1/2 w-4 h-4 rounded-full border-4 border-slate-100 dark:border-slate-950 hidden lg:block z-10 ${
                                  itemHasAccess ? 'bg-blue-500 animate-pulse' : 'bg-slate-300 dark:bg-slate-800'
                                }`} />

                                <div className="flex items-start sm:items-center gap-5 w-full lg:w-auto">
                                  {/* Premium Content Type Icon Wrapper */}
                                  <div className={`p-4 rounded-2xl border flex items-center justify-center shrink-0 shadow-xs ${iconStyle}`}>
                                    {itemIcon}
                                  </div>

                                  <div className="text-right space-y-2.5">
                                    <div className="flex flex-wrap items-center gap-2.5">
                                      <span className="text-base sm:text-lg lg:text-xl font-extrabold text-slate-800 dark:text-slate-100 leading-snug">
                                        {item.title}
                                      </span>
                                      {item.isHidden && (
                                        <span className="text-[10px] px-2.5 py-1 rounded-md bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20 font-black">مخفي</span>
                                      )}
                                    </div>
                                    {/* Only the real description the teacher entered for
                                        this item — no fabricated per-type boilerplate
                                        text and no fabricated metadata (duration/page
                                        count/file size/question count, ...). */}
                                    {item.description && (
                                      <span className="text-xs sm:text-sm font-semibold text-slate-400 dark:text-slate-500 block leading-relaxed">
                                        {item.description}
                                      </span>
                                    )}
                                  </div>
                                </div>

                                {/* Active premium functional controls vs locked indicator */}
                                <div className="w-full lg:w-auto flex justify-end shrink-0 pt-3 lg:pt-0 border-t lg:border-0 border-slate-100 dark:border-slate-800/60">
                                  {itemHasAccess ? (
                                    <>
                                      {item.type === 'video' && (
                                        <button
                                          onClick={() => navigate(`/play-video/${item.id}`, { state: { title: item.title, courseId: course.id } })}
                                          className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-black text-xs sm:text-sm rounded-xl shadow-md shadow-blue-500/10 hover:shadow-blue-500/20 transition-all duration-300 cursor-pointer border border-blue-500/10"
                                        >
                                          <PlayCircle className="w-4.5 h-4.5" />
                                          <span>مشاهدة الفيديو</span>
                                        </button>
                                      )}
                                      {(item.type === 'note' || item.type === 'file') && (
                                        <button
                                          onClick={() => handleDownload(item.id, item.title)}
                                          className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-black text-xs sm:text-sm rounded-xl shadow-md shadow-emerald-500/10 hover:shadow-emerald-500/20 transition-all duration-300 cursor-pointer border border-emerald-500/10"
                                        >
                                          <Download className="w-4.5 h-4.5" />
                                          <span>تحميل المرفق</span>
                                        </button>
                                      )}
                                      {item.type === 'exam' && (
                                        <button
                                          onClick={() => navigate(`/exam/${item.id}`, { state: { title: item.title, courseId: course.id, type: 'exam', duration: examData?.duration || 45, allowedAttempts: examData?.allowedAttempts } })}
                                          className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-black text-xs sm:text-sm rounded-xl shadow-md shadow-purple-500/10 hover:shadow-purple-500/20 transition-all duration-300 cursor-pointer border border-purple-500/10"
                                        >
                                          <Award className="w-4.5 h-4.5" />
                                          <span>دخول الاختبار</span>
                                        </button>
                                      )}
                                      {item.type === 'link' && (
                                        <button
                                          onClick={() => handleOpenLink(item.id)}
                                          className="w-full sm:w-auto text-center inline-flex items-center justify-center gap-2 px-6 py-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-800 font-black text-xs sm:text-sm rounded-xl shadow-xs transition-all duration-300 cursor-pointer"
                                        >
                                          <ExternalLink className="w-4.5 h-4.5" />
                                          <span>عرض الرابط</span>
                                        </button>
                                      )}
      </>
                                  ) : (
                                    // Elegant Lock Box for Guest / Unsubscribed users
                                    <div className="w-full sm:w-auto py-3 px-5 rounded-2xl bg-slate-100/60 dark:bg-slate-800/40 border border-slate-200/50 dark:border-slate-800 text-slate-400 dark:text-slate-500 flex items-center justify-center gap-2 select-none text-xs sm:text-sm font-bold shadow-inner">
                                      <Lock className="w-4 h-4 text-rose-500/80" />
                                      <span>مغلق للمشتركين 🔒</span>
                                    </div>
                                  )}
                                </div>
                              </motion.div>
                            );
                          })}
                        </div>
                      </motion.div>
                    )}
                  </AnimatePresence>
                </div>
              );
            })}
          </div>
          </>
      )}
      </div>
    </>
  );
}
