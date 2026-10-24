// 34Pazar Türkçe reklam v2 — "şov" sürümü. Şov hareketle ve kurguyla: kinetik yazı,
// yörüngedeki kanallar, sayan rakamlar, 3B eğik panel kartı, maskeli geçişler.
// Yasaklar sürüyor: gölge, parıltı, blob, kayan bant YOK. Sessiz (ses Instagram'da).
// İddialar sitede doğru: 15 kanal, aylık 499 ₺'den, yıllık peşin ve taahhüt yok,
// ücretsiz paket kartsız, ücretli pakette 14 gün iade, her gün WhatsApp destek,
// e-fatura Nilvera ile, set ürün stoğu bileşenlerden (en az olana göre).
// Ürün/sipariş satırları demo mağazasının örnek verisi (Atölye Nur, sahte veri).
// Aynı bileşen 1080×1920 (Reels) ve 1080×1080 (akış) kadrajında çalışır.

import React from "react";
import { AbsoluteFill, Easing, Img, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from "remotion";
import { loadFont as loadArchivo } from "@remotion/google-fonts/Archivo";
import { loadFont as loadInter } from "@remotion/google-fonts/InterTight";

const { fontFamily: DISPLAY } = loadArchivo("normal", { weights: ["600", "800", "900"], subsets: ["latin", "latin-ext"] });
const { fontFamily: SANS } = loadInter("normal", { weights: ["500", "600"], subsets: ["latin", "latin-ext"] });

const C = {
  night: "#070d18",
  navy: "#0b1b33",
  navyLine: "#22324d",
  brand: "#ce310d",
  coral: "#ff8a6b",
  ink: "#15171c",
  muted: "#5b606b",
  pale: "#c9d2e0",
  bg: "#ffffff",
  soft: "#f5f4f1",
  line: "#e3e2dd",
  ok: "#067647",
  okSoft: "#e3f4ea",
};

// Sahne süreleri (sn). Toplam 29 sn.
const S = [4.5, 2.5, 4, 2.5, 3, 3.5, 3.5, 2.5, 3] as const;
export const DURATION_V2 = S.reduce((a, b) => a + b, 0);

const ease = Easing.bezier(0.16, 1, 0.3, 1);
const clamp = { extrapolateLeft: "clamp", extrapolateRight: "clamp" } as const;

// Kadraj: dikeyde IG güvenli alanı (üst 250, alt 350), karede dar kenar.
const useLayout = () => {
  const { width, height } = useVideoConfig();
  const square = height <= width;
  return {
    square,
    k: square ? 0.78 : 1,
    padTop: square ? 90 : 250,
    padBottom: square ? 90 : 350,
    padX: square ? 80 : 72,
    width,
    height,
  };
};

const useSec = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  return frame / fps;
};

const useSpring = (delay: number, damping = 14) => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  return spring({ frame: frame - delay * fps, fps, config: { damping, mass: 0.6 } });
};

const enter = (t: number, dist = 40) => ({ opacity: t, translate: `0 ${(1 - t) * dist}px` });

