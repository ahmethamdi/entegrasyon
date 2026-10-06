// Kullanım: node kaydet.mjs <bolum-adi>
// Etkin sekmenin ekranını CDP screencast ile kareler halinde kaydeder.
// SIGTERM/SIGINT gelince kare listesini (concat.txt) yazar ve çıkar.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const name = process.argv[2];
const dir = path.resolve('bolumler', name);
fs.rmSync(dir, { recursive: true, force: true });
fs.mkdirSync(dir, { recursive: true });

const browser = await chromium.connectOverCDP('http://127.0.0.1:9333');
const ctx = browser.contexts()[0];
const page = ctx.pages().find(p => p.url().includes('34pazar') || p.url().includes('shopify')) ?? ctx.pages()[0];
const cdp = await ctx.newCDPSession(page);
// Onay pencerelerine DOKUNMA: dinleyici yoksa Playwright onları kendisi kapatır ve
// sürücüyle yarışıp kaydediciyi çökertir (6 Eki, abonelik bölümü).
page.on('dialog', () => {});

// Panel dili tarayıcıdan gelir; bu profil Türkçe → kayıt boyunca İngilizce tarayıcı gibi davran.
const ua = await page.evaluate(() => navigator.userAgent);
await cdp.send('Network.enable');
await cdp.send('Network.setUserAgentOverride', { userAgent: ua, acceptLanguage: 'en-US,en;q=0.9', platform: 'MacIntel' });

const frames = [];
cdp.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
  const file = `f${String(frames.length).padStart(5, '0')}.jpg`;
  fs.writeFileSync(path.join(dir, file), Buffer.from(data, 'base64'));
  frames.push({ file, t: metadata.timestamp ?? Date.now() / 1000 });
  try { await cdp.send('Page.screencastFrameAck', { sessionId }); } catch {}
});
await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 85, maxWidth: 1600, maxHeight: 1000, everyNthFrame: 1 });
const start = Date.now() / 1000;
fs.writeFileSync(path.join(dir, 'pid'), String(process.pid));
console.log('kayıt başladı', name, page.url());

// Kareler yalnız ekran DEĞİŞİNCE gelir: her karenin süresi bir sonrakine kadardır.
const finish = () => {
  const end = Date.now() / 1000;
  const lines = ['ffconcat version 1.0'];
  frames.forEach((f, i) => {
    const next = i + 1 < frames.length ? frames[i + 1].t : end;
    lines.push(`file ${f.file}`, `duration ${Math.max(0.01, next - f.t).toFixed(3)}`);
  });
  if (frames.length) lines.push(`file ${frames.at(-1).file}`);
  fs.writeFileSync(path.join(dir, 'concat.txt'), lines.join('\n') + '\n');
  console.log('kayıt bitti', frames.length, 'kare', (end - start).toFixed(1), 'sn');
  process.exit(0);
};
process.on('SIGTERM', finish);
process.on('SIGINT', finish);
setInterval(() => {}, 1000);
