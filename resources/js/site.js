// Tanıtım sitesi — yalnız mobil menü. Panel JS'i (Vue) bu sayfalara YÜKLENMEZ.
//
// İLKE: JS çalışmasa da site eksiksiz okunur. Menü bağlantıları alt
// bilgide de var. Kaydırınca beliren animasyon BİLEREK yok (kurumsal
// tasarım kararı, 9 Ekim 2026): içerik ilk boyamada yerinde durur.

function setupMenu() {
    const button = document.querySelector('[data-menu-button]');
    const menu = document.getElementById('mobil-menu');

    if (!button || !menu) {
        return;
    }

    const iconOpen = button.querySelector('[data-icon-open]');
    const iconClose = button.querySelector('[data-icon-close]');

    function setOpen(open) {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');
        iconOpen?.toggleAttribute('hidden', open);
        iconClose?.toggleAttribute('hidden', !open);
        // Menü açıkken arkadaki sayfa kaymasın.
        document.documentElement.style.overflow = open ? 'hidden' : '';
    }

    button.addEventListener('click', () => setOpen(menu.hidden));

    // Escape kapatır ve odak düğmeye döner: klavye kullanıcısı menüde sıkışmasın.
    document.addEventListener('keydown', (event) => {
        if (!menu.hidden && event.key === 'Escape') {
            setOpen(false);
            button.focus();
        }
    });

    // Aynı sayfadaki çapaya (/#sss) gidilince menü açık kalmasın.
    menu.addEventListener('click', (event) => {
        if (event.target.closest('a')) {
            setOpen(false);
        }
    });

    // Ekran genişleyip masaüstü gezinmesi görününce menü kendiliğinden kapanır;
    // yoksa kilitli kaydırma geniş ekranda takılı kalırdı.
    window.matchMedia('(min-width: 64rem)').addEventListener('change', (query) => {
        if (query.matches && !menu.hidden) {
            setOpen(false);
        }
    });
}

setupMenu();
