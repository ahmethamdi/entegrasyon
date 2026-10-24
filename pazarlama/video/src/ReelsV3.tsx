// 34Pazar Türkçe reklam v3 — v2'nin müzikli sürümü. Kurgu müziğin bar ızgarasına oturur.
// Müzik: "House - July_32Sec" · Easy_Eva · Pixabay Content License (ticari, atıfsız;
// Content ID yok). Lisans kaydı: Masaüstü/03 34Devs/34Pazar - Entegrasyon/reels/muzik/LISANS.txt
// Dosya public/muzik/july32-norm.wav (−14 LUFS, depoya girmez).
// Ölçülen ızgara: 127 BPM, ilk vuruş 0,425 sn, bar 1,8898 sn, drop bar 9 (17,43 sn).
// Sahneler bar başında değişir (iki sahne yarım barda, 3. vuruşta). Her vuruşta kesme yok;
// vuruşlar küçük ölçek nabzıyla ve rozet girişleriyle vurgulanır.
// v2'den fark: "15 kanal" sayacı yörünge sahnesinin hazırlığına, güven maddeleri kapanışa
// taşındı (9 sahne 32 sn'ye ve drop'a sığmıyordu). İddialar v2 ile aynı.
// Yasaklar sürüyor: gölge, parıltı, blob, kayan bant YOK.

import React, { createContext, useContext } from "react";
import { AbsoluteFill, Easing, Img, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig } from "remotion";
import { Audio } from "@remotion/media";
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
  soft: "#f5f4f1",
  line: "#e3e2dd",
  ok: "#067647",
  okSoft: "#e3f4ea",
};

// ── Müzik ızgarası ───────────────────────────────────────────────────────────
const BPM = 127;
const BEAT = 60 / BPM;
const T0 = 0.425;
const BAR = 4 * BEAT;
const bar = (n: number) => T0 + n * BAR;
const MUSIC_END = 32.4;

// Sahne sınırları (sn, mutlak).
const CUTS = [0, bar(3), bar(5), bar(10), bar(11.5), bar(13.5), bar(15), MUSIC_END];
export const DURATION_V3 = MUSIC_END;

const ease = Easing.bezier(0.16, 1, 0.3, 1);
const clamp = { extrapolateLeft: "clamp", extrapolateRight: "clamp" } as const;
const enter = (t: number, dist = 40) => ({ opacity: t, translate: `0 ${(1 - t) * dist}px` });

// Sahne başlangıcı bağlamı: sahne içi saniye ve mutlak saniye.
const StartCtx = createContext(0);
const useT = () => {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const start = useContext(StartCtx);
  return { t: frame / fps, abs: start + frame / fps, fps, frame };
};

// Vuruş nabzı: her vuruşta 1'e çıkar, ~120 ms'de söner (mutlak zamana göre).
const usePulse = (from = 0) => {
  const { abs } = useT();
  if (abs < Math.max(T0, from)) return 0;
  const since = (abs - T0) % BEAT;
  return Math.exp(-since / 0.12);
};

// Saniye cinsinden gecikmeli yay.
const useSpringAt = (at: number, damping = 14, mass = 0.6) => {
  const { frame, fps } = useT();
  return spring({ frame: frame - at * fps, fps, config: { damping, mass } });
};

const useLayout = () => {
  const { width, height } = useVideoConfig();
  const square = height <= width;
  return { square, k: square ? 0.78 : 1, padTop: square ? 90 : 250, padBottom: square ? 90 : 350, padX: square ? 80 : 72, width, height };
};

