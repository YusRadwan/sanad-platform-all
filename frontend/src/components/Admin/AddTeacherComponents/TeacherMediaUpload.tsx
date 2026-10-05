import React from 'react';
import { Upload, Image, CheckCircle2 } from 'lucide-react';
import { Card, CardHeader, Button } from '../../UI';
import { AVATAR_PLACEHOLDER, BANNER_PLACEHOLDER } from '../../../utils/imagePlaceholders';

interface TeacherMediaUploadProps {
  cardImage: string;
  setCardImage: (val: string) => void;
  bannerImage: string;
  setBannerImage: (val: string) => void;
  cardFileRef: React.RefObject<HTMLInputElement | null>;
  bannerFileRef: React.RefObject<HTMLInputElement | null>;
  handleCardImageUpload: (e: React.ChangeEvent<HTMLInputElement>) => void;
  handleBannerImageUpload: (e: React.ChangeEvent<HTMLInputElement>) => void;
}

/**
 * Visual-only migration. The two hidden `<input type="file">` elements keep the
 * exact same `ref`, `onChange` handler and `accept="image/*"`; the dropzone
 * still triggers `ref.current?.click()`; the clear buttons still call
 * `setCardImage('')` / `setBannerImage('')` behind `e.stopPropagation()`.
 *
 * `dark:border-slate-800` (used on both dropzones) is not a real Tailwind
 * shade and emitted no CSS — the dark-mode dropzone border was simply missing.
 * It is now `dark:border-slate-800`.
 */
const DROPZONE =
  'border-2 border-dashed border-slate-200 dark:border-slate-800 hover:border-blue-500 dark:hover:border-blue-500 rounded-2xl p-6 text-center cursor-pointer bg-slate-50/60 dark:bg-slate-950/30 transition-colors relative group';

export default function TeacherMediaUpload({
  cardImage,
  setCardImage,
  bannerImage,
  setBannerImage,
  cardFileRef,
  bannerFileRef,
  handleCardImageUpload,
  handleBannerImageUpload
}: TeacherMediaUploadProps) {
  return (
    <Card>
      <CardHeader
        icon={Upload}
        title="الصور والملفات الإعلانية للمدرس"
        description="صورة كارت العرض وبانر الخلفية — صورتان منفصلتان لكل منهما مكان عرض مختلف"
      />

      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">
        {/* Control 1: Card profile Image */}
        <div className="space-y-3">
          <span className="block text-[11px] sm:text-xs font-bold text-slate-700 dark:text-slate-300">
            صورة كارد المدرس الشخصي (تظهر بالرئيسية وبقايا التصفح)
          </span>

          <div onClick={() => cardFileRef.current?.click()} className={DROPZONE}>
            <input
              type="file"
              ref={cardFileRef}
              onChange={handleCardImageUpload}
              accept="image/*"
              className="hidden"
            />

            {cardImage ? (
              <div className="space-y-3 flex flex-col items-center justify-center">
                <img
                  src={cardImage}
                  alt="Teacher Card preview"
                  className="w-24 h-24 rounded-2xl object-cover shadow-md border border-white dark:border-slate-800"
                  // `cardImage` can be pre-filled from an EXISTING teacher's
                  // stored URL when editing — if that file is gone from the
                  // server this swaps the broken icon for the shared
                  // placeholder rather than leaving it broken next to a
                  // "تم تحميل الصورة" success message.
                  onError={(e) => {
                    e.currentTarget.onerror = null;
                    e.currentTarget.src = AVATAR_PLACEHOLDER;
                  }}
                />
                <span className="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                  <CheckCircle2 className="w-3.5 h-3.5" />
                  تم تحميل الصورة الإعلانية
                </span>
              </div>
            ) : (
              <div className="space-y-2.5 py-4 flex flex-col items-center justify-center">
                <div className="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center group-hover:scale-105 transition-all">
                  <Image className="w-5 h-5" />
                </div>
                <span className="text-xs font-bold text-slate-600 dark:text-slate-300">سحب وإفلات صورة الكارد هنا، أو انقر للتحديد</span>
                <span className="text-[10px] text-slate-500 dark:text-slate-400 block font-medium">حجم مقترح: 400 × 400 بكسل مربع</span>
              </div>
            )}
          </div>

          <div className="flex justify-end items-center text-right min-h-8">
            {cardImage && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={(e) => {
                  e.stopPropagation();
                  setCardImage('');
                }}
                className="text-rose-500 hover:text-rose-600"
              >
                حذف وتصغير
              </Button>
            )}
          </div>
        </div>

        {/* Control 2: Profile Banner space */}
        <div className="space-y-3">
          <span className="block text-[11px] sm:text-xs font-bold text-slate-700 dark:text-slate-300">
            خلفية بانر الملف الشخصي (البروفايل التعريفي العلوي)
          </span>

          <div onClick={() => bannerFileRef.current?.click()} className={DROPZONE}>
            <input
              type="file"
              ref={bannerFileRef}
              onChange={handleBannerImageUpload}
              accept="image/*"
              className="hidden"
            />

            {bannerImage ? (
              <div className="space-y-3 flex flex-col items-center justify-center">
                <img
                  src={bannerImage}
                  alt="Teacher Banner preview"
                  className="w-full h-16 rounded-xl object-cover shadow-md border border-white dark:border-slate-800"
                  onError={(e) => {
                    e.currentTarget.onerror = null;
                    e.currentTarget.src = BANNER_PLACEHOLDER;
                  }}
                />
                <span className="text-[11px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1.5">
                  <CheckCircle2 className="w-3.5 h-3.5" />
                  تم تحميل البانر بنجاح
                </span>
              </div>
            ) : (
              <div className="space-y-2.5 py-4 flex flex-col items-center justify-center">
                <div className="w-11 h-11 rounded-xl bg-indigo-50 dark:bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center group-hover:scale-105 transition-all">
                  <Image className="w-5 h-5" />
                </div>
                <span className="text-xs font-bold text-slate-600 dark:text-slate-300">سحب وإفلات صورة الغطاء العريض، أو تصفح ملفاتك</span>
                <span className="text-[10px] text-slate-500 dark:text-slate-400 block font-medium">حجم نسبة عرض: 1200 × 400 بكسل أفقي</span>
              </div>
            )}
          </div>

          <div className="flex justify-end items-center text-right min-h-8">
            {bannerImage && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={(e) => {
                  e.stopPropagation();
                  setBannerImage('');
                }}
                className="text-rose-500 hover:text-rose-600"
              >
                حذف الغطاء
              </Button>
            )}
          </div>
        </div>

      </div>
    </Card>
  );
}