// Sahne kabı: girişte soldan sağa maskeli silme, çıkışta kısa solma.
const Scene: React.FC<{ bg: string; children: React.ReactNode; wipe?: boolean; center?: boolean }> = ({ bg, children, wipe = true, center = true }) => {
  const frame = useCurrentFrame();
  const { fps, durationInFrames } = useVideoConfig();
  const L = useLayout();
  const w = wipe ? interpolate(frame, [0, 0.35 * fps], [100, 0], { ...clamp, easing: ease }) : 0;
  const out = interpolate(frame, [durationInFrames - 0.2 * fps, durationInFrames], [1, 0], clamp);
  return (
    <AbsoluteFill style={{ background: bg, clipPath: `inset(0 ${w}% 0 0)` }}>
      <AbsoluteFill style={{ opacity: out, paddingTop: L.padTop, paddingBottom: L.padBottom, paddingLeft: L.padX, paddingRight: L.padX, justifyContent: center ? "center" : "flex-start" }}>
        {children}
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

const Logo: React.FC<{ file: string | null; size: number }> = ({ file, size }) =>
  file ? (
    <Img src={staticFile(`img/channels/icon/${file}`)} style={{ width: size, height: size, objectFit: "contain", flexShrink: 0 }} />
  ) : (
    <div style={{ width: size, height: size, borderRadius: size * 0.22, background: C.ink, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 800, fontSize: size * 0.57, flexShrink: 0 }}>T</div>
  );

const CHANNELS: [string, string | null][] = [
  ["Trendyol", "trendyol.png"], ["Hepsiburada", "hepsiburada.png"], ["N11", "n11.png"],
  ["Çiçeksepeti", "ciceksepeti.png"], ["Pazarama", "pazarama.png"], ["İdefix", "idefix.png"],
  ["Amazon", "amazon.png"], ["eBay", "ebay.png"], ["Etsy", "etsy.svg"],
  ["Kaufland", "kaufland.svg"], ["OTTO", "otto.svg"], ["Shopify", "shopify.svg"],
  ["WooCommerce", "woocommerce.svg"], ["ikas", "ikas.png"], ["Ticimax", null],
];

// ── 1 · Bildirim yağmuru → kinetik kanca ─────────────────────────────────────
const NOTES: [string, string, string][] = [
  ["Yeni sipariş", "Trendyol", "trendyol.png"],
  ["Yeni sipariş", "Hepsiburada", "hepsiburada.png"],
  ["Stok: 1", "Seramik Kupa", ""],
  ["Yeni sipariş", "N11", "n11.png"],
  ["Yeni sipariş", "Shopify", "shopify.svg"],
  ["Stok: 1", "Seramik Kupa", ""],
  ["Yeni sipariş", "Amazon", "amazon.png"],
  ["Yeni sipariş", "Çiçeksepeti", "ciceksepeti.png"],
];
const HOOK_WORDS = ["Trendyol'da", "sattın,", "Hepsiburada'da", "stok", "hâlâ 1", "mi?"];

const Hook: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const t = useSec();
  const L = useLayout();
  const freeze = 2.0;
  const dim = interpolate(t, [freeze, freeze + 0.4], [1, 0.16], clamp);
  const noteW = L.square ? 700 : 860;
  return (
    <AbsoluteFill style={{ background: C.night }}>
      {/* Bildirimler üst üste birikir, sonra donar ve kararır. */}
      <AbsoluteFill style={{ alignItems: "center", justifyContent: "center", opacity: dim }}>
        {NOTES.map(([a, b, file], i) => {
          const start = 0.05 + i * 0.17;
          const s = spring({ frame: Math.min(frame, freeze * fps) - start * fps, fps, config: { damping: 15, mass: 0.5 } });
          const stock = a.startsWith("Stok");
          return (
            <div
              key={i}
              style={{
                width: noteW,
                marginTop: i ? (L.square ? -6 : 4) : 0,
                opacity: s,
                translate: `0 ${(1 - s) * -80}px`,
                scale: String(0.92 + 0.08 * s),
                display: "flex",
                alignItems: "center",
                gap: 22 * L.k,
                padding: `${20 * L.k}px ${28 * L.k}px`,
                borderRadius: 26 * L.k,
                background: stock ? "#3a1410" : "#16233a",
                border: `2px solid ${stock ? "#6b2418" : C.navyLine}`,
              }}
            >
              {file ? <Logo file={file} size={52 * L.k} /> : <div style={{ width: 52 * L.k, height: 52 * L.k, borderRadius: 12, background: C.brand, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 900, fontSize: 34 * L.k }}>!</div>}
              <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 38 * L.k, color: stock ? C.coral : "#fff" }}>{a}</div>
              <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 34 * L.k, color: C.pale, marginLeft: "auto" }}>{b}</div>
            </div>
          );
        })}
      </AbsoluteFill>
      {/* Kanca: kelime kelime. */}
      <AbsoluteFill style={{ justifyContent: "center", paddingLeft: L.padX, paddingRight: L.padX, paddingTop: L.padTop, paddingBottom: L.padBottom }}>
        <div style={{ display: "flex", flexWrap: "wrap", columnGap: 26 * L.k, rowGap: 0 }}>
          {HOOK_WORDS.map((w, i) => {
            const s = spring({ frame: frame - (freeze + 0.25 + i * 0.22) * fps, fps, config: { damping: 12, mass: 0.6 } });
            const hot = w.startsWith("hâlâ");
            return (
              <span key={w} style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: (hot ? 150 : 112) * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: hot ? C.coral : "#fff", opacity: Math.min(1, s * 1.4), scale: String(0.6 + 0.4 * s), translate: `0 ${(1 - s) * 50}px`, display: "inline-block", transformOrigin: "left bottom" }}>
                {w}
              </span>
            );
          })}
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

