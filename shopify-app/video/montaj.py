# Bölümleri altyazılı tek videoya birleştirir: cikti/screencast.mp4
# Her karenin süresi MAX_HOLD ile kırpılır (sürücünün bekleme anları atılır).
import os, re, glob, subprocess, shutil
from PIL import Image, ImageDraw, ImageFont

W, H = 1600, 832
MAX_HOLD = 3.0
FIRST_HOLD = 1.5
FONT = '/System/Library/Fonts/Supplemental/Arial.ttf'
FONT_B = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'

SEGMENTS = [
    ('01-kaldir',   None, 'Step 1/10', 'Starting clean: 34Pazar is uninstalled from the test store.'),
    ('02-kur',      85,   'Step 2/10', 'Installing from Shopify. The merchant reviews and approves the requested access (OAuth).'),
    ('03-giris',    None, 'Step 3/10', 'After OAuth the merchant signs up or logs in. No store URL is asked - the store connects automatically.'),
    ('04-import',   None, 'Step 4/10', 'Bulk import pulls the products from Shopify. The free plan limit (25 products) is enforced.'),
    ('05-stok',     None, 'Step 5/10', 'Stock is counted in 34Pazar (50 -> 42) and pushed to the Shopify location.'),
    ('06-siparis',  None, 'Step 6/10', 'A test order is created in Shopify (draft order marked as paid).'),
    ('07-kargo',    None, 'Step 7/10', 'The order arrives by webhook and stock drops to 41. "Mark as shipped" sends the tracking number to Shopify.'),
    ('08-abonelik', None, 'Step 8/10', 'Paid plans use Shopify Billing: the merchant approves the charge in Shopify (test charge on a dev store).'),
    ('09-ucretsiz', None, 'Step 9/10', 'Switching back to the free plan cancels the Shopify subscription.'),
    ('10-son',      None, 'Step 10/10', 'On uninstall, access is revoked and the connection is closed.'),
]

font = ImageFont.truetype(FONT, 26)
font_s = ImageFont.truetype(FONT_B, 20)


def wrap(text, f, width):
    words, lines, cur = text.split(), [], ''
    for w in words:
        t = (cur + ' ' + w).strip()
        if f.getlength(t) <= width:
            cur = t
        else:
            lines.append(cur); cur = w
    lines.append(cur)
    return lines


def captioned(src, dst, step, text):
    im = Image.open(src).convert('RGB')
    if im.size != (W, H):
        im = im.resize((W, H))
    lines = wrap(text, font, W - 260)
    bar_h = 26 + 34 * len(lines) + 10
    ov = Image.new('RGBA', (W, bar_h), (17, 17, 17, 225))
    d = ImageDraw.Draw(ov)
    d.rounded_rectangle((24, 18, 24 + font_s.getlength(step) + 24, 18 + 30), 8, fill=(206, 49, 13, 255))
    d.text((36, 22), step, font=font_s, fill='white')
    for i, ln in enumerate(lines):
        d.text((24 + font_s.getlength(step) + 48, 18 + i * 34), ln, font=font, fill='white')
    im.paste(ov, (0, H - bar_h), ov)
    im.save(dst, quality=92)


def card(dst, title, sub_lines):
    im = Image.new('RGB', (W, H), (17, 17, 17))
    d = ImageDraw.Draw(im)
    logo = '/Applications/XAMPP/xamppfiles/htdocs/Entegrasyon/public/images/34pazar-logo.png'
    y = 250
    if os.path.exists(logo):
        lg = Image.open(logo).convert('RGBA')
        lg.thumbnail((420, 120))
        bg = Image.new('RGBA', (lg.width + 60, lg.height + 40), (255, 255, 255, 255))
        bg.paste(lg, (30, 20), lg)
        im.paste(bg, ((W - bg.width) // 2, 150))
        y = 150 + bg.height + 50
    tf = ImageFont.truetype(FONT_B, 48)
    d.text(((W - tf.getlength(title)) // 2, y), title, font=tf, fill='white')
    sf = ImageFont.truetype(FONT, 28)
    for i, s in enumerate(sub_lines):
        d.text(((W - sf.getlength(s)) // 2, y + 90 + i * 46), s, font=sf, fill=(200, 200, 200))
    im.save(dst, quality=92)


out = 'cikti'
shutil.rmtree(out, ignore_errors=True)
os.makedirs(out + '/kare')
entries = []  # (dosya, süre)

card(f'{out}/kare/baslik.jpg', '34Pazar - App review walkthrough',
     ['Multichannel stock and order sync for Shopify merchants',
      'Test store: 34pazar-test.myshopify.com  ·  English captions'])
entries.append((f'kare/baslik.jpg', 4.0))

n = 0
for seg, cut, step, text in SEGMENTS:
    raw = open(f'bolumler/{seg}/concat.txt').read()
    pairs = re.findall(r'file (\S+)\nduration ([\d.]+)', raw)
    if cut is not None:
        pairs = [p for p in pairs if int(p[0][1:6]) <= cut]
    # Neredeyse aynı ardışık kareleri birleştir (yanıp sönen imleç, bekleme):
    # süre önceki kareye eklenir, sonra MAX_HOLD ile kırpılır.
    from PIL import ImageChops, ImageStat
    merged, prev = [], None
    for f, dur in pairs:
        small = Image.open(f'bolumler/{seg}/{f}').convert('L').resize((200, 104))
        if prev is not None and ImageStat.Stat(ImageChops.difference(small, prev)).mean[0] < 0.15:
            merged[-1][1] = str(float(merged[-1][1]) + float(dur))
            continue
        merged.append([f, dur]); prev = small
    pairs = merged
    seg_total = 0
    for i, (f, dur) in enumerate(pairs):
        dur = min(float(dur), MAX_HOLD)
        if i == 0:
            dur = max(dur, FIRST_HOLD)
        if i == len(pairs) - 1:
            dur = max(dur, 2.5)  # bölüm sonu: sonuç okunabilsin
        if dur < 1 / 30:
            continue
        name = f'kare/{n:06d}.jpg'
        captioned(f'bolumler/{seg}/{f}', f'{out}/{name}', step, text)
        entries.append((name, dur)); n += 1; seg_total += dur
    print(f'{seg}: {len(pairs)} kare, {seg_total:.1f} sn')

card(f'{out}/kare/son.jpg', 'Thank you for reviewing 34Pazar',
     ['Support: info@34devs.com', 'Help: https://34pazar.com/en/getting-started'])
entries.append(('kare/son.jpg', 4.0))

with open(f'{out}/liste.txt', 'w') as fh:
    fh.write('ffconcat version 1.0\n')
    for f, dur in entries:
        fh.write(f'file {f}\nduration {dur:.3f}\n')
    fh.write(f'file {entries[-1][0]}\n')

subprocess.run(['ffmpeg', '-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', f'{out}/liste.txt',
                '-vf', 'fps=30,format=yuv420p', '-c:v', 'libx264', '-preset', 'slow', '-crf', '22',
                '-movflags', '+faststart', f'{out}/screencast.mp4'], check=True)
print('toplam', sum(d for _, d in entries), 'sn')
