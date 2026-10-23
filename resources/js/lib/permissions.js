import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/*
 * S7 · rol yetkisi — düğme ve formlar için YALNIZ GÖRÜNÜM.
 *
 * Kaynak `HandleInertiaRequests`'in paylaştığı `permissions` listesidir
 * (rolün `Permission` değerleri); burada ikinci bir rol tablosu YOK. Yetkinin
 * kendisi sunucuda `AuthorizeTenantRoute`'tadır: gizlenen düğmenin isteği
 * elle atılırsa yine 403 döner. Gizlemenin amacı depo/salt okunur üyenin
 * tıklayıp hata ekranı görmemesidir.
 *
 * Metinler `Permission` enum değerleriyle AYNI olmalı —
 * `PanelPermissionStringsContractTest` bilinmeyen değeri yakalar; yanlış
 * yazılmış bir değer düğmeyi sahibe bile sessizce gizlerdi.
 */
export function usePermissions() {
    const page = usePage();
    const granted = computed(() => page.props.permissions ?? []);

    const can = (permission) => granted.value.includes(permission);

    return { can };
}
