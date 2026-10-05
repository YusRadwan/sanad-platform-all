import { Phone, Mail, X, PhoneCall, MessageCircle, Clock } from 'lucide-react';
import { useState, useEffect } from 'react';
import { AnimatePresence, motion } from 'motion/react';
import PlatformLogo from './PlatformLogo';
import { settingsApiService } from '../services/settingsApiService';
import { safeUrl } from '../utils/urlValidation';
import { getSocialPlatformIcon } from '../utils/socialPlatformIcon';

export default function Footer() {
  const [showPrivacy, setShowPrivacy] = useState(false);
  const [showTerms, setShowTerms] = useState(false);
  // Raw GET /settings envelope: `{ settings: {...}, socialLinks: [...],
  // supportNumbers: [...] }` — see PublicSettingsMap.php. The flat
  // `settings.social_links`/`settings.support_contacts` reads this component
  // used to do never existed on that shape (the arrays are TOP-LEVEL
  // `socialLinks`/`supportNumbers`, camelCase, siblings of `settings`, not
  // nested snake_case fields inside it), so this section silently rendered
  // nothing on every page load regardless of what was saved in
  // PlatformSettings.tsx. WhatsAppWidget.tsx already reads the same envelope
  // correctly; this mirrors that.
  const [settingsData, setSettingsData] = useState<any>(null);

  useEffect(() => {
    settingsApiService.getSettings()
      .then((data) => setSettingsData(data || {}))
      .catch(() => setSettingsData({}));
  }, []);

  const settings = settingsData?.settings || {};

  const privacyText = settings.privacy_policy || '';
  const termsText = settings.terms_conditions || '';

  // handleSave() in PlatformSettings.tsx persists these four flags as the
  // literal strings 'true'/'false' (see its payload comment), never a real
  // boolean — comparing to the boolean `false` never matched, so an admin
  // turning a section off here had no effect on the footer.
  const socialSectionEnabled = settings.social_section_enabled !== 'false';
  const privacySectionEnabled = settings.privacy_section_enabled !== 'false' && privacyText !== '';
  const termsSectionEnabled = settings.terms_section_enabled !== 'false' && termsText !== '';
  const supportSectionEnabled = settings.support_section_enabled !== 'false';

  const socialLinks: any[] = Array.isArray(settingsData?.socialLinks) ? settingsData.socialLinks : [];
  const activeSocialLinks = socialLinks.filter(link => link.enabled && link.url);

  const supportContacts: any[] = Array.isArray(settingsData?.supportNumbers) ? settingsData.supportNumbers : [];
  const activeSupportContacts = supportContacts.filter(contact => contact.enabled && !contact.type.includes('واتساب'));

  const isQuickLinksActive = privacySectionEnabled || termsSectionEnabled;
  const isSupportActive = supportSectionEnabled && activeSupportContacts.length > 0;
  const isSocialActive = socialSectionEnabled && activeSocialLinks.length > 0;

  let activeColumnCount = 1;
  if (isQuickLinksActive) activeColumnCount++;
  if (isSupportActive) activeColumnCount++;
  if (isSocialActive) activeColumnCount++;

  const gridColsClass = 
    activeColumnCount === 4 ? 'lg:grid-cols-4' :
    activeColumnCount === 3 ? 'lg:grid-cols-3' :
    activeColumnCount === 2 ? 'lg:grid-cols-2' : 'lg:grid-cols-1';

  const getSupportIcon = (type: string) => {
    if (type.includes('واتساب')) return MessageCircle;
    if (type.includes('إيميل') || type.includes('البريد')) return Mail;
    if (type.includes('ساعات') || type.includes('العمل')) return Clock;
    if (type.includes('إضافي')) return PhoneCall;
    return Phone;
  };

  return (
    <>
      <footer className="bg-slate-900 border-t border-slate-800 text-slate-300 pt-16 pb-8">
        <div className="container mx-auto px-6 md:px-12 lg:px-16">
          <div className={`grid grid-cols-1 md:grid-cols-2 ${gridColsClass} gap-12 mb-12`}>
            {/* About Section */}
            <div className="space-y-6">
               <div className="flex items-center gap-2">
                <PlatformLogo className="w-18 h-18 object-contain" />
                <span className="text-2xl font-black tracking-tight" style={{ color: '#3544e1' }}>سند</span>
              </div>
              <p className="text-slate-400 text-sm leading-relaxed">
                سندك الحقيقي نحو التفوق والنجاح
              </p>
            </div>

            {/* Support Contacts */}
            {isSupportActive && (
              <div>
                <h4 className="text-white font-bold mb-6 flex items-center gap-2">الدعم والتواصل</h4>
                <ul className="space-y-4 text-sm text-slate-400">
                  {activeSupportContacts.map((contact, idx) => {
                    const IconComponent = getSupportIcon(contact.type);
                    const values = contact.values || [contact.value].filter(Boolean);
                    if (values.length === 0) return null;

                    return (
                      <li key={idx} className="flex items-start gap-3">
                        <IconComponent className="w-5 h-5 text-slate-500 shrink-0 mt-0.5" />
                        <div className="flex flex-col gap-1">
                          {values.map((v: string, vIdx: number) => {
                            const isPhone = contact.type.includes('رقم') || contact.type.includes('هاتف');
                            const isMail = contact.type.includes('إيميل') || contact.type.includes('البريد');
                            if (isPhone) {
                              return (
                                <a key={vIdx} href={`tel:${v}`} className="hover:text-blue-400 transition-colors" dir="ltr">
                                  {v}
                                </a>
                              );
                            } else if (isMail) {
                              return (
                                <a key={vIdx} href={`mailto:${v}`} className="hover:text-blue-400 transition-colors">
                                  {v}
                                </a>
                              );
                            } else {
                              return <span key={vIdx}>{v}</span>;
                            }
                          })}
                        </div>
                      </li>
                    );
                  })}
                </ul>
              </div>
            )}

            {/* Social Media */}
            {isSocialActive && (
              <div>
                <h4 className="text-white font-bold mb-6 flex items-center gap-2">التواصل الاجتماعي</h4>
                <div className="flex flex-wrap gap-3">
                  {activeSocialLinks.map((link, idx) => {
                    const IconComponent = getSocialPlatformIcon(link.platform);
                    return (
                      <a 
                        key={idx} 
                        href={safeUrl(link.url)} 
                        target="_blank" 
                        rel="noopener noreferrer" 
                        className="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center text-slate-400 hover:text-white hover:bg-[#3544e1] transition-colors"
                      >
                        <IconComponent className="w-5 h-5" />
                      </a>
                    );
                  })}
                </div>
              </div>
            )}

            {/* Quick Links */}
            {isQuickLinksActive && (
              <div className="flex flex-col items-start text-right">
                <h4 className="text-white font-bold mb-6 flex items-center gap-2">روابط هامة</h4>
                <ul className="space-y-4 w-full flex flex-col items-start">
                  {privacySectionEnabled && (
                    <li>
                      <button 
                        onClick={() => setShowPrivacy(true)}
                        className="text-slate-400 hover:text-[#3544e1] transition-colors text-sm flex items-center gap-2 text-right"
                      >
                        <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: '#3544e1', opacity: 0.5 }}></span>
                        سياسة الخصوصية
                      </button>
                    </li>
                  )}
                  {termsSectionEnabled && (
                    <li>
                      <button 
                        onClick={() => setShowTerms(true)}
                        className="text-slate-400 hover:text-[#3544e1] transition-colors text-sm flex items-center gap-2 text-right"
                      >
                        <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: '#3544e1', opacity: 0.5 }}></span>
                        الشروط والأحكام
                      </button>
                    </li>
                  )}
                </ul>
              </div>
            )}
          </div>
          
          <div className="w-full h-px bg-slate-800/60" />
          
          <div className="w-full text-center text-slate-500 text-sm flex flex-col md:flex-row justify-between items-center gap-4 pt-8">
            <p>© {new Date().getFullYear()} منصة سند. جميع الحقوق محفوظة.</p>
            <div className="flex items-center gap-2">
              صنع بشغف لتمكين العقول.
            </div>
          </div>
        </div>
      </footer>

      {/* Modals */}
      <AnimatePresence>
        {(showPrivacy || showTerms) && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => {
                setShowPrivacy(false);
                setShowTerms(false);
              }}
              className="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50"
            />
            <motion.div
              initial={{ opacity: 0, scale: 0.95, y: 20 }}
              animate={{ opacity: 1, scale: 1, y: 0 }}
              exit={{ opacity: 0, scale: 0.95, y: 20 }}
              className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-2xl bg-white dark:bg-slate-900 rounded-3xl shadow-2xl z-50 overflow-hidden font-cairo"
            >
              <div className="p-6 md:p-8">
                <div className="flex items-center justify-between mb-6">
                  <h3 className="text-2xl font-black text-slate-900 dark:text-white">
                    {showPrivacy ? 'سياسة الخصوصية' : 'الشروط والأحكام'}
                  </h3>
                  <button
                    onClick={() => {
                      setShowPrivacy(false);
                      setShowTerms(false);
                    }}
                    aria-label="إغلاق"
                    className="w-10 h-10 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 hover:text-slate-700 dark:hover:text-slate-300 transition-colors"
                  >
                    <X className="w-5 h-5" />
                  </button>
                </div>
                
                <div className="prose prose-slate dark:prose-invert max-w-none max-h-[60vh] overflow-y-auto custom-scrollbar pr-4 text-slate-600 dark:text-slate-300 whitespace-pre-wrap leading-relaxed">
                  {showPrivacy ? privacyText : termsText}
                </div>
                
                <div className="mt-8 pt-6 border-t border-slate-100 dark:border-slate-800 flex justify-end">
                  <button
                    onClick={() => {
                      setShowPrivacy(false);
                      setShowTerms(false);
                    }}
                    className="px-6 py-2.5 text-white rounded-xl font-bold"
                    style={{ backgroundColor: '#3544e1' }}
                  >
                    إغلاق
                  </button>
                </div>
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </>
  );
}