// Sahne kabı: girişte soldan sağa maskeli silme (yarım vuruş), çıkışta kısa solma.
const Scene: React.FC<{ bg: string; children: React.ReactNode; center?: boolean }> = ({ bg, children, center = true }) => {
  const { frame, fps } = useT();
  const { durationInFrames } = useVideoConfig();
  const L = useLayout();
  const w = interpolate(frame, [0, (BEAT / 2) * fps], [100, 0], { ...clamp, easing: ease });
  const out = interpolate(frame, [durationInFrames - 0.15 * fps, durationInFrames], [1, 0], clamp);
  return (
    <AbsoluteFill style={{ background: bg, clipPath: `inset(0 ${w}% 0 0)` }}>
      <AbsoluteFill style={{ opacity: out, paddingTop: L.padTop, paddingBottom: L.padBottom, paddingLeft: L.padX, paddingRight: L.padX, justifyContent: center ? "center" : "flex-start" }}>{children}</AbsoluteFill>
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

// ── 1 · Bildirim yağmuru (sekizlik notalarda) → donma (bar 1) → kanca (vuruşlarda) ─
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
  const { t, fps, frame } = useT();
  const L = useLayout();
  const freeze = bar(1);
  const dim = interpolate(t, [freeze, freeze + BEAT], [1, 0.16], clamp);
  const pulse = usePulse(bar(1) + 4 * BEAT);
  const noteW = L.square ? 700 : 860;
  return (
    <AbsoluteFill style={{ background: C.night }}>
      <AbsoluteFill style={{ alignItems: "center", justifyContent: "center", opacity: dim }}>
        {NOTES.map(([a, b, file], i) => {
          const at = T0 + i * (BEAT / 2);
          const s = spring({ frame: Math.min(frame, freeze * fps) - at * fps, fps, config: { damping: 15, mass: 0.45 } });
          const stock = a.startsWith("Stok");
          return (
            <div key={i} style={{ width: noteW, marginTop: i ? (L.square ? -6 : 4) : 0, opacity: s, translate: `0 ${(1 - s) * -80}px`, scale: String(0.92 + 0.08 * s), display: "flex", alignItems: "center", gap: 22 * L.k, padding: `${20 * L.k}px ${28 * L.k}px`, borderRadius: 26 * L.k, background: stock ? "#3a1410" : "#16233a", border: `2px solid ${stock ? "#6b2418" : C.navyLine}` }}>
              {file ? <Logo file={file} size={52 * L.k} /> : <div style={{ width: 52 * L.k, height: 52 * L.k, borderRadius: 12, background: C.brand, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 900, fontSize: 34 * L.k }}>!</div>}
              <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 38 * L.k, color: stock ? C.coral : "#fff" }}>{a}</div>
              <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 34 * L.k, color: C.pale, marginLeft: "auto" }}>{b}</div>
            </div>
          );
        })}
      </AbsoluteFill>
      <AbsoluteFill style={{ justifyContent: "center", paddingLeft: L.padX, paddingRight: L.padX, paddingTop: L.padTop, paddingBottom: L.padBottom }}>
        <div style={{ display: "flex", flexWrap: "wrap", columnGap: 26 * L.k }}>
          {HOOK_WORDS.map((w, i) => {
            const s = spring({ frame: frame - (freeze + i * BEAT) * fps, fps, config: { damping: 12, mass: 0.55 } });
            const hot = w.startsWith("hâlâ");
            return (
              <span key={w} style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: (hot ? 150 : 112) * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: hot ? C.coral : "#fff", opacity: Math.min(1, s * 1.4), scale: String((0.6 + 0.4 * s) * (hot ? 1 + 0.05 * pulse : 1)), translate: `0 ${(1 - s) * 50}px`, display: "inline-block", transformOrigin: "left bottom" }}>
                {w}
              </span>
            );
          })}
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

// ── 2 · Dert (2 bar): üstü çizilen sipariş, kelimeler vuruşlarda ─────────────
const Problem: React.FC = () => {
  const { t } = useT();
  const L = useLayout();
  const a = useSpringAt(0.05);
  const strike = interpolate(t, [2 * BEAT, 2.6 * BEAT], [0, 100], { ...clamp, easing: ease });
  const words = ["İptal.", "Özür.", "Kötü yorum."];
  return (
    <Scene bg={C.navy}>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 120 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: "#fff", ...enter(a) }}>
        Elinde olmayan ürünü <span style={{ color: C.coral }}>satarsın.</span>
      </div>
      <div style={{ marginTop: 56 * L.k, position: "relative", alignSelf: "flex-start" }}>
        <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56 * L.k, color: C.pale, ...enter(a) }}>Sipariş #10284571 · Seramik Kupa</div>
        <div style={{ position: "absolute", left: 0, top: "52%", height: 8 * L.k, width: `${strike}%`, background: C.coral, borderRadius: 4 }} />
      </div>
      <div style={{ marginTop: 28 * L.k, display: "flex", gap: 22 * L.k }}>
        {words.map((w, i) => {
          const s = useSpringAt((4 + i) * BEAT, 11, 0.5);
          return <span key={w} style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56 * L.k, color: C.coral, opacity: s, scale: String(0.7 + 0.3 * s), display: "inline-block" }}>{w}</span>;
        })}
      </div>
    </Scene>
  );
};

