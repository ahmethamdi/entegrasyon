#!/bin/sh
# S13 · deploy öncesi elle alınan veritabanı dökümlerinin temizliği.
#
#   ./docker/prod/prune-deploy-backups.sh [klasör] [en-az-tutulan] [gün]
#   varsayılan: /var/backups 10 14
#
# Yalnız `oncesi-*.dump` (deploy öncesi yedekler) dokunulur; gece yedeği
# (`34pazar-YYYY-MM-DD.dump`, sunucu cron'u kendi 14 gününü siler) ve diğer
# dosyalar ASLA silinmez. Kural: en yeni N dosya + son G günde değişen her
# dosya kalır, gerisi silinir. 24 Ekim 2026'da 49 döküm (69 MB) birikmişti.
set -eu

DIR="${1:-/var/backups}"
KEEP="${2:-10}"
DAYS="${3:-14}"

[ -d "$DIR" ] || { echo "klasör yok: $DIR" >&2; exit 1; }

deleted=0
# En yeni önce; ilk KEEP dosya korunur.
for file in $(ls -1t "$DIR"/oncesi-*.dump 2>/dev/null | tail -n +"$((KEEP + 1))"); do
    # Son DAYS günde değiştiyse korunur.
    if [ -n "$(find "$file" -mtime -"$DAYS" 2>/dev/null)" ]; then
        continue
    fi
    rm -f -- "$file"
    deleted=$((deleted + 1))
done

echo "deploy yedekleri: ${deleted} silindi (en yeni ${KEEP} + son ${DAYS} gün tutuldu)"
