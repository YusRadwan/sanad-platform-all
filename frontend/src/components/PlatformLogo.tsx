import { useState } from 'react';
import { PlatformLogoProps } from '../types/index';

/**
 * The platform logo, wherever it is rendered (admin header, instructor header,
 * public navbar).
 *
 * THE SOURCE OF TRUTH IS THE STATIC ASSET /branding/logo.png, AND NOTHING ELSE.
 *
 * All site branding lives in frontend/public/branding, in PNG form only — one
 * logo and one favicon, the same two files index.html points at for the tab
 * icon, the apple-touch-icon and the OpenGraph/Twitter preview. To rebrand,
 * replace those two files; no code change and no database write is needed.
 *
 * There used to be a second path: a `platform_logo` key in the generic
 * `settings` table, written by an admin upload tab that was removed, then read
 * here as an override on top of the static file. It is gone — the settings key,
 * its validation rule and this component's fetch all removed together — because
 * a stored value could only ever differ from the two files the rest of the site
 * (index.html included, which has no API access) actually shows, which is a
 * split-brand bug waiting to happen.
 *
 * `onError` is kept, but only for what it says: if the static file is ever
 * missing or corrupt the element is hidden rather than left as a broken-image
 * icon. It is no longer an override mechanism.
 *
 * DARK MODE — the logo keeps its original colours in both themes on request;
 * no filter is applied here. (A `brightness-0 invert` repaint used to run in
 * dark mode to keep the navy mark visible against dark surfaces; removed
 * per explicit instruction to never recolour the logo anywhere on the site.)
 */

const STATIC_LOGO = '/branding/logo.png';

export default function PlatformLogo({ className = "w-11 h-11 object-contain" }: PlatformLogoProps) {
  const [failed, setFailed] = useState(false);

  if (failed) return null;

  return (
    <img
      src={STATIC_LOGO}
      alt="Platform Logo"
      className={className}
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
    />
  );
}
