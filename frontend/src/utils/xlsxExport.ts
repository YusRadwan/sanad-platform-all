/**
 * Zero-dependency writer for real .xlsx (Office Open XML SpreadsheetML) files.
 *
 * WHY THIS EXISTS INSTEAD OF A LIBRARY
 * ------------------------------------
 * The admin subscriptions table must export "ملف اكسيل منظم احترافياً" — an
 * actual workbook, not a CSV renamed. The two obvious npm options were both
 * rejected:
 *   - `xlsx` (SheetJS): the version published to npm is 0.18.5 (2022) and
 *     carries open advisories; the fixed releases are only distributed from
 *     the vendor's own CDN, which makes for a fragile package.json entry.
 *   - `exceljs`: pulls in `archiver` (a Node-only zip stack) and, measured in
 *     this repo, 12 transitive advisories — plus it is not browser-first.
 *
 * An .xlsx is just a ZIP of XML parts, and the ZIP spec allows STORED
 * (uncompressed) entries, so no deflate implementation — and therefore no
 * dependency and no CompressionStream browser requirement — is needed. The
 * files this produces open natively in Excel / LibreOffice / Google Sheets
 * and are structurally identical to a library-produced workbook, just
 * without compression.
 *
 * Formatting supported (enough for "professional", deliberately not more):
 * bold white-on-blue header row, banded borders, per-column widths, a frozen
 * header row, an autofilter over the header, and right-to-left sheet layout.
 */

export interface XlsxColumn {
  /** Header text shown in row 1. */
  header: string;
  /** Column width in Excel character units. */
  width?: number;
}

// --- CRC32 (required by the ZIP local/central headers) ---

const CRC_TABLE = (() => {
  const table = new Uint32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[i] = c >>> 0;
  }
  return table;
})();

function crc32(bytes: Uint8Array): number {
  let c = 0xffffffff;
  for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}

// --- Minimal STORED-entry ZIP writer ---

interface ZipEntry {
  name: string;
  data: Uint8Array;
}

function writeUint32(view: DataView, offset: number, value: number) {
  view.setUint32(offset, value, true);
}

function buildZip(entries: ZipEntry[]): Blob {
  const encoder = new TextEncoder();
  const locals: Uint8Array[] = [];
  const centrals: Uint8Array[] = [];
  let offset = 0;

  for (const entry of entries) {
    const nameBytes = encoder.encode(entry.name);
    const crc = crc32(entry.data);
    const size = entry.data.length;

    // Local file header (30 bytes) + name + data. Method 0 = stored.
    const local = new Uint8Array(30 + nameBytes.length + size);
    const lv = new DataView(local.buffer);
    writeUint32(lv, 0, 0x04034b50);
    lv.setUint16(4, 20, true); // version needed
    lv.setUint16(6, 0x0800, true); // UTF-8 filename flag
    lv.setUint16(8, 0, true); // stored
    lv.setUint16(10, 0, true); // mod time
    lv.setUint16(12, 0x21, true); // mod date (1980-01-01)
    writeUint32(lv, 14, crc);
    writeUint32(lv, 18, size);
    writeUint32(lv, 22, size);
    lv.setUint16(26, nameBytes.length, true);
    lv.setUint16(28, 0, true);
    local.set(nameBytes, 30);
    local.set(entry.data, 30 + nameBytes.length);
    locals.push(local);

    // Central directory record (46 bytes) + name.
    const central = new Uint8Array(46 + nameBytes.length);
    const cv = new DataView(central.buffer);
    writeUint32(cv, 0, 0x02014b50);
    cv.setUint16(4, 20, true);
    cv.setUint16(6, 20, true);
    cv.setUint16(8, 0x0800, true);
    cv.setUint16(10, 0, true);
    cv.setUint16(12, 0, true);
    cv.setUint16(14, 0x21, true);
    writeUint32(cv, 16, crc);
    writeUint32(cv, 20, size);
    writeUint32(cv, 24, size);
    cv.setUint16(28, nameBytes.length, true);
    cv.setUint16(30, 0, true);
    cv.setUint16(32, 0, true);
    cv.setUint16(34, 0, true);
    cv.setUint16(36, 0, true);
    writeUint32(cv, 38, 0);
    writeUint32(cv, 42, offset);
    central.set(nameBytes, 46);
    centrals.push(central);

    offset += local.length;
  }

  const centralSize = centrals.reduce((sum, c) => sum + c.length, 0);
  const end = new Uint8Array(22);
  const ev = new DataView(end.buffer);
  writeUint32(ev, 0, 0x06054b50);
  ev.setUint16(8, entries.length, true);
  ev.setUint16(10, entries.length, true);
  writeUint32(ev, 12, centralSize);
  writeUint32(ev, 16, offset);

  return new Blob([...locals, ...centrals, end], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  });
}

