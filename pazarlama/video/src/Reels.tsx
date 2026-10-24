// 34Pazar Türkçe reklam Reels'i (1080×1920, sessiz — ses Instagram'da eklenir).
// Kurallar: sahne başı ~3 sn, geçişler okunur yazıyla, başta kanca, sonda tek CTA,
// gölge/parıltı yok. Her iddia sitede doğru: 15 kanal, aylık 499 ₺'den, taahhüt yok,
// ücretsiz paket kartsız, ücretli pakette 14 gün iade, her gün WhatsApp destek.
// Panel sahneleri panelin görünümünde çizilmiş büyük kartlar (telefonda okunsun diye);
// ürün ve sipariş satırları demo mağazasının örnek verisinden (Atölye Nur, sahte veri).
// Yazı güvenli alanı: üst 250 px ve alt 350 px boş.

import React from "react";
import { AbsoluteFill, Easing, Img, Sequence, interpolate, staticFile, useCurrentFrame, useVideoConfig } from "remotion";
import { loadFont as loadArchivo } from "@remotion/google-fonts/Archivo";
import { loadFont as loadInter } from "@remotion/google-fonts/InterTight";

const { fontFamily: DISPLAY } = loadArchivo("normal", { weights: ["600", "800"], subsets: ["latin", "latin-ext"] });
const { fontFamily: SANS } = loadInter("normal", { weights: ["500", "600"], subsets: ["latin", "latin-ext"] });

const C = {
  navy: "#0b1b33",
  navyLine: "#22324d",
  brand: "#ce310d",
  ink: "#15171c",
  muted: "#5b606b",
  bg: "#ffffff",
  soft: "#f5f4f1",
  line: "#e3e2dd",
  ok: "#067647",
};

const SAFE_TOP = 250;
const SAFE_BOTTOM = 350;

// Sahne süreleri (sn). Toplam 24 sn.
const S = [3, 2.5, 3.5, 3, 3, 3, 3, 3] as const;
export const DURATION_REELS = S.reduce((a, b) => a + b, 0);

const ease = Easing.bezier(0.16, 1, 0.3, 1);

// Sahneye giriş: yukarı kayarak belirme; çıkış: kısa solma.
const useEnter = (delay = 0) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const t = interpolate(frame - delay * fps, [0, 0.5 * fps], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp", easing: ease });
  return { opacity: t, translate: `0 ${(1 - t) * 40}px` };
};

const SceneFade: React.FC<{ children: React.ReactNode; bg: string }> = ({ children, bg }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();
  const out = interpolate(frame, [durationInFrames - 0.25 * fps, durationInFrames], [1, 0], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  return (
    <AbsoluteFill style={{ background: bg }}>
      <AbsoluteFill style={{ opacity: out, paddingTop: SAFE_TOP, paddingBottom: SAFE_BOTTOM, paddingLeft: 72, paddingRight: 72, justifyContent: "center" }}>{children}</AbsoluteFill>
    </AbsoluteFill>
  );
};

const H: React.FC<{ children: React.ReactNode; color?: string; size?: number; delay?: number; style?: React.CSSProperties }> = ({ children, color = C.ink, size = 96, delay = 0, style }) => (
  <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: size, lineHeight: 1.08, letterSpacing: "-0.02em", color, ...useEnter(delay), ...style }}>{children}</div>
);

const P: React.FC<{ children: React.ReactNode; color?: string; size?: number; delay?: number; style?: React.CSSProperties }> = ({ children, color = C.muted, size = 48, delay = 0, style }) => (
  <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: size, lineHeight: 1.3, color, ...useEnter(delay), ...style }}>{children}</div>
);