// ── 2 · Dert: üstü çizilen sipariş ───────────────────────────────────────────
const Problem: React.FC = () => {
  const t = useSec();
  const L = useLayout();
  const a = useSpring(0.1);
  const strike = interpolate(t, [0.9, 1.4], [0, 100], { ...clamp, easing: ease });
  const b = useSpring(1.3);
  return (
    <Scene bg={C.navy}>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 120 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: "#fff", ...enter(a) }}>
        Elinde olmayan ürünü <span style={{ color: C.coral }}>satarsın.</span>
      </div>
      <div style={{ marginTop: 56 * L.k, position: "relative", display: "inline-block", alignSelf: "flex-start" }}>
        <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56 * L.k, color: C.pale, ...enter(a) }}>Sipariş #10284571 · Seramik Kupa</div>
        <div style={{ position: "absolute", left: 0, top: "52%", height: 8 * L.k, width: `${strike}%`, background: C.coral, borderRadius: 4 }} />
      </div>
      <div style={{ marginTop: 28 * L.k, fontFamily: SANS, fontWeight: 600, fontSize: 52 * L.k, color: C.coral, ...enter(b) }}>İptal. Özür. Kötü yorum.</div>
    </Scene>
  );
};

// ── 3 · Vay anı: merkezden 15 kanala akan satış ──────────────────────────────
const Orbit: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const t = useSec();
  const L = useLayout();
  const head = useSpring(0.05);
  // Yörünge merkezi ve yarıçapları (dikeyde elips, karede daire).
  const cx = L.width / 2;
  const cy = L.square ? 655 : 1080;
  const rx = L.square ? 380 : 410;
  const ry = L.square ? 295 : 470;
  const sale = 1.3; // Trendyol'da satış anı
  const node = 92 * L.k;
  const nodes = CHANNELS.map(([name, file], i) => {
    const a = -Math.PI / 2 + (i / CHANNELS.length) * Math.PI * 2;
    return { name, file, x: cx + rx * Math.cos(a), y: cy + ry * Math.sin(a), i };
  });
  const coreIn = useSpring(0.15, 12);
  return (
    <Scene bg={C.navy} center={false}>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 92 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: "#fff", textAlign: "center", ...enter(head) }}>
        Bir satış. <span style={{ color: C.coral }}>Bütün kanallarda düşer.</span>
      </div>
      <AbsoluteFill>
        <svg width={L.width} height={L.height} style={{ position: "absolute", inset: 0 }}>
          {nodes.map((n) => {
            const delay = sale + 0.15 + n.i * 0.04;
            const p = interpolate(t, [delay, delay + 0.45], [0, 1], { ...clamp, easing: ease });
            const x2 = cx + (n.x - cx) * p;
            const y2 = cy + (n.y - cy) * p;
            return <line key={n.name} x1={cx} y1={cy} x2={x2} y2={y2} stroke={n.i === 0 ? C.coral : "#3b5070"} strokeWidth={n.i === 0 ? 6 : 4} strokeLinecap="round" opacity={p > 0 ? 1 : 0} />;
          })}
        </svg>
        {nodes.map((n) => {
          const inS = spring({ frame: frame - (0.2 + n.i * 0.035) * fps, fps, config: { damping: 13, mass: 0.5 } });
          const hit = sale + 0.15 + n.i * 0.04 + 0.45;
          const pop = spring({ frame: frame - hit * fps, fps, config: { damping: 9, mass: 0.4 } });
          const count = t >= hit ? 61 : 62;
          const origin = n.i === 0;
          return (
            <div key={n.name} style={{ position: "absolute", left: n.x - node / 2 - 8, top: n.y - node / 2 - 8, width: node + 16, display: "flex", flexDirection: "column", alignItems: "center", opacity: inS, scale: String(0.5 + 0.5 * inS) }}>
              <div style={{ width: node, height: node, borderRadius: node * 0.26, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", border: origin && t > sale ? `5px solid ${C.coral}` : "5px solid #fff", boxSizing: "border-box" }}>
                <Logo file={n.file} size={node * 0.6} />
              </div>
              <div style={{ marginTop: 6, fontFamily: DISPLAY, fontWeight: 800, fontSize: 40 * L.k, color: t >= hit ? "#7ee2a8" : "#fff", scale: String(1 + 0.25 * pop * (t >= hit ? 1 : 0) - 0.25 * (t >= hit ? Math.max(0, pop - 1) : 0)) }}>
                {count}
              </div>
            </div>
          );
        })}
        {/* Merkez: 34Pazar. */}
        <div style={{ position: "absolute", left: cx - 150 * L.k, top: cy - 80 * L.k, width: 300 * L.k, height: 160 * L.k, borderRadius: 32 * L.k, background: C.brand, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", scale: String(coreIn * (1 + 0.08 * Math.sin(Math.max(0, t - sale) * 9) * Math.exp(-Math.max(0, t - sale) * 3) * (t > sale ? 1 : 0))) }}>
          <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 52 * L.k, color: "#fff" }}>34Pazar</div>
          <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 30 * L.k, color: "#ffe1d8" }}>{t > sale ? "1 satıldı · stok 61" : "stok 62"}</div>
        </div>
      </AbsoluteFill>
    </Scene>
  );
};

