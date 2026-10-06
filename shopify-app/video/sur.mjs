// Kullanım: node sur.mjs '<page üzerinde çalışacak async kod>'
// Örn: node sur.mjs 'await page.goto("https://34pazar.com/panel")'
// Sonunda ekran görüntüsü: son.png
import { chromium } from 'playwright';

const browser = await chromium.connectOverCDP('http://127.0.0.1:9333');
const ctx = browser.contexts()[0];
const page = ctx.pages().find(p => p.url().includes('34pazar') || p.url().includes('shopify')) ?? ctx.pages()[0];
page.setDefaultTimeout(20000);

// Ekranda "yazıyor" gibi görünen yavaş yazma + imleçli tıklama
const type = async (sel, text) => { await page.locator(sel).first().click(); await page.keyboard.type(text, { delay: 60 }); };
const pause = ms => page.waitForTimeout(ms);

try {
  const fn = new Function('page', 'ctx', 'type', 'pause', `return (async () => { ${process.argv[2]} })()`);
  const out = await fn(page, ctx, type, pause);
  if (out !== undefined) console.log(typeof out === 'string' ? out : JSON.stringify(out, null, 1));
} catch (e) {
  console.log('HATA:', e.message.split('\n').slice(0, 6).join('\n'));
}
await page.screenshot({ path: 'son.png' }).catch(() => {});
console.log('URL:', page.url());
process.exit(0); // close() CDP Chrome'unu kapatabilir — yalnız kopar
