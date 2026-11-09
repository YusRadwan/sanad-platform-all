import {describe, expect, it} from 'vitest';

import {isBunnyIframeEmbedUrl, resolveVideoEmbed} from './videoEmbed';

describe('Bunny Stream embed detection', () => {
  it.each([
    'https://player.mediadelivery.net/embed/12345/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'https://iframe.mediadelivery.net/embed/12345/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'https://iframe.b-cdn.net/embed/12345/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
  ])('recognizes Bunny iframe URL %s', (url) => {
    expect(isBunnyIframeEmbedUrl(url)).toBe(true);
    expect(resolveVideoEmbed(url, 'Bunny').kind).toBe('bunny-iframe');
  });

  it('prioritizes a Bunny embed URL over stale Vimeo provider metadata', () => {
    const url = 'https://player.mediadelivery.net/embed/72101/0c6ba42c-965a-4f7b-9e35-000000000000';
    expect(resolveVideoEmbed(url, 'Vimeo').kind).toBe('bunny-iframe');
  });

  it('keeps Bunny direct media URLs on the native video player', () => {
    const url = 'https://example.b-cdn.net/path/video.mp4';
    expect(isBunnyIframeEmbedUrl(url)).toBe(false);
    expect(resolveVideoEmbed(url, 'Bunny').kind).toBe('native');
  });
});