// ── 4 · Sayaç: 15 kanal ───────────────────────────────────────────────────────
const Count15: React.FC = () => {
  const t = useSec();
  const L = useLayout();
  const n = Math.round(interpolate(t, [0.15, 1.0], [0, 15], { ...clamp, easing: Easing.out(Easing.cubic) }));
  const sub = useSpring(0.9);
  return (
    <Scene bg={C.soft}>
      <div style={{ display: "flex", alignItems: "baseline", gap: 28 * L.k }}>
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 380 * L.k, lineHeight: 0.9, letterSpacing: "-0.05em", color: C.brand, fontVariantNumeric: "tabular-nums" }}>{n}</div>
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 110 * L.k, color: C.ink }}>kanal</div>
      </div>
      <div style={{ marginTop: 30 * L.k, fontFamily: DISPLAY, fontWeight: 800, fontSize: 84 * L.k, lineHeight: 1.08, color: C.ink, ...enter(sub) }}>Her pakette hepsi.</div>
      <div style={{ marginTop: 20 * L.k, fontFamily: SANS, fontWeight: 500, fontSize: 44 * L.k, color: C.muted, ...enter(sub) }}>Pazaryeri sayısına göre paket yok.</div>
    </Scene>
  );
};

// ── 5 · 3B eğik panel kartı: siparişler ───────────────────────────────────────
const ORDERS: [string, string, string, string, string, boolean][] = [
  ["10284571", "Trendyol", "trendyol.png", "₺379,80", "Kargo bekliyor", false],
  ["#1047", "Shopify", "shopify.svg", "₺899,00", "Kargo bekliyor", false],
  ["T-2231", "WooCommerce", "woocommerce.svg", "₺987,00", "Kargo bekliyor", false],
  ["#1043", "Shopify", "shopify.svg", "₺249,00", "Kargoda", true],
];
const Orders: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const t = useSec();
  const L = useLayout();
  const head = useSpring(0.05);
  const tilt = spring({ frame: frame - 0.25 * fps, fps, config: { damping: 18, mass: 0.9 } });
  const cam = interpolate(t, [0.3, 3], [0.94, 1.03], clamp);
  return (
    <Scene bg={C.bg}>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 92 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: C.ink, ...enter(head) }}>Bütün siparişler tek listede.</div>
      <div style={{ perspective: 1800, marginTop: 48 * L.k }}>
        <div style={{ border: `2px solid ${C.line}`, borderRadius: 28 * L.k, background: "#fff", overflow: "hidden", rotate: `x ${(1 - tilt) * 32 + 6}deg`, transform: `rotateY(${(1 - tilt) * -18 - 4}deg)`, scale: String(cam), opacity: Math.min(1, tilt * 1.5), transformOrigin: "50% 0%" }}>
          {ORDERS.map(([no, ch, file, amount, status, shipped], i) => {
            const s = spring({ frame: frame - (0.6 + i * 0.12) * fps, fps, config: { damping: 15, mass: 0.5 } });
            return (
              <div key={no} style={{ ...enter(s, 24), display: "flex", alignItems: "center", gap: 22 * L.k, padding: `${(L.square ? 18 : 26) * L.k}px ${32 * L.k}px`, borderTop: i ? `2px solid ${C.line}` : "none" }}>
                <Logo file={file} size={56 * L.k} />
                <div style={{ flex: 1 }}>
                  <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 38 * L.k, color: C.ink }}>{no}</div>
                  <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 30 * L.k, color: C.muted }}>{ch}</div>
                </div>
                <div style={{ textAlign: "right" }}>
                  <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 36 * L.k, color: C.ink }}>{amount}</div>
                  <div style={{ display: "inline-block", marginTop: 6, fontFamily: SANS, fontWeight: 600, fontSize: 26 * L.k, borderRadius: 999, padding: "4px 16px", color: shipped ? C.ok : "#8a5a00", background: shipped ? C.okSoft : "#fdf1d8" }}>{status}</div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 16 * L.k, marginTop: 40 * L.k }}>
        {["Kargo takibi", "Fiyat kuralı", "E-fatura (Nilvera)"].map((x, i) => {
          const s = spring({ frame: frame - (1.4 + i * 0.2) * fps, fps, config: { damping: 12, mass: 0.5 } });
          return (
            <div key={x} style={{ opacity: s, scale: String(0.7 + 0.3 * s), fontFamily: SANS, fontWeight: 600, fontSize: 38 * L.k, color: C.ink, border: `2px solid ${C.ink}`, borderRadius: 999, padding: `${10 * L.k}px ${26 * L.k}px` }}>
              {x}
            </div>
          );
        })}
      </div>
    </Scene>
  );
};

