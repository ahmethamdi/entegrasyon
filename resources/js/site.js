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
    window.matchMedia('(min-width: 80rem)').addEventListener('change', (query) => {
        if (query.matches && !menu.hidden) {
            setOpen(false);
        }
    });
}

// Mega menü: düğme tıklamayla açar/kapatır (dokunmatik + klavye), Escape
// kapatır. Hover/odakla açılma CSS'te; JS yoksa da menü çalışır.
function setupMega() {
    const items = [...document.querySelectorAll('[data-mega]')];

    const closeAll = (except) => items.forEach((item) => {
        if (item !== except) {
            item.dataset.open = 'false';
            item.querySelector('[data-mega-button]')?.setAttribute('aria-expanded', 'false');
        }
    });

    items.forEach((item) => {
        const button = item.querySelector('[data-mega-button]');

        button?.addEventListener('click', () => {
            const open = item.dataset.open !== 'true';
            closeAll(item);
            item.dataset.open = open ? 'true' : 'false';
            // Tıklayarak kapatılan panel hover yüzünden açık kalmasın.
            item.dataset.closed = open ? 'false' : 'true';
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        item.addEventListener('mouseleave', () => {
            item.dataset.closed = 'false';
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            const open = items.find((item) => item.dataset.open === 'true');
            closeAll(null);
            open?.querySelector('[data-mega-button]')?.focus();
        }
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-mega]')) {
            closeAll(null);
        }
    });
}

setupMenu();
setupMega();