// 1 · Kanca: aynı ürün, iki kanalda iki farklı stok.
const Hook: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const sold = frame > 1.1 * fps;
  return (
    <SceneFade bg={C.navy}>
      <H color="#fff" size={104}>Trendyol'da sattın,</H>
      <H color="#fff" size={104} delay={0.35} style={{ marginTop: 8 }}>
        Hepsiburada'da stok <span style={{ color: "#ff8a6b" }}>hâlâ 1</span> mi?
      </H>
      <div style={{ display: "flex", gap: 28, marginTop: 90, ...useEnter(0.7) }}>
        {[
          ["Trendyol", sold ? "0" : "1", sold],
          ["Hepsiburada", "1", false],
        ].map(([name, stock, done]) => (
          <div key={String(name)} style={{ flex: 1, border: `2px solid ${C.navyLine}`, borderRadius: 24, padding: "36px 40px" }}>
            <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 40, color: "#c9d2e0" }}>{name}</div>
            <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 120, color: done ? "#fff" : "#ff8a6b", marginTop: 8 }}>{stock}</div>
            <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 34, color: "#93a0b5" }}>stok</div>
          </div>
        ))}
      </div>
    </SceneFade>
  );
};

// 2 · Derdin adı.
const Problem: React.FC = () => (
  <SceneFade bg={C.navy}>
    <div style={{ display: "flex", flexDirection: "column", justifyContent: "center", height: "100%" }}>
      <H color="#fff" size={112}>Elinde olmayan ürünü satarsın.</H>
      <P color="#c9d2e0" size={52} delay={0.4} style={{ marginTop: 40 }}>
        Sonra iptal, özür ve kötü yorum.
      </P>
    </div>
  </SceneFade>
);

// Kanal satırı: logo + ad (panelin kanal rozetine benzer, büyük ve okunur).
const Logo: React.FC<{ file: string | null; size?: number }> = ({ file, size = 56 }) =>
  file ? (
    <Img src={staticFile(`img/channels/icon/${file}`)} style={{ width: size, height: size, objectFit: "contain", flexShrink: 0 }} />
  ) : (
    <div style={{ width: size, height: size, borderRadius: 12, background: C.ink, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 800, fontSize: size * 0.57, flexShrink: 0 }}>T</div>
  );

// 3 · Çözüm: tek stok. Ürün demo mağazasının örnek verisinden (Seramik Kupa, 62 stok).
const OneStock: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const sold = frame > 1.5 * fps;
  const rows: [string, string, boolean][] = [
    ["Trendyol", "trendyol.png", true],
    ["Hepsiburada", "hepsiburada.png", false],
    ["Shopify", "shopify.svg", false],
  ];
  return (
    <SceneFade bg={C.bg}>
      <P color={C.brand} size={44} style={{ fontWeight: 600 }}>34Pazar ile</P>
      <H size={92} style={{ marginTop: 12 }}>Bir kanalda satılır, stok her kanalda düşer.</H>
      <div style={{ marginTop: 60, border: `2px solid ${C.line}`, borderRadius: 28, padding: "36px 40px", ...useEnter(0.4) }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
          <div>
            <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 44, color: C.ink }}>Seramik Kupa 350 ml</div>
            <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 32, color: C.muted, marginTop: 4 }}>KUP-350-BEYAZ</div>
          </div>
          <div style={{ textAlign: "right" }}>
            <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 88, color: C.ink, lineHeight: 1 }}>{sold ? 61 : 62}</div>
            <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 30, color: C.muted }}>stok</div>
          </div>
        </div>
        <div style={{ marginTop: 28, display: "flex", flexDirection: "column", gap: 16 }}>
          {rows.map(([name, file, origin], i) => {
            const t = interpolate(frame - (1.5 + 0.2 * i) * fps, [0, 0.3 * fps], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
            return (
              <div key={name} style={{ display: "flex", alignItems: "center", gap: 20, borderTop: `2px solid ${C.line}`, paddingTop: 16 }}>
                <Logo file={file} size={52} />
                <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 38, color: C.ink, flex: 1 }}>{name}</div>
                {origin && sold ? <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 28, color: C.brand, background: "#fbe3dc", borderRadius: 999, padding: "6px 18px" }}>1 satıldı</div> : null}
                <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 44, color: t > 0.5 ? C.ok : C.ink, minWidth: 140, textAlign: "right" }}>
                  {t > 0.5 ? "✓ 61" : "62"}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </SceneFade>
  );
};

