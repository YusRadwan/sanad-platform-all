import { useEffect, useState, useRef, useMemo } from 'react';
import { useNavigate, useParams } from 'react-router';
import { ArrowLeft, PlayCircle, Loader2, Maximize } from 'lucide-react';
import { motion } from 'motion/react';
import { courseService } from '../services/courseService';
import { logger } from '../services/logger';
import { safeUrl } from '../utils/urlValidation';
import { resolveVideoEmbed } from '../utils/videoEmbed';

/**
 * Loads the YouTube IFrame Player API script exactly once (idempotent across
 * remounts/multiple players on the page) and resolves once `window.YT.Player`
 * is actually usable — the API loads asynchronously and calls the GLOBAL
 * `window.onYouTubeIframeAPIReady`, which is why this can't just be a
 * script's own `onload` handler.
 */
function loadYoutubeApi(): Promise<void> {
  const w = window as any;
  if (w.YT?.Player) return Promise.resolve();

  return new Promise((resolve) => {
    const previous = w.onYouTubeIframeAPIReady;
    w.onYouTubeIframeAPIReady = () => {
      previous?.();
      resolve();
    };

    if (!document.getElementById('youtube-iframe-api')) {
      const tag = document.createElement('script');
      tag.id = 'youtube-iframe-api';
      tag.src = 'https://www.youtube.com/iframe_api';
      document.head.appendChild(tag);
    }
  });
}

/** Same idea for Vimeo's player.js — a plain `<script onload>` suffices here. */
function loadVimeoApi(): Promise<void> {
  const w = window as any;
  if (w.Vimeo?.Player) return Promise.resolve();

  return new Promise((resolve) => {
    const existing = document.getElementById('vimeo-player-api') as HTMLScriptElement | null;
    if (existing) {
      existing.addEventListener('load', () => resolve());
      return;
    }
    const tag = document.createElement('script');
    tag.id = 'vimeo-player-api';
    tag.src = 'https://player.vimeo.com/api/player.js';
    tag.onload = () => resolve();
    document.head.appendChild(tag);
  });
}

