import { useState, useEffect } from 'react';
import { Bell, Check, Clock, ExternalLink, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'motion/react';
import { notificationService, Notification } from '../services/notificationService';

export default function NotificationDropdown() {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [isOpen, setIsOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const unreadCount = notifications.filter(n => !n.is_read).length;

  const fetchNotifications = async () => {
    try {
      setLoading(true);
      const data = await notificationService.getNotifications();
      setNotifications(data);
    } catch (err) {
      if ((err as any).response?.status !== 401 && (err as any).response?.status !== 400) console.error('Failed to fetch notifications:', err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchNotifications();
    // Poll every 2 minutes for new notifications
    const interval = setInterval(fetchNotifications, 120000);
    return () => clearInterval(interval);
  }, []);

  const handleMarkRead = async (id: string) => {
    try {
      await notificationService.markAsRead(id);
      setNotifications(notifications.map(n => n.id === id ? { ...n, is_read: true } : n));
    } catch (err) {
      console.error('Failed to mark notification as read:', err);
    }
  };

  const formatDate = (dateString: string) => {
    const date = new Date(dateString);
    return date.toLocaleDateString('ar-EG-u-nu-latn', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  return (
    <div className="relative">
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="relative p-2.5 rounded-xl border border-slate-200/60 dark:border-slate-800/60 hover:bg-slate-100 dark:hover:bg-slate-900 text-slate-700 dark:text-slate-300 transition-all cursor-pointer"
        aria-label="Notifications"
      >
        <Bell className="w-5 h-5" />
        {unreadCount > 0 && (
          <span className="absolute top-2 right-2 w-4 h-4 bg-rose-600 text-white text-[10px] font-black flex items-center justify-center rounded-full border-2 border-white dark:border-slate-950">
            {unreadCount}
          </span>
        )}
      </button>

      <AnimatePresence>
        {isOpen && (
          <>
            <div 
              className="fixed inset-0 z-30" 
              onClick={() => setIsOpen(false)} 
            />
            <motion.div
              initial={{ opacity: 0, y: 10, scale: 0.95 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 10, scale: 0.95 }}
              className="absolute left-0 mt-2.5 w-80 md:w-96 bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-3xl shadow-2xl z-40 overflow-hidden text-right"
              dir="rtl"
            >
              <div className="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Bell className="w-4 h-4 text-blue-600" />
                  <h3 className="font-black text-sm text-slate-900 dark:text-white">التنبيهات</h3>
                </div>
                {unreadCount > 0 && (
                  <span className="text-[10px] font-black px-2 py-0.5 bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 rounded-lg">
                    {unreadCount} جديد
                  </span>
                )}
              </div>

              <div className="max-h-[400px] overflow-y-auto">
                {loading && notifications.length === 0 ? (
                  <div className="py-12 flex flex-col items-center justify-center gap-3">
                    <Loader2 className="w-6 h-6 text-blue-600 animate-spin" />
                    <p className="text-[10px] font-bold text-slate-400">جاري تحميل التنبيهات...</p>
                  </div>
                ) : notifications.length > 0 ? (
                  <div className="divide-y divide-slate-50 dark:divide-slate-900">
                    {notifications.map((notification) => (
                      <div 
                        key={notification.id}
                        className={`p-4 hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors group relative ${!notification.is_read ? 'bg-blue-50/30 dark:bg-blue-950/10' : ''}`}
                      >
                        <div className="flex gap-3">
                          <div className={`mt-1 w-2 h-2 rounded-full shrink-0 ${!notification.is_read ? 'bg-blue-600' : 'bg-transparent'}`} />
                          <div className="space-y-1 flex-1">
                            <h4 className="font-black text-xs text-slate-900 dark:text-white leading-tight">
                              {notification.title}
                            </h4>
                            <p className="text-[11px] font-bold text-slate-500 dark:text-slate-400 leading-relaxed">
                              {notification.message}
                            </p>
                            <div className="flex items-center justify-between pt-2">
                              <span className="text-[9px] font-bold text-slate-400 flex items-center gap-1">
                                <Clock className="w-3 h-3" />
                                {formatDate(notification.created_at)}
                              </span>
                              
                              <div className="flex items-center gap-2">
                                {!notification.is_read && (
                                  <button
                                    onClick={() => handleMarkRead(notification.id)}
                                    className="p-1 rounded-md hover:bg-white dark:hover:bg-slate-800 text-blue-600 transition-all cursor-pointer"
                                    title="تحديد كمقروء"
                                  >
                                    <Check className="w-3.5 h-3.5" />
                                  </button>
                                )}
                                {notification.link && (
                                  <a
                                    href={notification.link}
                                    className="p-1 rounded-md hover:bg-white dark:hover:bg-slate-800 text-slate-400 hover:text-blue-600 transition-all"
                                  >
                                    <ExternalLink className="w-3.5 h-3.5" />
                                  </a>
                                )}
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="py-16 text-center space-y-3">
                    <div className="w-12 h-12 bg-slate-50 dark:bg-slate-900 rounded-full flex items-center justify-center mx-auto">
                      <Bell className="w-6 h-6 text-slate-300" />
                    </div>
                    <p className="text-[11px] font-bold text-slate-400">لا توجد تنبيهات حالياً</p>
                  </div>
                )}
              </div>

              {notifications.length > 0 && (
                <div className="p-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
                  <button 
                    onClick={() => setIsOpen(false)}
                    className="w-full py-2 text-[10px] font-black text-slate-500 hover:text-blue-600 transition-colors cursor-pointer"
                  >
                    إغلاق النافذة
                  </button>
                </div>
              )}
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
}