// ── 3 · Yörünge (5 bar): 15 kanal sayılır → gerilim → DROP'ta satış dalgası ───
const Orbit: React.FC = () => {
  const { t, fps, frame } = useT();
  const L = useLayout();
  const pulse = usePulse();
  const cx = L.width / 2;
  const cy = L.square ? 655 : 1080;
  const rx = L.square ? 380 : 410;
  const ry = L.square ? 295 : 470;
  const node = 92 * L.k;
  const phaseB = 8 * BEAT; // bar 7: başlık değişir
  // Bar 9 = 16 vuruş sonra; sesteki asıl vuruş ızgaradan ~50 ms sonra geliyor (ölçüldü: 17,47–17,50 sn).
  const drop = bar(9) - bar(5) + 0.05;
  const count = Math.min(15, Math.max(0, Math.floor((t + 0.0001) / (BEAT / 2)) + 1));
  const titleA = interpolate(t, [0, 0.3, phaseB - 0.2, phaseB], [0, 1, 1, 0], clamp);
  const titleB = interpolate(t, [phaseB, phaseB + 0.3], [0, 1], clamp);
  const sub = useSpringAt(4 * BEAT);
  // Drop öncesi son iki vuruşta Trendyol'dan merkeze sipariş gelir.
  const fly = interpolate(t, [drop - 2 * BEAT, drop], [0, 1], { ...clamp, easing: Easing.in(Easing.cubic) });
  const nodes = CHANNELS.map(([name, file], i) => {
    const a = -Math.PI / 2 + (i / CHANNELS.length) * Math.PI * 2;
    return { name, file, x: cx + rx * Math.cos(a), y: cy + ry * Math.sin(a), i };
  });
  const sold = t >= drop;
  const core = spring({ frame: frame - drop * fps, fps, config: { damping: 8, mass: 0.5 } });
  return (
    <Scene bg={C.navy} center={false}>
      <div style={{ position: "relative", height: 210 * L.k }}>
        <div style={{ position: "absolute", inset: 0, textAlign: "center", opacity: titleA }}>
          <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 120 * L.k, lineHeight: 1, letterSpacing: "-0.03em", color: "#fff" }}>
            <span style={{ color: C.coral, fontVariantNumeric: "tabular-nums" }}>{count}</span> kanal.
          </div>
          <div style={{ marginTop: 14 * L.k, fontFamily: DISPLAY, fontWeight: 800, fontSize: 60 * L.k, color: C.pale, ...enter(sub, 20) }}>Her pakette hepsi.</div>
        </div>
        <div style={{ position: "absolute", inset: 0, textAlign: "center", opacity: titleB, fontFamily: DISPLAY, fontWeight: 900, fontSize: 92 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: "#fff" }}>
          Bir satış. <span style={{ color: C.coral }}>Bütün kanallarda düşer.</span>
        </div>
      </div>
      <AbsoluteFill>
        <svg width={L.width} height={L.height} style={{ position: "absolute", inset: 0 }}>
          {nodes.map((n) => {
            const at = drop + n.i * 0.03;
            const p = interpolate(t, [at, at + BEAT], [0, 1], { ...clamp, easing: ease });
            return <line key={n.name} x1={cx} y1={cy} x2={cx + (n.x - cx) * p} y2={cy + (n.y - cy) * p} stroke={n.i === 0 ? C.coral : "#3b5070"} strokeWidth={n.i === 0 ? 6 : 4} strokeLinecap="round" opacity={p > 0 ? 1 : 0} />;
          })}
          {/* Drop öncesi: Trendyol'dan merkeze uçan sipariş çizgisi. */}
          {fly > 0 && t < drop + BEAT ? <line x1={nodes[0].x} y1={nodes[0].y} x2={nodes[0].x + (cx - nodes[0].x) * fly} y2={nodes[0].y + (cy - nodes[0].y) * fly} stroke={C.coral} strokeWidth={6} strokeLinecap="round" /> : null}
        </svg>
        {nodes.map((n) => {
          // Her yarım vuruşta bir kanal belirir; sayaç bununla birlikte artar.
          const inS = spring({ frame: frame - n.i * (BEAT / 2) * fps, fps, config: { damping: 12, mass: 0.45 } });
          const hit = drop + n.i * 0.03 + BEAT;
          const done = t >= hit;
          const beatScale = t > phaseB && !done ? 1 + 0.06 * pulse : 1;
          const origin = n.i === 0;
          return (
            <div key={n.name} style={{ position: "absolute", left: n.x - node / 2 - 8, top: n.y - node / 2 - 8, width: node + 16, display: "flex", flexDirection: "column", alignItems: "center", opacity: inS, scale: String((0.5 + 0.5 * inS) * beatScale) }}>
              <div style={{ width: node, height: node, borderRadius: node * 0.26, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", border: origin && fly > 0 ? `5px solid ${C.coral}` : "5px solid #fff", boxSizing: "border-box" }}>
                <Logo file={n.file} size={node * 0.6} />
              </div>
              <div style={{ marginTop: 6, fontFamily: DISPLAY, fontWeight: 800, fontSize: 40 * L.k, color: done ? "#7ee2a8" : "#fff", scale: String(done ? 1 + 0.35 * Math.exp(-(t - hit) * 7) : 1) }}>
                {done ? 61 : 62}
              </div>
            </div>
          );
        })}
        <div style={{ position: "absolute", left: cx - 150 * L.k, top: cy - 80 * L.k, width: 300 * L.k, height: 160 * L.k, borderRadius: 32 * L.k, background: C.brand, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", scale: String((sold ? 1 + 0.12 * Math.max(0, 1 - core) + 0.12 * Math.min(core, 1) * Math.exp(-(t - drop) * 4) : 1) * (1 + 0.04 * pulse)) }}>
          <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 52 * L.k, color: "#fff" }}>34Pazar</div>
          <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 30 * L.k, color: "#ffe1d8" }}>{sold ? "1 satıldı · stok 61" : "stok 62"}</div>
        </div>
      </AbsoluteFill>
    </Scene>
  );
};

