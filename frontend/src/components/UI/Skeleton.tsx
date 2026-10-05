import type { HTMLAttributes } from 'react';
import { cn } from './cn';

export interface SkeletonProps extends HTMLAttributes<HTMLDivElement> {
  /** Shape preset. @default 'block' */
  variant?: 'text' | 'block' | 'circle';
  /** Any CSS width, e.g. '100%', '8rem'. */
  width?: string | number;
  /** Any CSS height. Ignored for 'text', which uses the line height. */
  height?: string | number;
}

/**
 * Loading placeholder, replacing the ad-hoc `animate-pulse bg-slate-200` divs
 * scattered across pages so every skeleton pulses at the same rate and tone.
 */
export function Skeleton({ variant = 'block', width, height, className, style, ...rest }: SkeletonProps) {
  return (
    <div
      aria-hidden="true"
      style={{ width, height, ...style }}
      className={cn(
        'animate-pulse bg-slate-200/80 dark:bg-slate-800',
        variant === 'circle' && 'rounded-full',
        variant === 'text' && 'rounded-md h-3.5',
        variant === 'block' && 'rounded-xl',
        className,
      )}
      {...rest}
    />
  );
}

export interface SkeletonTextProps {
  /** Number of lines. @default 3 */
  lines?: number;
  className?: string;
}

/** A stack of text-line skeletons, last line intentionally shorter. */
export function SkeletonText({ lines = 3, className }: SkeletonTextProps) {
  return (
    <div className={cn('space-y-2.5', className)}>
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} variant="text" width={i === lines - 1 ? '60%' : '100%'} />
      ))}
    </div>
  );
}

export default Skeleton;
