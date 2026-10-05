import { useState, useEffect } from 'react';
import { useNavigate, useParams, useLocation } from 'react-router';
import { Award, AlertCircle, Clock, CheckCircle2, ChevronRight, XCircle, FileText, LayoutList, Eye, X, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import { courseService } from '../services/courseService';
import { logger } from '../services/logger';
import { useCenteredNotification } from '../context/CenteredNotificationContext';

interface Question {
  id: string;
  text: string;
  image?: string;
  choices: string[];
  correctAnswer: number;
}

interface ExamData {
  id?: string;
  title: string;
  type: 'exam' | string;
  duration_minutes?: number;
  dueDate?: string;
  dueTime?: string;
  total_score?: number;
  show_answers?: boolean;
  allowed_attempts?: number | null;
}


export default function ExamPage() {
  const { examId } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const { notify } = useCenteredNotification();
  
  const courseId = location.state?.courseId || '';

  const [examInfo, setExamInfo] = useState<ExamData | null>(null);
  const [questions, setQuestions] = useState<Question[]>([]);

  const [showExitWarning, setShowExitWarning] = useState(false);
  const [examStarted, setExamStarted] = useState(false);
  const [examFinished, setExamFinished] = useState(false);
  const [viewingAnswers, setViewingAnswers] = useState(false);
  const [currentQuestion, setCurrentQuestion] = useState(0);
  const [selectedAnswers, setSelectedAnswers] = useState<Map<string, number>>(new Map());
  const [timeLeft, setTimeLeft] = useState<number | null>(45 * 60); // Default 45 minutes
  const [selectedImage, setSelectedImage] = useState<string | null>(null);
  
  const [attempts, setAttempts] = useState(0);
  // GET /exams/{id} (EnrolledExamResource) returns `allowed_attempts`
  // (null = unlimited). There is still no endpoint reporting how many
  // attempts the student has already used in PAST sessions, so `attempts`
  // only counts finishes within this page load — the cap itself is real.
  const maxAttempts: number | null = examInfo?.allowed_attempts ?? null;

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [result, setResult] = useState<any>(null);

  useEffect(() => {
    const fetchExamData = async () => {
      if (!examId) return;
      try {
        setLoading(true);
        const [examData, questionsData] = await Promise.all([
          courseService.getExamDetails(examId),
          courseService.getExamQuestions(examId)
        ]);
        setExamInfo(examData);
        setQuestions(questionsData);
        if (examData.duration_minutes) {
          setTimeLeft(examData.duration_minutes * 60);
        } else {
          setTimeLeft(null);
        }
      } catch (err) {
        console.error('Failed to fetch exam:', err);
        setError('عذراً، فشل تحميل الاختبار.');
      } finally {
        setLoading(false);
      }
    };
    
    fetchExamData();
  }, [examId]);

  useEffect(() => {
    let timer: any;
    if (examStarted && !examFinished && timeLeft !== null && timeLeft > 0) {
      timer = setInterval(() => {
        setTimeLeft(prev => (prev !== null ? prev - 1 : null));
      }, 1000);
    } else if (timeLeft === 0 && !examFinished && examStarted) {
      handleFinishExam();
    }
    return () => clearInterval(timer);
  }, [examStarted, examFinished, timeLeft]);

  const handleFinishExam = async () => {
    if (!examInfo || !questions || questions.length === 0) {
      return;
    }

    if (selectedAnswers.size < questions.length && (timeLeft === null || timeLeft > 0)) {
      notify('يرجى الإجابة على جميع الأسئلة قبل إنهاء الاختبار.', 'error');
      return;
    }
    
    try {
      setExamFinished(true);
      const formattedAnswers = Array.from(selectedAnswers.entries()).map(([qId, choiceIdx]) => {
        const q = questions.find(item => item.id === qId);
        const choices = q?.choices || [];
        const selectedText = choices[choiceIdx] || '';
        return {
          questionId: qId,
          answerIndex: choiceIdx,
          selectedText: selectedText,
          answer: selectedText
        };
      });
      
      // ExamController::submit returns a FLAT payload —
      // { attempt_id, score, earned_points, total_points, passed } — not
      // nested under `submission`. Reading `response.submission` was always
      // undefined, so every student saw a 0 score with no pass/fail badge
      // regardless of their real (correctly graded) result.
      const response = await courseService.submitExam(examId!, formattedAnswers);
      setResult(response);
    } catch (err) {
      logger.error('Submission failed:', err as Error);
      notify('فشل إرسال الإجابات.', 'error');
    }
  };

  // GET /exams/{id}/results is the only endpoint allowed to reveal
  // `correct_answer` to a student — the questions the exam was TAKEN with
  // never carry it (by design, so a network inspector mid-attempt can't
  // read the key). `questions` therefore has every `correctAnswer` as
  // `undefined` until this runs, which is why the answers screen previously
  // showed every choice as neither selected-correct nor selected-incorrect:
  // `q.correctAnswer === cIdx` and `studentAnswer === q.correctAnswer` are
  // both comparisons against `undefined`, so neither can ever be true.
  const handleViewAnswers = async () => {
    try {
      const results = await courseService.getExamResults(examId!);
      const correctAnswers: Array<{ question_id: string; correct_answer: string }> = results?.correct_answers || [];
      if (correctAnswers.length > 0) {
        setQuestions((prev) =>
          prev.map((q) => {
            const entry = correctAnswers.find((c) => c.question_id === q.id);
            if (!entry) return q;
            // The key ships as the answer TEXT (matches how grading itself
            // compares strings), not a choice index — find which option it is.
            const idx = q.choices.findIndex((choice) => choice === entry.correct_answer);
            return idx === -1 ? q : { ...q, correctAnswer: idx };
          })
        );
      }
    } catch (err) {
      logger.error('Failed to fetch exam results for answer reveal:', err as Error);
    }
    setViewingAnswers(true);
  };

  const handleReturnToCourse = () => {
    if (courseId) {
      navigate(`/course/${courseId}`, { replace: true });
    } else {
      navigate('/', { replace: true });
    }
  };

  const formatTime = (seconds: number) => {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  const handleStartExam = () => {
    setExamStarted(true);
    setAttempts(prev => prev + 1);
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex items-center justify-center">
        <Loader2 className="w-10 h-10 text-blue-500 animate-spin" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col items-center justify-center p-6 text-center">
        <AlertCircle className="w-16 h-16 text-red-500 mb-4" />
        <p className="text-slate-800 dark:text-white text-xl font-bold mb-6">{error}</p>
        <button onClick={() => navigate(-1)} className="bg-blue-600 text-white px-8 py-3 rounded-2xl">العودة</button>
      </div>
    );
  }

  if (!questions || questions.length === 0) {
    return (
      <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex items-center justify-center">
        <Loader2 className="w-10 h-10 text-blue-500 animate-spin" />
      </div>
    );
  }

  const activeQuestions = questions;
  const examTitle = examInfo?.title || 'اختبار تفاعلي';
  const durationMinutes = examInfo?.duration_minutes || 45;
  const showAnswers = examInfo?.show_answers ?? true;

  if (viewingAnswers) {
    return (
      <div className="min-h-screen bg-slate-50 dark:bg-slate-950 font-sans select-none pb-20" dir="rtl">
        <div className="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 p-4 sticky top-0 z-40 shadow-sm">
          <div className="max-w-4xl mx-auto flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                <FileText className="w-5 h-5 text-purple-600 dark:text-purple-400" />
              </div>
              <div>
                <h1 className="font-bold text-slate-900 dark:text-white leading-tight">
                  إجابات: {examTitle}
                </h1>
              </div>
            </div>
            <button
              onClick={handleReturnToCourse}
              className="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-bold rounded-lg transition-colors cursor-pointer text-sm"
            >
              الخروج من الاختبار
            </button>
          </div>
        </div>

        <div className="max-w-4xl mx-auto px-4 py-8 space-y-8">
          {activeQuestions.map((q, idx) => {
            const studentAnswer = selectedAnswers.get(q.id);
            const isCorrect = studentAnswer === q.correctAnswer;
            
            return (
              <motion.div 
                key={idx}
                initial={{ opacity: 0, y: 10 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: idx * 0.05 }}
                className="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200 dark:border-slate-800"
              >
                <div className="flex items-start justify-between mb-6">
                  <h3 className="text-lg md:text-xl font-bold text-slate-900 dark:text-white leading-relaxed">
                    <span className="text-purple-600 dark:text-purple-400 ml-2">{idx + 1}.</span>
                    {q.text}
                  </h3>
                  {studentAnswer !== undefined ? (
                    <div className={`shrink-0 px-3 py-1 rounded-lg text-sm font-bold flex items-center gap-1.5 ${
                      isCorrect 
                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' 
                        : 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400'
                    }`}>
                      {isCorrect ? (
                        <><CheckCircle2 className="w-4 h-4" /> إجابة صحيحة</>
                      ) : (
                        <><XCircle className="w-4 h-4" /> إجابة خاطئة</>
                      )}
                    </div>
                  ) : (
                    <div className="shrink-0 px-3 py-1 rounded-lg text-sm font-bold flex items-center gap-1.5 bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                      <AlertCircle className="w-4 h-4" /> لم يتم الإجابة
                    </div>
                  )}
                </div>

                {q.image && (
                  <div className="mb-6 rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-700">
                    <img
                      src={q.image}
                      alt="Question"
                      className="w-full max-h-64 object-cover"
                      onError={(e) => {
                        const wrapper = e.currentTarget.parentElement;
                        if (wrapper) wrapper.style.display = 'none';
                      }}
                    />
                  </div>
                )}

                <div className="space-y-3">
                  {q.choices.map((choice: string, cIdx: number) => {
                    const isSelected = studentAnswer === cIdx;
                    const isActuallyCorrect = q.correctAnswer === cIdx;
                    
                    let choiceClasses = 'border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300';
                    let icon = null;

                    if (isActuallyCorrect) {
                      choiceClasses = 'border-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300';
                      icon = <CheckCircle2 className="w-5 h-5 text-emerald-500 shrink-0" />;
                    } else if (isSelected && !isActuallyCorrect) {
                      choiceClasses = 'border-rose-500 bg-rose-50 dark:bg-rose-900/20 text-rose-700 dark:text-rose-300';
                      icon = <XCircle className="w-5 h-5 text-rose-500 shrink-0" />;
                    }

                    return (
                      <div
                        key={cIdx}
                        className={`w-full text-right p-4 rounded-2xl border-2 flex items-center justify-between gap-3 ${choiceClasses}`}
                      >
                        <span className="font-semibold">{choice}</span>
                        {icon}
                      </div>
                    );
                  })}
                </div>
              </motion.div>
            );
          })}
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950 font-sans select-none" dir="rtl">
      {/* Exam Header */}
      <div className="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 p-4 sticky top-0 z-40 shadow-sm">
        <div className="max-w-4xl mx-auto flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
              <Award className="w-5 h-5 text-purple-600 dark:text-purple-400" />
            </div>
            <div>
              <h1 className="font-bold text-slate-900 dark:text-white leading-tight">
                {examTitle}
              </h1>
              {examStarted && !examFinished && (
                <p className="text-xs text-slate-500 font-medium hidden sm:block">الرجاء عدم الخروج من الصفحة حتى يتم الاعتماد</p>
              )}
            </div>
          </div>
        </div>
      </div>

      <div className="max-w-4xl mx-auto px-4 py-8">
        {!examStarted ? (
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="bg-white dark:bg-slate-900 rounded-3xl p-8 md:p-12 shadow-sm border border-slate-200 dark:border-slate-800 text-center"
          >
            <div className="w-24 h-24 bg-purple-100 dark:bg-purple-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
              <FileText className="w-12 h-12 text-purple-600 dark:text-purple-400" />
            </div>
            <h2 className="text-3xl font-black text-slate-900 dark:text-white mb-4">
              تفاصيل الاختبار
            </h2>
            <p className="text-slate-500 dark:text-slate-400 mb-8 max-w-lg mx-auto leading-relaxed">
              هذا الاختبار مصمم لتقييم فهمك لمحتوى الوحدة. يتكون من مجموعة من الأسئلة الاختيارية. يرجى التأكد من استقرار اتصالك بالإنترنت قبل البدء، حيث أن وقت الاختبار محدود وسيتم الاعتماد تلقائياً عند انتهاء الوقت.
            </p>
            
            <div className="grid grid-cols-2 sm:grid-cols-3 max-w-lg mx-auto gap-4 mb-10 text-right">
              <div className="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
                <div className="text-purple-500 mb-2"><Clock className="w-6 h-6" /></div>
                <div className="text-sm text-slate-500 dark:text-slate-400 font-bold mb-1">مدة الاختبار</div>
                <div className="text-lg font-black text-slate-900 dark:text-white">{durationMinutes} دقيقة</div>
              </div>
              <div className="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-800">
                <div className="text-purple-500 mb-2"><LayoutList className="w-6 h-6" /></div>
                <div className="text-sm text-slate-500 dark:text-slate-400 font-bold mb-1">عدد الأسئلة</div>
                <div className="text-lg font-black text-slate-900 dark:text-white">{activeQuestions.length} أسئلة</div>
              </div>
              {examInfo?.total_score != null && (
                <div className="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-2xl border border-slate-100 dark:border-slate-800 col-span-2 sm:col-span-1">
                  <div className="text-purple-500 mb-2"><Award className="w-6 h-6" /></div>
                  <div className="text-sm text-slate-500 dark:text-slate-400 font-bold mb-1">الدرجة الكلية</div>
                  <div className="text-lg font-black text-slate-900 dark:text-white">{examInfo.total_score} درجة</div>
                </div>
              )}
            </div>

            {maxAttempts !== null && (
              <div className="mb-6 text-center">
                <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-bold ${attempts >= maxAttempts ? 'bg-red-50 text-red-600 dark:bg-red-900/30 dark:text-red-400' : 'bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400'}`}>
                  المحاولات المتبقية: {maxAttempts - attempts} من {maxAttempts}
                </span>
              </div>
            )}

            <button
              onClick={handleStartExam}
              className={`px-10 py-4 rounded-2xl font-black text-white ${maxAttempts !== null && attempts >= maxAttempts ? 'bg-slate-400 cursor-not-allowed' : 'bg-purple-600 hover:bg-purple-700 shadow-xl shadow-purple-500/20 hover:shadow-purple-500/30 hover:scale-105 active:scale-95 cursor-pointer'} transition-all text-lg flex items-center gap-2 mx-auto`}
              disabled={maxAttempts !== null && attempts >= maxAttempts}
            >
              <Award className="w-5 h-5" />
              {maxAttempts !== null && attempts >= maxAttempts ? 'استنفذت المحاولات' : 'ابدأ الاختبار الآن'}
            </button>
          </motion.div>
        ) : !examFinished ? (
          <motion.div 
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            className="bg-white dark:bg-slate-900 rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200 dark:border-slate-800"
          >
            {/* Highly Visible Countdown Timer Header */}
            {timeLeft !== null && (
              <div className="mb-8 flex items-center justify-center">
                <div className={`inline-flex items-center gap-3 px-6 py-3 rounded-2xl font-mono font-black text-2xl shadow-sm border ${
                  timeLeft < 300 
                    ? 'bg-rose-50 text-rose-600 border-rose-200 dark:bg-rose-950/40 dark:text-rose-400 dark:border-rose-900/50 animate-pulse' 
                    : 'bg-slate-100 text-slate-800 border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700'
                }`}>
                  <Clock className={`w-7 h-7 ${timeLeft < 300 ? 'text-rose-500' : 'text-slate-500 dark:text-slate-400'}`} />
                  <span className="tracking-widest">{formatTime(timeLeft)}</span>
                </div>
              </div>
            )}

            {/* Progress */}
            <div className="mb-8">
              <div className="flex justify-between text-sm font-bold text-slate-500 mb-2">
                <span>السؤال {currentQuestion + 1} من {activeQuestions.length}</span>
                <span>{Math.round((selectedAnswers.size / activeQuestions.length) * 100)}% مكتمل</span>
              </div>
              <div className="w-full h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                <div 
                  className="h-full bg-purple-500 transition-all duration-300"
                  style={{ width: `${(selectedAnswers.size / activeQuestions.length) * 100}%` }}
                />
              </div>
            </div>

            {/* Question */}
            <div className="mb-10">
              <h2 className="text-xl md:text-2xl font-bold text-slate-900 dark:text-white mb-6 leading-relaxed">
                {activeQuestions[currentQuestion].text}
              </h2>
              
              {activeQuestions[currentQuestion].image && (
                <div 
                  className="mb-8 rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-700 cursor-pointer hover:opacity-90 transition-opacity relative group"
                  onClick={() => setSelectedImage(activeQuestions[currentQuestion].image || null)}
                >
                  <img
                    src={activeQuestions[currentQuestion].image}
                    alt="Question"
                    className="w-full max-h-72 object-cover"
                    onError={(e) => {
                      const wrapper = e.currentTarget.parentElement;
                      if (wrapper) wrapper.style.display = 'none';
                    }}
                  />
                  <div className="absolute inset-0 bg-slate-900/0 group-hover:bg-slate-900/20 transition-colors flex items-center justify-center">
                    <div className="bg-slate-900/80 text-white px-4 py-2 rounded-xl text-sm font-bold opacity-0 group-hover:opacity-100 transition-opacity backdrop-blur-sm shadow-lg flex items-center gap-2">
                      <Eye className="w-4 h-4" />
                      اضغط لتكبير الصورة
                    </div>
                  </div>
                </div>
              )}
              
              <div className="space-y-3">
                {activeQuestions[currentQuestion].choices.map((opt: string, idx: number) => (
                  <button
                    key={idx}
                    onClick={() => {
                      const newMap = new Map(selectedAnswers);
                      newMap.set(activeQuestions[currentQuestion].id, idx);
                      setSelectedAnswers(newMap);
                    }}
                    className={`w-full text-right p-4 rounded-2xl border-2 transition-all cursor-pointer ${
                      selectedAnswers.get(activeQuestions[currentQuestion].id) === idx
                        ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300'
                        : 'border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50 text-slate-700 dark:text-slate-300 hover:border-purple-200 dark:hover:border-purple-800/50'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <div className={`w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 ${
                        selectedAnswers.get(activeQuestions[currentQuestion].id) === idx
                          ? 'border-purple-500'
                          : 'border-slate-300 dark:border-slate-600'
                      }`}>
                        {selectedAnswers.get(activeQuestions[currentQuestion].id) === idx && (
                          <div className="w-2.5 h-2.5 rounded-full bg-purple-500" />
                        )}
                      </div>
                      <span className="font-semibold">{opt}</span>
                    </div>
                  </button>
                ))}
              </div>
            </div>

            {/* Navigation */}
            <div className="flex justify-between items-center pt-6 border-t border-slate-100 dark:border-slate-800">
              <button
                onClick={() => setCurrentQuestion(Math.max(0, currentQuestion - 1))}
                disabled={currentQuestion === 0}
                className="px-6 py-3 rounded-xl font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-50 transition-all flex items-center gap-2 cursor-pointer"
              >
                <ChevronRight className="w-4 h-4" />
                السابق
              </button>

              {currentQuestion === activeQuestions.length - 1 ? (
                <button
                  onClick={handleFinishExam}
                  className="px-8 py-3 rounded-xl font-bold text-white bg-emerald-500 hover:bg-emerald-600 shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-2 cursor-pointer"
                >
                  <CheckCircle2 className="w-5 h-5" />
                  إنهاء الاختبار
                </button>
              ) : (
                <button
                  onClick={() => setCurrentQuestion(Math.min(activeQuestions.length - 1, currentQuestion + 1))}
                  className="px-8 py-3 rounded-xl font-bold text-white bg-purple-600 hover:bg-purple-700 shadow-lg shadow-purple-500/20 transition-all flex items-center gap-2 cursor-pointer"
                >
                  التالي
                  <ChevronRight className="w-4 h-4 rotate-180" />
                </button>
              )}
            </div>
          </motion.div>
        ) : (
          <motion.div 
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            className="bg-white dark:bg-slate-900 rounded-3xl p-10 shadow-sm border border-emerald-200 dark:border-emerald-900/30 text-center"
          >
            <div className="w-20 h-20 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
              <CheckCircle2 className="w-10 h-10 text-emerald-600 dark:text-emerald-400" />
            </div>
            <h2 className="text-3xl font-black text-slate-900 dark:text-white mb-3">تم اعتماد الحل بنجاح</h2>
            <p className="text-slate-500 dark:text-slate-400 mb-6 max-w-md mx-auto">
              شكراً لك. تم حفظ إجاباتك بنجاح. يمكنك الآن الخروج من صفحة الاختبار والعودة إلى محتوى الكورس.
            </p>

            <div className="mb-10 p-6 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800 max-w-sm mx-auto">
              <div className="text-sm text-slate-500 dark:text-slate-400 font-bold mb-2">نتيجتك في الاختبار</div>
              <div className="text-4xl font-black text-slate-900 dark:text-white flex items-baseline justify-center gap-2">
                <span className="text-emerald-500">
                  {result?.score ?? 0}
                </span>
                <span className="text-lg text-slate-400">/</span>
                {/* `result.score` (above) is ALREADY normalised to the exam's
                    configured `total_score` scale by ScoreExamAttempt — e.g.
                    a 3-question exam worth 100 gives ~33.33 per correct
                    answer, not "out of 3". `result.total_points` is the
                    UNNORMALISED sum of each question's raw `points` (here,
                    literally 3) — showing it as the denominator produced the
                    nonsensical "33.33 / 3". `examInfo.total_score` is the
                    right unit for this numerator and must come first. */}
                <span className="text-2xl text-slate-600 dark:text-slate-300">{examInfo?.total_score ?? result?.total_points ?? activeQuestions.length}</span>
              </div>
              {result?.passed !== undefined && result?.passed !== null && (
                <div className={`mt-4 font-bold ${result.passed ? 'text-green-500' : 'text-red-500'}`}>
                  {result.passed ? 'ناجح' : 'راسب'}
                </div>
              )}
            </div>
            
            <div className="flex flex-col sm:flex-row items-center justify-center gap-4 max-w-lg mx-auto">
              {showAnswers && (
                <button
                  onClick={handleViewAnswers}
                  className="w-full sm:w-auto px-8 py-4 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-2xl transition-all shadow-lg shadow-purple-500/20 cursor-pointer flex items-center justify-center gap-2"
                >
                  <Eye className="w-5 h-5" />
                  عرض الإجابات
                </button>
              )}
              <button
                onClick={handleReturnToCourse}
                className={`w-full sm:w-auto px-8 py-4 bg-slate-900 dark:bg-slate-800 text-white font-bold rounded-2xl hover:scale-105 transition-all shadow-xl cursor-pointer ${!showAnswers ? 'sm:flex-1' : ''}`}
              >
                الخروج من الاختبار
              </button>
            </div>
          </motion.div>
        )}
      </div>

      {/* Image Modal */}
      <AnimatePresence>
        {selectedImage && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 backdrop-blur-xl p-4">
            <motion.div 
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.95 }}
              className="relative max-w-5xl w-full"
            >
              <button 
                onClick={() => setSelectedImage(null)}
                className="absolute -top-14 right-0 bg-white/10 hover:bg-white/20 text-white rounded-full p-2.5 backdrop-blur-md transition-all cursor-pointer flex items-center gap-2 group border border-white/10"
              >
                <X className="w-6 h-6 group-hover:rotate-90 transition-transform" />
                <span className="font-bold text-sm pl-2 pr-1">إغلاق الصورة</span>
              </button>
              <img 
                src={selectedImage} 
                alt="Enlarged question content" 
                className="w-full max-h-[85vh] object-contain rounded-2xl shadow-2xl border border-white/5" 
              />
            </motion.div>
          </div>
        )}
      </AnimatePresence>

      {/* Exit Warning Modal */}
      <AnimatePresence>
        {showExitWarning && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
            <motion.div 
              initial={{ opacity: 0, scale: 0.95 }}
              animate={{ opacity: 1, scale: 1 }}
              exit={{ opacity: 0, scale: 0.95 }}
              className="bg-white dark:bg-slate-900 p-8 rounded-3xl max-w-sm w-full shadow-2xl border border-rose-100 dark:border-rose-900/30"
            >
              <div className="w-16 h-16 bg-rose-100 dark:bg-rose-900/30 rounded-full flex items-center justify-center mx-auto mb-5">
                <AlertCircle className="w-8 h-8 text-rose-600 dark:text-rose-400" />
              </div>
              <h3 className="text-xl font-bold text-center text-slate-900 dark:text-white mb-3">
                تحذير الخروج
              </h3>
              <p className="text-center text-slate-600 dark:text-slate-400 mb-8 font-medium">
                لا يمكنك الخروج من صفحة الاختبار قبل الانتهاء منه واعتماد الإجابات.
              </p>
              <button
                onClick={() => setShowExitWarning(false)}
                className="w-full py-3.5 bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold rounded-xl cursor-pointer"
              >
                متابعة الاختبار
              </button>
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
}
