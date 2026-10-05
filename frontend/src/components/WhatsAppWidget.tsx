import { useState, useEffect } from 'react';
import { X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { settingsApiService } from '../services/settingsApiService';
import { buildWhatsAppLink } from '../utils/whatsappLink';
import WhatsAppIcon from './icons/WhatsAppIcon';

export default function WhatsAppWidget() {
  const [isOpen, setIsOpen] = useState(false);
  const [whatsappNumbers, setWhatsappNumbers] = useState<string[]>([]);
  const [isEnabled, setIsEnabled] = useState(false);

  useEffect(() => {
    const fetchSettings = async () => {
      try {
        const { supportNumbers } = await settingsApiService.getSettings();
        if (supportNumbers && Array.isArray(supportNumbers)) {
          // Canonical fields from the settings API are `type`/`value`/
          // `values`/`enabled` (see types/teacher.ts's SupportContact and
          // PlatformSettings.tsx's own mapping) — `label`/`number`/`is_active`
          // were never actually sent by the backend, which silently kept this
          // widget disabled on every real deployment until now.
          const whatsappContact = supportNumbers.find((c: any) => c.type && c.type.includes('واتساب'));
          if (whatsappContact) {
            const rawNumbers = Array.isArray(whatsappContact.values) && whatsappContact.values.length > 0
              ? whatsappContact.values as string[]
              : String(whatsappContact.value ?? '').split(',').map((n: string) => n.trim()).filter(Boolean);
            setWhatsappNumbers(rawNumbers);
            setIsEnabled(whatsappContact.enabled !== false && rawNumbers.length > 0);
          }
        }
      } catch (error) {
        console.error('Failed to load WhatsApp settings:', error);
      }
    };
    fetchSettings();
  }, []);

  if (!isEnabled || whatsappNumbers.length === 0) return null;

  const handleOpenWhatsApp = (number: string) => {
    window.open(buildWhatsAppLink(number), '_blank', 'noopener,noreferrer');
  };

  return (
    <div className="fixed bottom-6 left-6 z-[9999] flex flex-col items-start gap-4 font-cairo">
      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0, y: 20, scale: 0.9 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.9 }}
            className="bg-white dark:bg-slate-900 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-800 p-4 w-64 origin-bottom-left"
          >
            <div className="flex items-center justify-between mb-4 pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="font-bold text-slate-800 dark:text-white flex items-center gap-2">
                <WhatsAppIcon className="w-5 h-5 text-[#25D366]" />
                دعم سند
              </h3>
              <button
                onClick={() => setIsOpen(false)}
                aria-label="إغلاق"
                className="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>
            <div className="space-y-2">
              {whatsappNumbers.map((num, idx) => (
                <button
                  key={idx}
                  onClick={() => {
                    handleOpenWhatsApp(num);
                    setIsOpen(false);
                  }}
                  className="w-full flex items-center justify-between p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 border border-transparent hover:border-slate-200 dark:hover:border-slate-700 transition-all group"
                >
                  <span className="font-bold text-slate-700 dark:text-slate-300" dir="ltr">{num}</span>
                  <div className="w-8 h-8 rounded-full bg-[#25D366]/10 dark:bg-[#25D366]/20 flex items-center justify-center text-[#25D366] group-hover:scale-110 transition-transform">
                    <WhatsAppIcon className="w-4 h-4" />
                  </div>
                </button>
              ))}
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <button
        onClick={() => setIsOpen(!isOpen)}
        className="w-[60px] h-[60px] bg-[#25D366] hover:bg-[#20bd5a] text-white rounded-full shadow-lg shadow-[#25D366]/30 flex items-center justify-center transition-all hover:scale-110 active:scale-95"
        aria-label="تواصل عبر واتساب"
      >
        <WhatsAppIcon className="w-8 h-8" />
      </button>
    </div>
  );
}
