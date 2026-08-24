#!/usr/bin/env bash
#
# V3.0 dokümanını PDF'e çevirir.
#
# KAYNAK `docs/ENTEGRASYON-V3.0.md`'DİR — PDF bir ÇIKTIDIR.
# PDF elle düzeltilirse bu betiğin bir sonraki çalıştırması düzeltmeyi
# SESSİZCE geri alır. Değişiklik her zaman Markdown'da yapılır.
#
# Araç zinciri: pandoc + Chrome headless.
# weasyprint / wkhtmltopdf / LaTeX bu makinede YOK ve GEREKMİYOR.
#
# Kullanım:  docs/pdf/build-v3.sh
# Çıktı:     ~/Desktop/Entegrasyon-Mimari-v3.0.pdf

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SRC="$ROOT/docs/ENTEGRASYON-V3.0.md"
ASSETS="$ROOT/docs/pdf"
OUT="${1:-$HOME/Desktop/Entegrasyon-Mimari-v3.0.pdf}"
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

[ -f "$SRC" ]   || { echo "HATA: $SRC yok"; exit 1; }
command -v pandoc >/dev/null || { echo "HATA: pandoc kurulu değil (brew install pandoc)"; exit 1; }
[ -x "$CHROME" ] || { echo "HATA: Chrome bulunamadı: $CHROME"; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# İlk 7 satır (başlık + meta bloğu) KAPAĞA gitti; gövdeden çıkarılır.
# Markdown'ın başı değişirse bu sayı da güncellenmelidir.
tail -n +8 "$SRC" > "$WORK/body.md"

pandoc "$WORK/body.md" -f gfm -t html5 -o "$WORK/body.html"

{
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8">'
    echo '<title>Entegrasyon V3.0</title><style>'
    cat "$ASSETS/v3.css"
    echo '</style></head><body>'
    cat "$ASSETS/cover.html"
    cat "$WORK/body.html"
    echo '</body></html>'
} > "$WORK/v3.html"

"$CHROME" --headless --disable-gpu --no-pdf-header-footer \
    --print-to-pdf="$WORK/v3.pdf" "file://$WORK/v3.html" 2>/dev/null

cp "$WORK/v3.pdf" "$OUT"

PAGES=$(python3 -c "
import re,sys
d=open('$OUT','rb').read()
print(len(re.findall(rb'/Type\s*/Page[^s]', d)))
")

echo "✅ $OUT"
echo "   $PAGES sayfa · $(du -h "$OUT" | cut -f1)"