// --- SpreadsheetML parts ---

function escapeXml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
    // Control characters are illegal in XML 1.0 and make Excel refuse the file.
    .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, '');
}

function columnLetter(index: number): string {
  let letter = '';
  let n = index + 1;
  while (n > 0) {
    const rem = (n - 1) % 26;
    letter = String.fromCharCode(65 + rem) + letter;
    n = Math.floor((n - 1) / 26);
  }
  return letter;
}

/**
 * Every cell is written as an inline string. Numbers-as-text is the correct
 * choice here: the exported columns are phone numbers and student codes,
 * which Excel would otherwise mangle by stripping leading zeros (01111111111
 * becoming 1111111111) or switching to scientific notation.
 */
function cellXml(rowIndex: number, colIndex: number, value: string, styleId: number): string {
  const ref = `${columnLetter(colIndex)}${rowIndex}`;
  return `<c r="${ref}" s="${styleId}" t="inlineStr"><is><t xml:space="preserve">${escapeXml(value)}</t></is></c>`;
}

function sheetXml(columns: XlsxColumn[], rows: string[][]): string {
  const cols = columns
    .map((c, i) => `<col min="${i + 1}" max="${i + 1}" width="${c.width ?? 18}" customWidth="1"/>`)
    .join('');

  const headerCells = columns.map((c, i) => cellXml(1, i, c.header, 1)).join('');
  const bodyRows = rows
    .map((row, r) => {
      const cells = columns.map((_, i) => cellXml(r + 2, i, row[i] ?? '', 2)).join('');
      return `<row r="${r + 2}">${cells}</row>`;
    })
    .join('');

  const lastCol = columnLetter(columns.length - 1);
  const lastRow = rows.length + 1;

  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>
<dimension ref="A1:${lastCol}${lastRow}"/>
<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>
<sheetFormatPr defaultRowHeight="18"/>
<cols>${cols}</cols>
<sheetData><row r="1" ht="26" customHeight="1">${headerCells}</row>${bodyRows}</sheetData>
<autoFilter ref="A1:${lastCol}${lastRow}"/>
</worksheet>`;
}

/**
 * Style ids used above: 0 = default, 1 = header, 2 = body.
 * Hand-authored rather than generated because only these three exist.
 */
const STYLES_XML = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<fonts count="3">
<font><sz val="11"/><name val="Calibri"/></font>
<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
<font><sz val="11"/><color rgb="FF1E293B"/><name val="Calibri"/></font>
</fonts>
<fills count="3">
<fill><patternFill patternType="none"/></fill>
<fill><patternFill patternType="gray125"/></fill>
<fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill>
</fills>
<borders count="2">
<border><left/><right/><top/><bottom/><diagonal/></border>
<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom><diagonal/></border>
</borders>
<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
<cellXfs count="3">
<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>
</cellXfs>
<dxfs count="0"/>
</styleSheet>`;

/**
 * Builds a single-sheet .xlsx workbook and returns it as a Blob.
 *
 * @param sheetName Worksheet tab name (Excel limits it to 31 chars and
 *                  forbids : \\ / ? * [ ] — both are enforced here).
 */
export function buildXlsx(columns: XlsxColumn[], rows: string[][], sheetName = 'Sheet1'): Blob {
  const encoder = new TextEncoder();
  const safeName = sheetName.replace(/[:\\/?*[\]]/g, ' ').slice(0, 31) || 'Sheet1';

  const parts: Array<[string, string]> = [
    [
      '[Content_Types].xml',
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>`,
    ],
    [
      '_rels/.rels',
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>`,
    ],
    [
      'xl/workbook.xml',
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="${escapeXml(safeName)}" sheetId="1" r:id="rId1"/></sheets>
</workbook>`,
    ],
    [
      'xl/_rels/workbook.xml.rels',
      `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
</Relationships>`,
    ],
    ['xl/styles.xml', STYLES_XML],
    ['xl/worksheets/sheet1.xml', sheetXml(columns, rows)],
  ];

  return buildZip(parts.map(([name, xml]) => ({ name, data: encoder.encode(xml) })));
}

/** Builds the workbook and triggers a browser download. */
export function downloadXlsx(
  filename: string,
  columns: XlsxColumn[],
  rows: string[][],
  sheetName = 'Sheet1'
): void {
  const blob = buildXlsx(columns, rows, sheetName);
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename.endsWith('.xlsx') ? filename : `${filename}.xlsx`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  // Revoked on the next tick so the click has definitely been dispatched.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