// ── 6 · Set ürün: üç ürün birleşir, stok en az olana göre ─────────────────────
const SET_ITEMS: [string, number][] = [
  ["Seramik Kupa", 62],
  ["Soya Mum · Lavanta", 40],
  ["Termos 500 ml", 31],
];
const SetProduct: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const t = useSec();
  const L = useLayout();
  const head = useSpring(0.05);
  const merge = interpolate(t, [1.2, 1.8], [0, 1], { ...clamp, easing: ease });
  const box = spring({ frame: frame - 1.7 * fps, fps, config: { damping: 11, mass: 0.6 } });
  const result = Math.round(interpolate(t, [1.9, 2.5], [0, 31], clamp));
  return (
    <Scene bg={C.soft}>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 88 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: C.ink, ...enter(head) }}>
        Set ürün? <span style={{ color: C.brand }}>Stoğu kendiliğinden hesaplanır.</span>
      </div>
      <div style={{ marginTop: 50 * L.k, display: "flex", flexDirection: "column", gap: 16 * L.k }}>
        {SET_ITEMS.map(([name, n], i) => {
          const s = spring({ frame: frame - (0.4 + i * 0.15) * fps, fps, config: { damping: 14, mass: 0.5 } });
          const least = i === 2;
          return (
            <div key={name} style={{ opacity: s * (1 - merge * 0.25), translate: `${(1 - s) * -60}px ${merge * (1 - i) * -30}px`, scale: String(1 - merge * 0.12), display: "flex", alignItems: "center", justifyContent: "space-between", background: "#fff", border: `2px solid ${least && t > 1.9 ? C.brand : C.line}`, borderRadius: 22 * L.k, padding: `${18 * L.k}px ${28 * L.k}px` }}>
              <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 40 * L.k, color: C.ink }}>1 × {name}</div>
              <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 46 * L.k, color: least && t > 1.9 ? C.brand : C.ink }}>{n}</div>
            </div>
          );
        })}
      </div>
      <div style={{ marginTop: 28 * L.k, opacity: box, scale: String(0.6 + 0.4 * box), transformOrigin: "50% 0%", background: C.ink, borderRadius: 26 * L.k, padding: `${26 * L.k}px ${32 * L.k}px`, display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div>
          <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 52 * L.k, color: "#fff" }}>Hediye seti</div>
          <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 32 * L.k, color: C.pale }}>en az olan bileşene göre</div>
        </div>
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 96 * L.k, color: C.coral, fontVariantNumeric: "tabular-nums" }}>{result}</div>
      </div>
    </Scene>
  );
};

