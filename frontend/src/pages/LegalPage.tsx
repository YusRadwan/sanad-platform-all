import type { ReactNode } from 'react';

/** Reading layout for the legal pages: one column at a readable measure. */
export default function LegalPage({ title, children }: { title: string; children: ReactNode }) {
  return (
    <article className="mx-auto w-full max-w-[720px] px-5 py-14 sm:px-8 sm:py-20">
      <h1 className="m-0 mb-8 font-kufi text-[34px] font-semibold leading-[1.35] sm:text-[44px]">{title}</h1>
      <div className="legal-body flex flex-col gap-4 text-[17px] leading-[1.85] text-[color:var(--ink-2)] [&_h2]:m-0 [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-[color:var(--ink)] [&_p]:m-0">
        {children}
      </div>
    </article>
  );
}
