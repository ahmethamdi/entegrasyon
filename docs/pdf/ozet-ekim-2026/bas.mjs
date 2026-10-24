import { chromium } from 'playwright';
const [src, out] = process.argv.slice(2);
const b = await chromium.launch({ channel: 'chrome' });
const p = await b.newPage();
await p.goto('file://' + src, { waitUntil: 'networkidle' });
await p.evaluate(() => document.fonts.ready);
await p.pdf({ path: out, format: 'A4', printBackground: true, preferCSSPageSize: true,
  displayHeaderFooter: true, headerTemplate: '<div></div>',
  footerTemplate: '<div style="width:100%;font-family:Inter,sans-serif;font-size:7.5pt;color:#5b6474;padding:0 16mm;display:flex;justify-content:space-between"><span>34Pazar · Durum Özeti · Ekim 2026</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>' });
await b.close();