// ── 4 · Siparişler (6 vuruş): 3B eğik kart, rozetler vuruşlarda ────────────────
const ORDERS: [string, string, string, string, string, boolean][] = [
  ["10284571", "Trendyol", "trendyol.png", "₺379,80", "Kargo bekliyor", false],
  ["#1047", "Shopify", "shopify.svg", "₺899,00", "Kargo bekliyor", false],
  ["T-2231", "WooCommerce", "woocommerce.svg", "₺987,00", "Kargo bekliyor", false],
  ["#1043", "Shopify", "shopify.svg", "₺249,00", "Kargoda", true],
];
const Orders: React.FC = () => {
  const { t, fps, frame } = useT();
  const L = useLayout();
  const head = useSpringAt(0.03);
  const tilt = spring({ frame: frame - 0.12 * fps, fps, config: { damping: 18, mass: 0.8 } });
  const cam = interpolate(t, [0.2, 6 * BEAT], [0.94, 1.03], clamp);
  return (
    <Scene bg="#ffffff">
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 92 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: C.ink, ...enter(head) }}>Bütün siparişler tek listede.</div>
      <div style={{ perspective: 1800, marginTop: 48 * L.k }}>
        <div style={{ border: `2px solid ${C.line}`, borderRadius: 28 * L.k, background: "#fff", overflow: "hidden", rotate: `x ${(1 - tilt) * 32 + 6}deg`, transform: `rotateY(${(1 - tilt) * -18 - 4}deg)`, scale: String(cam), opacity: Math.min(1, tilt * 1.5), transformOrigin: "50% 0%" }}>
          {ORDERS.map(([no, ch, file, amount, status, shipped], i) => {
            const s = spring({ frame: frame - (BEAT / 2) * (1 + i) * fps, fps, config: { damping: 15, mass: 0.45 } });
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
          const s = useSpringAt((2 + i) * BEAT, 11, 0.45);
          return <div key={x} style={{ opacity: s, scale: String(0.7 + 0.3 * s), fontFamily: SANS, fontWeight: 600, fontSize: 38 * L.k, color: C.ink, border: `2px solid ${C.ink}`, borderRadius: 999, padding: `${10 * L.k}px ${26 * L.k}px` }}>{x}</div>;
        })}
      </div>
    </Scene>
  );
};

