/**
 * Zero-dependency "PDF export" — opens a print-formatted window and invokes
 * the browser's native print dialog, where "Save as PDF" produces the file.
 *
 * WHY NOT A PDF LIBRARY (jspdf etc.)
 * -----------------------------------
 * Same reasoning as xlsxExport.ts's own banner: this repo prefers a small
 * hand-built implementation over a new dependency when the browser already
 * provides the primitive. Every modern browser's print dialog offers
 * "Save as PDF" as a destination, so a plain, well-styled HTML document is
 * a real PDF export with zero added dependency weight or supply-chain
 * surface — it just requires ONE extra click (choosing the destination)
 * instead of an automatic download.
 */

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export function printTableAsPdf(title: string, columns: string[], rows: string[][]): void {
  const win = window.open('', '_blank', 'noopener,noreferrer');
  if (!win) return;

  const headerHtml = columns.map((c) => `<th>${escapeHtml(c)}</th>`).join('');
  const rowsHtml = rows
    .map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`)
    .join('');

  win.document.write(`<!doctype html>
<html dir="rtl" lang="ar">
<head>
<meta charset="utf-8" />
<title>${escapeHtml(title)}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; padding: 24px; color: #0f172a; }
  h1 { font-size: 18px; margin: 0 0 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: right; }
  th { background: #1e293b; color: #fff; }
  tr:nth-child(even) td { background: #f1f5f9; }
  @media print {
    body { padding: 0; }
  }
</style>
</head>
<body>
<h1>${escapeHtml(title)}</h1>
<table>
<thead><tr>${headerHtml}</tr></thead>
<tbody>${rowsHtml}</tbody>
</table>
</body>
</html>`);
  win.document.close();
  win.focus();

  // A short delay lets the document (and any layout it triggers) finish
  // rendering before the print dialog steals focus — printing immediately
  // on `document.write` can race an empty paint in some browsers.
  setTimeout(() => win.print(), 250);
}
