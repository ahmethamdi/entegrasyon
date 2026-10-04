// Tanıtım sitesi — yalnız mobil menü ve kaydırınca belirme. Panel JS'i (Vue) bu sayfalara YÜKLENMEZ.
//
// İLKE: JS çalışmasa da site eksiksiz okunur. Menü bağlantıları alt
// bilgide de var; beliren öğeler varsayılan olarak GÖRÜNÜR ve yalnız bu
// dosya çalışınca, ekranın altında kalanlar geçici olarak gizlenir.

// ─────────────────────────────────────────────── mobil menü

function setupMenu() {
    const button = document.querySelector('[data-menu-button]');
    const menu = document.getElementById('mobil-menu');

    if (!button || !menu) {
        return;
    }

    const labelOpen = button.querySelector('[data-label-open]');
    const labelClose = button.querySelector('[data-label-close]');

    function setOpen(open) {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        labelOpen?.toggleAttribute('hidden', open);
        labelClose?.toggleAttribute('hidden', !open);
        // Menü açıkken arkadaki sayfa kaymasın; tam ekran panel zaten örtüyor.
        document.documentElement.style.overflow = open ? 'hidden' : '';

        if (open) {
            menu.querySelector('a')?.focus();
        }
    }

    button.addEventListener('click', () => setOpen(menu.hidden));

    document.addEventListener('keydown', (event) => {
        if (menu.hidden) {
            return;
        }

        // Escape kapatır ve odak düğmeye döner: klavye kullanıcısı menüde sıkışmasın.
        if (event.key === 'Escape') {
            setOpen(false);
            button.focus();

            return;
        }

        // Tab menü ile "Kapat" düğmesi arasında döner: tam ekran panelin
        // ARKASINDAKİ görünmeyen bağlantılara odak kaçmasın.
        if (event.key === 'Tab') {
            const items = [button, ...menu.querySelectorAll('a')];
            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
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

// ─────────────────────────────────────────────── kaydırınca belirme

function setupReveal() {
    const items = document.querySelectorAll('[data-reveal]');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!items.length || reduce || !('IntersectionObserver' in window)) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }

                const el = entry.target;
                el.classList.add('reveal-in');
                // Bir kare beklenir: geçiş sınıfı uygulanmadan gizleme kalkarsa animasyon atlanır.
                requestAnimationFrame(() => el.classList.remove('reveal-pending'));
                observer.unobserve(el);
            }
        },
        { rootMargin: '0px 0px -8% 0px', threshold: 0.08 },
    );

    const fold = window.innerHeight;

    for (const el of items) {
        // İlk ekranda görünen öğe GİZLENMEZ: yüklenirken yanıp sönmesin.
        if (el.getBoundingClientRect().top < fold) {
            continue;
        }

        el.classList.add('reveal-pending');
        observer.observe(el);
    }
}

setupMenu();
setupReveal();