/** Seconds -> "12:05" / "1:02:05" for the duration chip. */
function formatDuration(totalSeconds: number): string {
  const seconds = Math.max(0, Math.round(totalSeconds));
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const pad = (n: number) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

export default function VideoPlayerPage() {
  const { videoId } = useParams();
  const navigate = useNavigate();
  const videoRef = useRef<HTMLVideoElement>(null);
  const youtubeContainerRef = useRef<HTMLDivElement>(null);
  const vimeoIframeRef = useRef<HTMLIFrameElement>(null);
  const playerWrapperRef = useRef<HTMLDivElement>(null);
  const isSavingRef = useRef(false);
  const lastSavedRef = useRef<number>(0);

  const [videoData, setVideoData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [lastPosition, setLastPosition] = useState(0);

  useEffect(() => {
    const fetchVideoAndProgress = async () => {
      if (!videoId) return;

      try {
        setLoading(true);
        const [video, progress] = await Promise.all([
          courseService.getLessonVideo(videoId),
          courseService.getLessonProgress(videoId)
        ]);

        setVideoData(video);
        setLastPosition(progress.last_position_seconds || 0);
      } catch (err: any) {
        console.error('Failed to load video:', err);
        setError('عذراً، فشل تحميل الفيديو. يرجى المحاولة مرة أخرى.');
      } finally {
        setLoading(false);
      }
    };

    fetchVideoAndProgress();
  }, [videoId]);

  // Before this fix, EVERY video (regardless of provider) was fed into a
  // plain native <video src>. That element can only render a direct media
  // file — a youtube.com or vimeo.com URL doesn't play in it at all, so any
  // lesson added as YouTube or Vimeo silently failed to play for students,
  // even though the admin's own add/edit preview (VideoManagement.tsx)
  // already built the correct iframe embed for those two providers. Both
  // surfaces now resolve through the same shared utility.
  const embed = useMemo(
    () => (videoData?.videoUrl ? resolveVideoEmbed(videoData.videoUrl, videoData.provider) : null),
    [videoData?.videoUrl, videoData?.provider]
  );

  /**
   * Shared by all three playable kinds — only WHERE currentTime/duration come
   * from differs (native `<video>` properties vs. the YouTube/Vimeo player
   * APIs' own reported values). Same throttle/force/ended semantics as
   * before this fix.
   */
  const persistProgress = (currentTime: number, duration: number, force = false, isEnded = false) => {
    if (!videoId || loading) return;

    const now = Date.now();
    const timeSinceLastSave = now - lastSavedRef.current;

    if (force || isEnded || (!isSavingRef.current && timeSinceLastSave >= 30000)) {
      if (duration && !isNaN(currentTime) && !isNaN(duration)) {
        isSavingRef.current = true;
        lastSavedRef.current = now;
        const percentage = isEnded ? 100 : (currentTime / duration) * 100;

        courseService.saveLessonProgress(videoId, isEnded ? duration : currentTime, percentage)
          .catch((err) => logger.warn('Failed to save video progress gracefully', err))
          .finally(() => { isSavingRef.current = false; });
      }
    }
  };

  // ── Native <video> progress tracking (Bunny direct-media / plain URLs) ──

  const onLoadedMetadata = () => {
    if (videoRef.current) {
      const duration = videoRef.current.duration;
      if (lastPosition > 0 && duration && lastPosition <= duration) {
        videoRef.current.currentTime = lastPosition;
      }
    }
  };

  useEffect(() => {
    if (embed?.kind !== 'native') return;
    const videoElement = videoRef.current;
    if (!videoElement || !videoId || loading) return;

    const handleTimeUpdateNative = () => persistProgress(videoElement.currentTime, videoElement.duration);
    const handleEndedNative = () => persistProgress(videoElement.duration, videoElement.duration, true, true);
    const handlePauseNative = () => persistProgress(videoElement.currentTime, videoElement.duration, true);

    videoElement.addEventListener('timeupdate', handleTimeUpdateNative);
    videoElement.addEventListener('ended', handleEndedNative);
    videoElement.addEventListener('pause', handlePauseNative);

    return () => {
      videoElement.removeEventListener('timeupdate', handleTimeUpdateNative);
      videoElement.removeEventListener('ended', handleEndedNative);
      videoElement.removeEventListener('pause', handlePauseNative);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [embed?.kind, videoId, loading]);

  // ── YouTube progress tracking, via the official IFrame Player API ──

  useEffect(() => {
    if (embed?.kind !== 'youtube' || !embed.youtubeVideoId || loading) return;
    if (!youtubeContainerRef.current) return;

    let player: any;
    let pollInterval: ReturnType<typeof setInterval> | undefined;
    let cancelled = false;

    loadYoutubeApi().then(() => {
      if (cancelled || !youtubeContainerRef.current) return;
      const YT = (window as any).YT;

      player = new YT.Player(youtubeContainerRef.current, {
        videoId: embed.youtubeVideoId,
        playerVars: { playsinline: 1 },
        events: {
          onReady: (e: any) => {
            if (lastPosition > 0) e.target.seekTo(lastPosition, true);
          },
          onStateChange: (e: any) => {
            if (e.data === YT.PlayerState.PLAYING) {
              pollInterval = setInterval(() => {
                persistProgress(player.getCurrentTime(), player.getDuration());
              }, 30000);
            } else {
              clearInterval(pollInterval);
              if (e.data === YT.PlayerState.PAUSED) {
                persistProgress(player.getCurrentTime(), player.getDuration(), true);
              } else if (e.data === YT.PlayerState.ENDED) {
                persistProgress(player.getDuration(), player.getDuration(), true, true);
              }
            }
          },
        },
      });
    });

    return () => {
      cancelled = true;
      clearInterval(pollInterval);
      player?.destroy?.();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [embed?.kind, embed?.youtubeVideoId, videoId, loading]);

  // ── Vimeo progress tracking, via player.js ──

  useEffect(() => {
    if (embed?.kind !== 'vimeo' || loading) return;
    if (!vimeoIframeRef.current) return;

    let player: any;
    let cancelled = false;

    loadVimeoApi().then(() => {
      if (cancelled || !vimeoIframeRef.current) return;
      const Vimeo = (window as any).Vimeo;
      player = new Vimeo.Player(vimeoIframeRef.current);

      player.ready().then(() => {
        if (!cancelled && lastPosition > 0) {
          player.setCurrentTime(lastPosition).catch(() => {});
        }
      });

      player.on('timeupdate', (data: { seconds: number; duration: number }) => {
        persistProgress(data.seconds, data.duration);
      });
      player.on('pause', (data: { seconds: number; duration: number }) => {
        persistProgress(data.seconds, data.duration, true);
      });
      player.on('ended', (data: { duration: number }) => {
        persistProgress(data.duration, data.duration, true, true);
      });
    });

    return () => {
      cancelled = true;
      player?.unload?.().catch(() => {});
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [embed?.kind, videoId, loading]);

  // A guaranteed fullscreen affordance regardless of embed kind: native
  // <video> controls and the YouTube/Vimeo/Bunny iframes each rely on their
  // own provider's fullscreen icon (which can be hidden/disabled by the
  // browser or embed context), so this button requests fullscreen on the
  // whole player wrapper instead, which always works.
  const handleFullscreen = () => {
    const el = playerWrapperRef.current as any;
    if (!el) return;
    if (el.requestFullscreen) el.requestFullscreen();
    else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
  };

  const handleBack = () => {
    if (videoData?.courseId) {
      navigate(`/course/${videoData.courseId}`);
    } else {
      navigate(-1);
    }
  };

  if (loading) {
    return (
      <div className="min-h-screen bg-slate-900 flex items-center justify-center">
        <Loader2 className="w-10 h-10 text-blue-500 animate-spin" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="min-h-screen bg-slate-900 flex flex-col items-center justify-center p-6 text-center">
        <p className="text-red-400 text-lg mb-6">{error}</p>
        <button onClick={handleBack} className="bg-slate-800 text-white px-6 py-2 rounded-xl">العودة</button>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-slate-900 text-slate-200 font-sans" dir="rtl">
      {/* Header */}
      <div className="bg-slate-950 border-b border-slate-800 p-4 sticky top-0 z-50">
        <div className="max-w-7xl mx-auto flex items-center justify-between">
          <h1 className="text-xl font-bold text-white flex items-center gap-3">
            <PlayCircle className="w-6 h-6 text-blue-500" />
            <span>{videoData?.title || 'محاضرة تفاعلية'}</span>
          </h1>
          <button
            onClick={handleBack}
            className="flex items-center gap-2 px-4 py-2 text-sm font-bold bg-slate-800 hover:bg-slate-700 text-white rounded-lg transition-colors"
          >
            <ArrowLeft className="w-4 h-4" />
            العودة للكورس
          </button>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 py-8">
        <motion.div
          ref={playerWrapperRef}
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          className="aspect-video bg-black rounded-3xl overflow-hidden shadow-2xl border border-slate-800 flex items-center justify-center relative group"
        >
          <button
            type="button"
            onClick={handleFullscreen}
            title="تكبير الشاشة بالكامل"
            aria-label="تكبير الشاشة بالكامل"
            className="absolute top-4 left-4 z-20 p-2.5 rounded-xl bg-black/60 hover:bg-black/80 text-white opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity"
          >
            <Maximize className="w-5 h-5" />
          </button>
          {embed?.kind === 'youtube' ? (
            <div ref={youtubeContainerRef} className="w-full h-full absolute inset-0" />
          ) : embed?.kind === 'vimeo' ? (
            <iframe
              ref={vimeoIframeRef}
              src={safeUrl(embed.src)}
              title={videoData?.title || 'فيديو'}
              className="w-full h-full border-0 absolute inset-0"
              allow="autoplay; fullscreen; picture-in-picture"
              allowFullScreen
            />
          ) : embed?.kind === 'bunny-iframe' ? (
            // Plays correctly (Bunny Stream's iframe embed is self-contained,
            // same as YouTube/Vimeo's), but progress is NOT tracked here —
            // unlike YouTube/Vimeo, Bunny's iframe postMessage API isn't
            // wired up (no Bunny account/library available to verify it
            // against). A lesson added as a direct Bunny CDN media URL
            // instead of this iframe embed uses the native <video> path
            // below and gets full progress tracking as normal.
            <iframe
              src={safeUrl(embed.src)}
              title={videoData?.title || 'فيديو'}
              className="w-full h-full border-0 absolute inset-0"
              allow="accelerometer; autoplay; encrypted-media; picture-in-picture"
              allowFullScreen
            />
          ) : (
            <video
              ref={videoRef}
              src={safeUrl(videoData?.videoUrl)}
              controls
              onLoadedMetadata={onLoadedMetadata}
              className="w-full h-full"
              poster={safeUrl(videoData?.thumbnailUrl)}
            />
          )}
        </motion.div>

        <div className="mt-8 bg-slate-800/50 p-6 rounded-2xl border border-slate-700">
          <div className="flex items-center justify-between gap-4 flex-wrap mb-2">
            <h2 className="text-xl font-bold text-white">{videoData?.title}</h2>
            {!!videoData?.duration && (
              <span className="shrink-0 px-3 py-1 rounded-lg bg-blue-500/10 text-blue-400 text-sm font-bold">
                مدة الفيديو: {formatDuration(videoData.duration)}
              </span>
            )}
          </div>
          <p className="text-slate-400">أنت الآن تشاهد محتوى تعليمي حصري من منصة سند.</p>
        </div>
      </div>
    </div>
  );
}