// ── 7 · Fiyat: sayan rakam, üstü çizilen yıllık peşin ─────────────────────────
const Price: React.FC = () => {
  const t = useSec();
  const L = useLayout();
  const n = Math.round(interpolate(t, [0.2, 1.1], [0, 499], { ...clamp, easing: Easing.out(Easing.cubic) }));
  const a = useSpring(1.2);
  const strike = interpolate(t, [1.6, 2.1], [0, 100], { ...clamp, easing: ease });
  const b = useSpring(2.2);
  return (
    <Scene bg={C.navy}>
      <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56 * L.k, color: C.pale }}>Aylık</div>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 230 * L.k, lineHeight: 1, letterSpacing: "-0.04em", color: "#fff", fontVariantNumeric: "tabular-nums" }}>
        {n} ₺<span style={{ fontSize: 120 * L.k }}>'den</span>
      </div>
      <div style={{ marginTop: 56 * L.k, position: "relative", alignSelf: "flex-start", ...enter(a) }}>
        <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 60 * L.k, color: C.pale }}>Yıllık peşin ödeme</div>
        <div style={{ position: "absolute", left: -6, top: "54%", height: 8 * L.k, width: `calc(${strike}% + 12px)`, background: C.coral, borderRadius: 4 }} />
      </div>
      <div style={{ marginTop: 18 * L.k, fontFamily: DISPLAY, fontWeight: 800, fontSize: 72 * L.k, color: "#fff", ...enter(b) }}>Taahhüt yok.</div>
    </Scene>
  );
};

// ── 8 · Güven ─────────────────────────────────────────────────────────────────
const Trust: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const L = useLayout();
  const rows: [string, string][] = [
    ["Ücretsiz paket, kart gerekmez", ""],
    ["14 gün içinde tam iade", "ücretli paketlerde"],
    ["Her gün WhatsApp destek", "09.00–18.00"],
  ];
  return (
    <Scene bg={C.bg}>
      {rows.map(([a, b], i) => {
        const s = spring({ frame: frame - (0.15 + i * 0.25) * fps, fps, config: { damping: 13, mass: 0.5 } });
        const tick = spring({ frame: frame - (0.35 + i * 0.25) * fps, fps, config: { damping: 8, mass: 0.4 } });
        return (
          <div key={a} style={{ ...enter(s, 30), display: "flex", alignItems: "flex-start", gap: 28 * L.k, borderTop: i ? `2px solid ${C.line}` : "none", paddingTop: i ? 30 * L.k : 0, marginTop: i ? 30 * L.k : 0 }}>
            <div style={{ width: 76 * L.k, height: 76 * L.k, borderRadius: 999, background: C.ok, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 900, fontSize: 46 * L.k, scale: String(tick), flexShrink: 0 }}>✓</div>
            <div>
              <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 58 * L.k, color: C.ink, lineHeight: 1.12 }}>{a}</div>
              {b ? <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 38 * L.k, color: C.muted, marginTop: 6 }}>{b}</div> : null}
            </div>
          </div>
        );
      })}
    </Scene>
  );
};