const CHANNELS: [string, string | null][] = [
  ["Trendyol", "trendyol.png"], ["Hepsiburada", "hepsiburada.png"], ["N11", "n11.png"],
  ["Çiçeksepeti", "ciceksepeti.png"], ["Pazarama", "pazarama.png"], ["İdefix", "idefix.png"],
  ["Amazon", "amazon.png"], ["eBay", "ebay.png"], ["Etsy", "etsy.svg"],
  ["Kaufland", "kaufland.svg"], ["OTTO Market", "otto.svg"], ["Shopify", "shopify.svg"],
  ["WooCommerce", "woocommerce.svg"], ["ikas", "ikas.png"], ["Ticimax", null],
];

// 4 · Kanallar.
const Channels: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  return (
    <SceneFade bg={C.soft}>
      <H size={100}>15 kanal.</H>
      <H size={100} color={C.brand} delay={0.25}>Her pakette hepsi.</H>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 20, marginTop: 56 }}>
        {CHANNELS.map(([name, file], i) => {
          const t = interpolate(frame - (0.5 + i * 0.05) * fps, [0, 0.35 * fps], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp", easing: ease });
          return (
            <div key={name} style={{ opacity: t, translate: `0 ${(1 - t) * 20}px`, display: "flex", alignItems: "center", gap: 18, background: "#fff", border: `2px solid ${C.line}`, borderRadius: 18, padding: "22px 20px", height: 112, boxSizing: "border-box" }}>
              <Logo file={file} />
              <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: name.length > 10 ? 27 : 32, color: C.ink, lineHeight: 1.1 }}>{name}</div>
            </div>
          );
        })}
      </div>
    </SceneFade>
  );
};

// 5 · Siparişler. Satırlar demo mağazasının örnek siparişleri.
const ORDERS: [string, string, string, string, string, boolean][] = [
  ["10284571", "Trendyol", "trendyol.png", "₺379,80", "Kargo bekliyor", false],
  ["#1047", "Shopify", "shopify.svg", "₺899,00", "Kargo bekliyor", false],
  ["T-2231", "WooCommerce", "woocommerce.svg", "₺987,00", "Kargo bekliyor", false],
  ["#1043", "Shopify", "shopify.svg", "₺249,00", "Kargoda", true],
];
const Orders: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  return (
    <SceneFade bg={C.bg}>
      <H size={92}>Bütün siparişler tek listede.</H>
      <P size={46} delay={0.3} style={{ marginTop: 24 }}>Kargo takibi ve kanal başına fiyat kuralı da aynı panelde.</P>
      <div style={{ marginTop: 56, border: `2px solid ${C.line}`, borderRadius: 28, overflow: "hidden" }}>
        {ORDERS.map(([no, ch, file, amount, status, shipped], i) => {
          const t = interpolate(frame - (0.5 + i * 0.15) * fps, [0, 0.35 * fps], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp", easing: ease });
          return (
            <div key={no} style={{ opacity: t, translate: `0 ${(1 - t) * 20}px`, display: "flex", alignItems: "center", gap: 22, padding: "26px 32px", borderTop: i ? `2px solid ${C.line}` : "none" }}>
              <Logo file={file} size={56} />
              <div style={{ flex: 1 }}>
                <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 38, color: C.ink }}>{no}</div>
                <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 30, color: C.muted }}>{ch}</div>
              </div>
              <div style={{ textAlign: "right" }}>
                <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 36, color: C.ink }}>{amount}</div>
                <div style={{ display: "inline-block", marginTop: 6, fontFamily: SANS, fontWeight: 600, fontSize: 26, borderRadius: 999, padding: "4px 16px", color: shipped ? C.ok : "#8a5a00", background: shipped ? "#e3f4ea" : "#fdf1d8" }}>{status}</div>
              </div>
            </div>
          );
        })}
      </div>
    </SceneFade>
  );
};

