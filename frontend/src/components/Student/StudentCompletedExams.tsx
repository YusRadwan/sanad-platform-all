import StudentShell from './StudentShell';
import { useState, useEffect } from 'react';
import { motion } from 'motion/react';
import { Award, BookOpen, CheckCircle2, XCircle, Loader2, Hash } from 'lucide-react';

import { studentService } from '../../services/studentService';
import { formatDateTime } from '../../utils/dateUtils';
import { formatNumber } from '../../utils/formatMoney';

interface ExamAttempt {
  attempt_id: number | string;
  exam_id: number | string;
  exam_title: string;
  course_id: number | string;
  course_title: string;
  attempt_number: number;
  score: number | null;
  total_score: number | null;
  pass_score: number | null;
  started_at: string | null;
  submitted_at: string;
}

export default function StudentCompletedExams() {
  const [attempts, setAttempts] = useState<ExamAttempt[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    const fetchAttempts = async () => {
      try {
        setLoading(true);
        const data = await studentService.getExamAttempts();
        if (!cancelled) setAttempts(Array.isArray(data) ? data : []);
      } catch (err) {
        console.error('Failed to fetch exam attempts:', err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    };
    fetchAttempts();
    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <StudentShell title="الامتحانات" lead="كل امتحان دخلته ونتيجتك فيه">
      <div className="space-y-8">
      {/* Exams List */}
      <div className="space-y-4">
        {loading ? (
          <div className="py-20 flex flex-col items-center justify-center gap-4">
            <Loader2 className="w-9 h-9 text-indigo-600 animate-spin" />
            <p className="text-sm font-black text-slate-400">جاري تحميل سجل الامتحانات...</p>
          </div>
        ) : attempts.length === 0 ? (
          <div className="bg-white dark:bg-slate-900 rounded-3xl p-16 text-center border border-slate-100 dark:border-slate-800 shadow-3xs">
            <Award className="w-14 h-14 text-slate-300 dark:text-slate-700 mx-auto mb-4" />
            <p className="text-base font-black text-slate-900 dark:text-white mb-2">لا توجد محاولات امتحانات حالياً</p>
            <p className="text-xs font-bold text-slate-400 dark:text-slate-500 max-w-md mx-auto leading-relaxed">
              تأكد من فتح كورساتك والدخول للاختبارات الدورية وحلها لتظهر تفاصيل علاماتك والوقت المستغرق هنا فوراً.
            </p>
          </div>
        ) : (
          <div className="space-y-3.5">
            {attempts.map((attempt) => {
              const hasScore = attempt.score != null && attempt.total_score != null;
              const passed =
                hasScore && attempt.pass_score != null ? (attempt.score as number) >= attempt.pass_score : null;
              return (
                <motion.div
                  key={attempt.attempt_id}
                  initial={{ opacity: 0, y: 10 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ duration: 0.3 }}
                  className="bg-white dark:bg-slate-900 rounded-2xl p-5 border border-slate-100 dark:border-slate-800 shadow-3xs hover:shadow-md transition-all duration-300 flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                >
                  <div className="min-w-0">
                    <h3 className="text-sm font-black text-slate-900 dark:text-white truncate">{attempt.exam_title}</h3>
                    <p className="text-[11px] font-bold text-slate-400 dark:text-slate-500 mt-1 flex items-center gap-1.5">
                      <BookOpen className="w-3.5 h-3.5 shrink-0" />
                      <span className="truncate">{attempt.course_title}</span>
                    </p>
                    <div className="flex items-center gap-3 mt-2 text-[11px] font-bold text-slate-400 dark:text-slate-500">
                      <span className="flex items-center gap-1">
                        <Hash className="w-3 h-3" />
                        <span>المحاولة رقم {formatNumber(attempt.attempt_number)}</span>
                      </span>
                      <span>{formatDateTime(attempt.submitted_at)}</span>
                    </div>
                  </div>

                  <div className="flex items-center gap-3 shrink-0">
                    {hasScore ? (
                      <>
                        <div className="text-left">
                          <div className="text-xl font-black text-slate-900 dark:text-white font-mono">
                            {formatNumber(attempt.score as number)}
                            <span className="text-xs text-slate-400 font-bold"> / {formatNumber(attempt.total_score as number)}</span>
                          </div>
                          {attempt.pass_score != null && (
                            <div className="text-[10px] font-bold text-slate-400 dark:text-slate-500">
                              درجة النجاح: {formatNumber(attempt.pass_score)}%
                            </div>
                          )}
                        </div>
                        {passed !== null && (
                          <span
                            className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black border ${
                              passed
                                ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border-emerald-100/50 dark:border-emerald-900/30'
                                : 'bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 border-rose-100/50 dark:border-rose-900/30'
                            }`}
                          >
                            {passed ? <CheckCircle2 className="w-3 h-3" /> : <XCircle className="w-3 h-3" />}
                            <span>{passed ? 'ناجح' : 'راسب'}</span>
                          </span>
                        )}
                      </>
                    ) : (
                      <span className="text-xs font-bold text-slate-400 dark:text-slate-500">
                        جاري تصحيح المحاولة
                      </span>
                    )}
                  </div>
                </motion.div>
              );
            })}
          </div>
        )}
      </div>
      </div>
    </StudentShell>
  );
}