// ── 9 · Kapanış: logo oturur, düğmeye basılır ─────────────────────────────────
const Cta: React.FC = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const t = useSec();
  const L = useLayout();
  const logo = spring({ frame, fps, config: { damping: 16, mass: 0.8 } });
  const btn = spring({ frame: frame - 0.5 * fps, fps, config: { damping: 12, mass: 0.6 } });
  // Basılma: 1,4 sn'de içe, sonra geri.
  const press = interpolate(t, [1.4, 1.52, 1.75], [1, 0.92, 1], clamp);
  const pressed = t > 1.45 && t < 1.75;
  const url = spring({ frame: frame - 0.8 * fps, fps, config: { damping: 14, mass: 0.6 } });
  return (
    <Scene bg={C.navy}>
      <div style={{ display: "flex", flexDirection: "column", alignItems: "center", textAlign: "center" }}>
        <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 640 * L.k, scale: String(1.5 - 0.5 * logo), translate: `0 ${(1 - logo) * -120}px`, opacity: Math.min(1, logo * 1.6) }} />
        <div style={{ marginTop: 50 * L.k, fontFamily: SANS, fontWeight: 600, fontSize: 52 * L.k, color: C.pale, ...enter(url) }}>Stoğunu tek yerden yönet.</div>
        <div style={{ marginTop: 64 * L.k, opacity: btn, scale: String((0.7 + 0.3 * btn) * press), background: pressed ? C.brand : C.brand, color: "#fff", fontFamily: DISPLAY, fontWeight: 900, fontSize: 70 * L.k, padding: `${34 * L.k}px ${70 * L.k}px`, borderRadius: 999, border: `4px solid ${pressed ? "#fff" : C.brand}` }}>
          Ücretsiz başla
        </div>
        <div style={{ marginTop: 40 * L.k, fontFamily: DISPLAY, fontWeight: 800, fontSize: 64 * L.k, color: "#fff", ...enter(url) }}>34pazar.com</div>
      </div>
    </Scene>
  );
};

const SCENES = [Hook, Problem, Orbit, Count15, Orders, SetProduct, Price, Trust, Cta];

export const PazarReelsV2: React.FC = () => {
  const { fps } = useVideoConfig();
  let from = 0;
  return (
    <AbsoluteFill style={{ background: C.night }}>
      {SCENES.map((SceneC, i) => {
        const start = from;
        from += S[i];
        return (
          <Sequence key={i} from={Math.round(start * fps)} durationInFrames={Math.round(S[i] * fps)} premountFor={fps}>
            <SceneC />
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};

// Kapak (ilk kare değil): okunur başlık + yörünge özeti.
export const PazarCoverV2: React.FC = () => {
  const { width } = useVideoConfig();
  const cx = width / 2;
  const cy = 1180;
  return (
    <AbsoluteFill style={{ background: C.navy }}>
      <AbsoluteFill style={{ paddingTop: 250, paddingLeft: 72, paddingRight: 72 }}>
        <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 380 }} />
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 124, lineHeight: 1.02, letterSpacing: "-0.03em", color: "#fff", marginTop: 60 }}>
          Tek stok.
          <br />
          <span style={{ color: C.coral }}>15 kanal.</span>
        </div>
        <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 52, color: C.pale, marginTop: 30 }}>Aylık 499 ₺'den · taahhüt yok</div>
      </AbsoluteFill>
      <svg width={width} height={1920} style={{ position: "absolute", inset: 0 }}>
        {CHANNELS.map((_, i) => {
          const a = -Math.PI / 2 + (i / CHANNELS.length) * Math.PI * 2;
          return <line key={i} x1={cx} y1={cy} x2={cx + 380 * Math.cos(a)} y2={cy + 300 * Math.sin(a)} stroke={i === 0 ? C.coral : "#3b5070"} strokeWidth={i === 0 ? 6 : 4} />;
        })}
      </svg>
      {CHANNELS.map(([name, file], i) => {
        const a = -Math.PI / 2 + (i / CHANNELS.length) * Math.PI * 2;
        const x = cx + 380 * Math.cos(a);
        const y = cy + 300 * Math.sin(a);
        return (
          <div key={name} style={{ position: "absolute", left: x - 42, top: y - 42, width: 84, height: 84, borderRadius: 22, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center" }}>
            <Logo file={file} size={50} />
          </div>
        );
      })}
      <div style={{ position: "absolute", left: cx - 130, top: cy - 60, width: 260, height: 120, borderRadius: 28, background: C.brand, display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 900, fontSize: 48, color: "#fff" }}>34Pazar</div>
    </AbsoluteFill>
  );
};