// ── 5 · Set ürün (2 bar): birleşme 3. vuruşta, sonuç 4. vuruşta ───────────────
const SET_ITEMS: [string, number][] = [
  ["Seramik Kupa", 62],
  ["Soya Mum · Lavanta", 40],
  ["Termos 500 ml", 31],
];
const SetProduct: React.FC = () => {
  const { t, fps, frame } = useT();
  const L = useLayout();
  const head = useSpringAt(0.03);
  const merge = interpolate(t, [3 * BEAT, 4 * BEAT], [0, 1], { ...clamp, easing: ease });
  const box = spring({ frame: frame - 4 * BEAT * fps, fps, config: { damping: 11, mass: 0.55 } });
  const result = Math.round(interpolate(t, [4 * BEAT, 5 * BEAT], [0, 31], clamp));
  const pulse = usePulse();
  const lit = t > 4 * BEAT;
  return (
    <Scene bg={C.soft}>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 88 * L.k, lineHeight: 1.05, letterSpacing: "-0.03em", color: C.ink, ...enter(head) }}>
        Set ürün? <span style={{ color: C.brand }}>Stoğu kendiliğinden hesaplanır.</span>
      </div>
      <div style={{ marginTop: 50 * L.k, display: "flex", flexDirection: "column", gap: 16 * L.k }}>
        {SET_ITEMS.map(([name, n], i) => {
          const s = spring({ frame: frame - (0.5 + i * 0.5) * BEAT * fps, fps, config: { damping: 14, mass: 0.45 } });
          const least = i === 2;
          return (
            <div key={name} style={{ opacity: s * (1 - merge * 0.25), translate: `${(1 - s) * -60}px ${merge * (1 - i) * -30}px`, scale: String(1 - merge * 0.12), display: "flex", alignItems: "center", justifyContent: "space-between", background: "#fff", border: `2px solid ${least && lit ? C.brand : C.line}`, borderRadius: 22 * L.k, padding: `${18 * L.k}px ${28 * L.k}px` }}>
              <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 40 * L.k, color: C.ink }}>1 × {name}</div>
              <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 46 * L.k, color: least && lit ? C.brand : C.ink }}>{n}</div>
            </div>
          );
        })}
      </div>
      <div style={{ marginTop: 28 * L.k, opacity: box, scale: String(0.6 + 0.4 * box), transformOrigin: "50% 0%", background: C.ink, borderRadius: 26 * L.k, padding: `${26 * L.k}px ${32 * L.k}px`, display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div>
          <div style={{ fontFamily: DISPLAY, fontWeight: 800, fontSize: 52 * L.k, color: "#fff" }}>Hediye seti</div>
          <div style={{ fontFamily: SANS, fontWeight: 500, fontSize: 32 * L.k, color: C.pale }}>en az olan bileşene göre</div>
        </div>
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 96 * L.k, color: C.coral, fontVariantNumeric: "tabular-nums", scale: String(1 + (t > 5 * BEAT ? 0.05 * pulse : 0)) }}>{result}</div>
      </div>
    </Scene>
  );
};