// 6 · Fiyat.
const Price: React.FC = () => (
  <SceneFade bg={C.navy}>
    <div style={{ display: "flex", flexDirection: "column", justifyContent: "center", height: "100%" }}>
      <P color="#c9d2e0" size={52}>Aylık</P>
      <H color="#fff" size={200} delay={0.15} style={{ marginTop: 4 }}>499 ₺'den</H>
      <div style={{ marginTop: 56, display: "flex", flexDirection: "column", gap: 20 }}>
        <P color="#fff" size={56} delay={0.5} style={{ fontWeight: 600 }}>Yıllık peşin ödeme yok.</P>
        <P color="#fff" size={56} delay={0.75} style={{ fontWeight: 600 }}>Taahhüt yok.</P>
      </div>
    </div>
  </SceneFade>
);

// 7 · Güven.
const Trust: React.FC = () => (
  <SceneFade bg={C.bg}>
    <div style={{ display: "flex", flexDirection: "column", justifyContent: "center", height: "100%" }}>
      <H size={96}>Rahat başla.</H>
      <div style={{ marginTop: 64, display: "flex", flexDirection: "column", gap: 28 }}>
        {[
          ["Ücretsiz paket, kart gerekmez", ""],
          ["14 gün içinde tam iade", "ücretli paketlerde"],
          ["Her gün WhatsApp destek", "09.00–18.00"],
        ].map(([a, b], i) => (
          <div key={a} style={{ display: "flex", alignItems: "flex-start", gap: 28, borderTop: `2px solid ${C.line}`, paddingTop: 28, ...useEnter(0.3 + i * 0.25) }}>
            <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 64, color: C.ok, lineHeight: 1 }}>✓</div>
            <div>
              <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56, color: C.ink, lineHeight: 1.15 }}>{a}</div>
              {b ? <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 38, color: C.muted, marginTop: 6 }}>{b}</div> : null}
            </div>
          </div>
        ))}
      </div>
    </div>
  </SceneFade>
);

// 8 · Tek CTA.
const Cta: React.FC = () => (
  <AbsoluteFill style={{ background: C.navy, paddingTop: SAFE_TOP, paddingBottom: SAFE_BOTTOM, paddingLeft: 72, paddingRight: 72 }}>
    <div style={{ display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", height: "100%", textAlign: "center" }}>
      <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 640, ...useEnter(0) }} />
      <P color="#c9d2e0" size={50} delay={0.3} style={{ marginTop: 56 }}>Stoğunu tek yerden yönet.</P>
      <div style={{ marginTop: 72, background: C.brand, color: "#fff", fontFamily: DISPLAY, fontWeight: 800, fontSize: 64, padding: "34px 64px", borderRadius: 999, ...useEnter(0.55) }}>
        Ücretsiz başla
      </div>
      <div style={{ marginTop: 40, fontFamily: SANS, fontWeight: 600, fontSize: 56, color: "#fff", ...useEnter(0.75) }}>34pazar.com</div>
    </div>
  </AbsoluteFill>
);

const SCENES = [Hook, Problem, OneStock, Channels, Orders, Price, Trust, Cta];

export const PazarReels: React.FC = () => {
  const { fps } = useVideoConfig();
  let from = 0;
  return (
    <AbsoluteFill style={{ background: C.navy }}>
      {SCENES.map((Scene, i) => {
        const start = from;
        from += S[i];
        return (
          <Sequence key={i} from={Math.round(start * fps)} durationInFrames={Math.round(S[i] * fps)} premountFor={fps}>
            <Scene />
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};

// Kapak: okunur başlık + fiyat, ilk kare değil.
export const PazarCover: React.FC = () => (
  <AbsoluteFill style={{ background: C.navy, paddingTop: SAFE_TOP, paddingBottom: SAFE_BOTTOM, paddingLeft: 72, paddingRight: 72 }}>
    <div style={{ display: "flex", flexDirection: "column", justifyContent: "center", height: "100%" }}>
      <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 420 }} />
      <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 124, lineHeight: 1.05, letterSpacing: "-0.02em", color: "#fff", marginTop: 80 }}>
        Tek stok.
        <br />
        <span style={{ color: "#ff8a6b" }}>Bütün kanallar.</span>
      </div>
      <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56, color: "#c9d2e0", marginTop: 56 }}>Aylık 499 ₺'den · taahhüt yok</div>
    </div>
  </AbsoluteFill>
);
