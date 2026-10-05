/**
 * Single source of truth for turning a stored `video_url` (+ `provider`)
 * into something actually playable.
 *
 * Before this module existed, VideoManagement.tsx's admin preview and
 * VideoPlayerPage.tsx's student player each had their own (different, and in
 * the student player's case entirely ABSENT) logic for this. The student
 * player fed every URL straight into a native `<video src>` element — which
 * cannot render a youtube.com or vimeo.com page at all, so any lesson added
 * as YouTube or Vimeo silently failed to play for students despite looking
 * fine in the admin's own preview. Both surfaces now resolve through the
 * exact same function.
 */

export type VideoEmbedKind = 'youtube' | 'vimeo' | 'bunny-iframe' | 'native';

export interface ResolvedVideoEmbed {
  kind: VideoEmbedKind;
  /** The URL to actually put in the `<iframe src>` / `<video src>`. */
  src: string;
  /** Only set for kind 'youtube' — needed by the IFrame Player API. */
  youtubeVideoId?: string;
  /** Only set for kind 'vimeo' — needed by Vimeo's player.js API. */
  vimeoVideoId?: string;
}

const YOUTUBE_ID_PATTERN = /^.*(?:youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/;

export function getYoutubeVideoId(url: string): string | null {
  if (!url) return null;
  const match = url.match(YOUTUBE_ID_PATTERN);
  return match && match[1]?.length === 11 ? match[1] : null;
}

export function getYoutubeEmbedUrl(url: string): string {
  const id = getYoutubeVideoId(url);
  return id ? `https://www.youtube.com/embed/${id}?enablejsapi=1` : url;
}

const VIMEO_ID_PATTERN =
  /vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/(?:[^/]*)\/videos\/|album\/(?:\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?)/;

export function getVimeoVideoId(url: string): string | null {
  if (!url) return null;
  const match = url.match(VIMEO_ID_PATTERN);
  return match?.[1] ?? null;
}

export function getVimeoEmbedUrl(url: string): string {
  const id = getVimeoVideoId(url);
  return id ? `https://player.vimeo.com/video/${id}?api=1` : url;
}

/**
 * Bunny Stream's own iframe embed page (`iframe.mediadelivery.net/embed/...`,
 * and its predecessor host `iframe.b-cdn.net`), as opposed to a direct
 * progressive/HLS media file served off a Bunny CDN pull zone
 * (`{zone}.b-cdn.net/.../video.mp4` or `.m3u8`). Only the former needs an
 * `<iframe>` — the latter plays directly in a native `<video>` element,
 * exactly like any other direct media URL.
 */
export function isBunnyIframeEmbedUrl(url: string): boolean {
  if (!url) return false;
  try {
    const host = new URL(url).hostname;
    return host === 'iframe.mediadelivery.net' || host === 'iframe.b-cdn.net';
  } catch {
    return false;
  }
}

/**
 * The one function both VideoManagement.tsx (admin preview) and
 * VideoPlayerPage.tsx (student player) call. `provider` is the instructor's
 * own dropdown selection ('YouTube' | 'Vimeo' | 'Bunny') — trusted as a hint,
 * but the URL shape is still what actually decides rendering, since nothing
 * upstream guarantees the two agree (see StoreContentItemDetailRequest).
 */
export function resolveVideoEmbed(url: string, provider?: string | null): ResolvedVideoEmbed {
  const youtubeId = getYoutubeVideoId(url);
  if (youtubeId || provider === 'YouTube') {
    return { kind: 'youtube', src: getYoutubeEmbedUrl(url), youtubeVideoId: youtubeId ?? undefined };
  }

  const vimeoId = getVimeoVideoId(url);
  if (vimeoId || provider === 'Vimeo') {
    return { kind: 'vimeo', src: getVimeoEmbedUrl(url), vimeoVideoId: vimeoId ?? undefined };
  }

  if (isBunnyIframeEmbedUrl(url)) {
    return { kind: 'bunny-iframe', src: url };
  }

  // Bunny direct-media URL, or any other plain http(s) media file: native
  // <video> — including the case where `provider === 'Bunny'` but the URL
  // doesn't look like anything special (an instructor-pasted CDN link). The
  // browser itself is the final judge of whether it actually plays.
  return { kind: 'native', src: url };
}