// ── 6 · Fiyat (6 vuruş): sayaç 2 vuruşta, üstü çizme 3. vuruşta ───────────────
const Price: React.FC = () => {
  const { t } = useT();
  const L = useLayout();
  const pulse = usePulse();
  const n = Math.round(interpolate(t, [0.05, 2 * BEAT], [0, 499], { ...clamp, easing: Easing.out(Easing.cubic) }));
  const a = useSpringAt(2 * BEAT);
  const strike = interpolate(t, [3 * BEAT, 3.5 * BEAT], [0, 100], { ...clamp, easing: ease });
  const b = useSpringAt(4 * BEAT);
  return (
    <Scene bg={C.navy}>
      <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 56 * L.k, color: C.pale }}>Aylık</div>
      <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 230 * L.k, lineHeight: 1, letterSpacing: "-0.04em", color: "#fff", fontVariantNumeric: "tabular-nums", scale: String(t > 2 * BEAT ? 1 + 0.03 * pulse : 1), transformOrigin: "left center" }}>
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

// ── 7 · Kapanış + güven (son 2 bar): logo oturur, maddeler vuruşlarda, düğmeye basılır ─
const Cta: React.FC = () => {
  const { t, fps, frame } = useT();
  const L = useLayout();
  const pulse = usePulse();
  const logo = spring({ frame, fps, config: { damping: 16, mass: 0.8 } });
  const btn = useSpringAt(BEAT, 12, 0.55);
  const press = interpolate(t, [4 * BEAT, 4 * BEAT + 0.1, 4 * BEAT + 0.3], [1, 0.92, 1], clamp);
  const pressed = t > 4 * BEAT && t < 4 * BEAT + 0.3;
  const url = useSpringAt(2 * BEAT);
  const trust = ["Ücretsiz paket · kart gerekmez", "Ücretli pakette 14 gün iade", "Her gün WhatsApp destek"];
  return (
    <Scene bg={C.navy}>
      <div style={{ display: "flex", flexDirection: "column", alignItems: "center", textAlign: "center" }}>
        <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 600 * L.k, scale: String(1.5 - 0.5 * logo), translate: `0 ${(1 - logo) * -120}px`, opacity: Math.min(1, logo * 1.6) }} />
        <div style={{ marginTop: 60 * L.k, opacity: btn, scale: String((0.7 + 0.3 * btn) * press * (t > 4 * BEAT + 0.3 ? 1 + 0.03 * pulse : 1)), background: C.brand, color: "#fff", fontFamily: DISPLAY, fontWeight: 900, fontSize: 70 * L.k, padding: `${32 * L.k}px ${70 * L.k}px`, borderRadius: 999, border: `4px solid ${pressed ? "#fff" : C.brand}` }}>
          Ücretsiz başla
        </div>
        <div style={{ marginTop: 34 * L.k, fontFamily: DISPLAY, fontWeight: 800, fontSize: 64 * L.k, color: "#fff", ...enter(url) }}>34pazar.com</div>
        <div style={{ marginTop: 52 * L.k, display: "flex", flexDirection: "column", gap: 14 * L.k, alignItems: "flex-start" }}>
          {trust.map((x, i) => {
            const s = useSpringAt((2.5 + i * 0.5) * BEAT, 11, 0.45);
            return (
              <div key={x} style={{ display: "flex", alignItems: "center", gap: 18 * L.k, opacity: s, translate: `${(1 - s) * 30}px 0` }}>
                <div style={{ width: 46 * L.k, height: 46 * L.k, borderRadius: 999, background: C.ok, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 900, fontSize: 28 * L.k }}>✓</div>
                <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 42 * L.k, color: "#fff" }}>{x}</div>
              </div>
            );
          })}
        </div>
      </div>
    </Scene>
  );
};

const SCENES = [Hook, Problem, Orbit, Orders, SetProduct, Price, Cta];

export const PazarReelsV3: React.FC = () => {
  const { fps } = useVideoConfig();
  return (
    <AbsoluteFill style={{ background: C.night }}>
      <Audio src={staticFile("muzik/july32-norm.wav")} />
      {SCENES.map((SceneC, i) => {
        const from = Math.round(CUTS[i] * fps);
        const to = Math.round(CUTS[i + 1] * fps);
        return (
          <Sequence key={i} from={from} durationInFrames={to - from} premountFor={fps}>
            <StartCtx.Provider value={from / fps}>
              <SceneC />
            </StartCtx.Provider>
          </Sequence>
        );
      })}
    </AbsoluteFill>
  );
};

// Sahne sınırları (rapor ve kontrol için).
export const V3_CUTS = CUTS;
