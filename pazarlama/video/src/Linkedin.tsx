// 34Pazar LinkedIn tanıtım görselleri (Reels v2/v3 görsel dili, gölgesiz).
// 1200×1500 (4:5, akış) ve 1200×627 (yatay). Kanallar: 25 Eki itibarıyla bağlanabilen 12 kanal
// (Amazon, OTTO, Etsy gizli — burada YOK). Sahne: Trendyol'da bir satış, bütün kanallarda 62→61.

import React from "react";
import { AbsoluteFill, Img, staticFile } from "remotion";
import { loadFont as loadArchivo } from "@remotion/google-fonts/Archivo";
import { loadFont as loadInter } from "@remotion/google-fonts/InterTight";

const { fontFamily: DISPLAY } = loadArchivo("normal", { weights: ["800", "900"], subsets: ["latin", "latin-ext"] });
const { fontFamily: SANS } = loadInter("normal", { weights: ["500", "600"], subsets: ["latin", "latin-ext"] });

const C = { navy: "#0b1b33", line: "#3b5070", brand: "#ce310d", coral: "#ff8a6b", pale: "#c9d2e0", ink: "#15171c", ok: "#7ee2a8" };

const CHANNELS: [string, string | null][] = [
  ["Trendyol", "trendyol.png"], ["Hepsiburada", "hepsiburada.png"], ["N11", "n11.png"], ["Çiçeksepeti", "ciceksepeti.png"],
  ["Pazarama", "pazarama.png"], ["İdefix", "idefix.png"], ["eBay", "ebay.png"], ["Kaufland", "kaufland.svg"],
  ["Shopify", "shopify.svg"], ["WooCommerce", "woocommerce.svg"], ["ikas", "ikas.png"], ["Ticimax", null],
];

const Logo: React.FC<{ file: string | null; size: number }> = ({ file, size }) =>
  file ? (
    <Img src={staticFile(`img/channels/icon/${file}`)} style={{ width: size, height: size, objectFit: "contain" }} />
  ) : (
    <div style={{ width: size, height: size, borderRadius: size * 0.22, background: C.ink, color: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontFamily: DISPLAY, fontWeight: 800, fontSize: size * 0.57 }}>T</div>
  );

// Yörünge: merkezde 34Pazar, çevrede 12 kanal; Trendyol'dan gelen satış turuncu.
const Orbit: React.FC<{ cx: number; cy: number; rx: number; ry: number; node: number; w: number; h: number; scale?: number }> = ({ cx, cy, rx, ry, node, w, h, scale = 1 }) => {
  const nodes = CHANNELS.map(([name, file], i) => {
    const a = -Math.PI / 2 + (i / CHANNELS.length) * Math.PI * 2;
    return { name, file, x: cx + rx * Math.cos(a), y: cy + ry * Math.sin(a), i };
  });
  return (
    <>
      <svg width={w} height={h} style={{ position: "absolute", inset: 0 }}>
        {nodes.map((n) => (
          <line key={n.name} x1={cx} y1={cy} x2={n.x} y2={n.y} stroke={n.i === 0 ? C.coral : C.line} strokeWidth={n.i === 0 ? 6 * scale : 4 * scale} strokeLinecap="round" />
        ))}
      </svg>
      {nodes.map((n) => (
        <div key={n.name} style={{ position: "absolute", left: n.x - node / 2, top: n.y - node / 2, width: node, display: "flex", flexDirection: "column", alignItems: "center" }}>
          <div style={{ width: node, height: node, borderRadius: node * 0.26, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", border: `${5 * scale}px solid ${n.i === 0 ? C.coral : "#fff"}`, boxSizing: "border-box" }}>
            <Logo file={n.file} size={node * 0.6} />
          </div>
          <div style={{ marginTop: 6 * scale, fontFamily: DISPLAY, fontWeight: 800, fontSize: 36 * scale, color: C.ok }}>61</div>
        </div>
      ))}
      <div style={{ position: "absolute", left: cx - 150 * scale, top: cy - 78 * scale, width: 300 * scale, height: 156 * scale, borderRadius: 30 * scale, background: C.brand, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center" }}>
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 52 * scale, color: "#fff" }}>34Pazar</div>
        <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: 28 * scale, color: "#ffe1d8" }}>1 satıldı · stok 61</div>
      </div>
    </>
  );
};

const Strip: React.FC<{ size: number; pad: string }> = ({ size, pad }) => (
  <div style={{ background: C.brand, padding: pad, display: "flex", alignItems: "center", justifyContent: "space-between", gap: 24 }}>
    <div style={{ fontFamily: SANS, fontWeight: 600, fontSize: size, color: "#fff" }}>
      Aylık <b style={{ fontFamily: DISPLAY, fontWeight: 900 }}>499 ₺'den</b> · Taahhüt yok
    </div>
    <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: size, color: "#fff" }}>Ücretsiz başla — 34pazar.com</div>
  </div>
);

// 4:5 · 1200×1500
export const LinkedinPost: React.FC = () => (
  <AbsoluteFill style={{ background: C.navy }}>
    <div style={{ padding: "80px 80px 0" }}>
      <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 300 }} />
      <div style={{ marginTop: 50, fontFamily: DISPLAY, fontWeight: 900, fontSize: 104, lineHeight: 1.02, letterSpacing: "-0.03em", color: "#fff" }}>
        Bir satış.
        <br />
        <span style={{ color: C.coral }}>Bütün kanallarda stok düşer.</span>
      </div>
      <div style={{ marginTop: 26, fontFamily: SANS, fontWeight: 500, fontSize: 40, lineHeight: 1.3, color: C.pale }}>12 kanal, tek stok, tek panel.</div>
    </div>
    <AbsoluteFill>
      <Orbit cx={600} cy={965} rx={420} ry={270} node={86} w={1200} h={1500} scale={0.9} />
    </AbsoluteFill>
    <div style={{ position: "absolute", left: 0, right: 0, bottom: 0 }}>
      <div style={{ background: C.brand, padding: "30px 80px 34px" }}>
        <div style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 50, color: "#fff" }}>Ücretsiz başla — 34pazar.com</div>
        <div style={{ marginTop: 6, fontFamily: SANS, fontWeight: 600, fontSize: 34, color: "#ffe1d8" }}>Aylık 499 ₺'den · Yıllık peşin yok · Taahhüt yok</div>
      </div>
    </div>
  </AbsoluteFill>
);

// Yatay · 1200×627
export const LinkedinWide: React.FC = () => (
  <AbsoluteFill style={{ background: C.navy }}>
    <div style={{ position: "absolute", left: 64, top: 56, width: 560 }}>
      <Img src={staticFile("img/34pazar-logo-beyaz.png")} style={{ width: 220 }} />
      <div style={{ marginTop: 34, fontFamily: DISPLAY, fontWeight: 900, fontSize: 66, lineHeight: 1.03, letterSpacing: "-0.03em", color: "#fff" }}>
        Bir satış.
        <br />
        <span style={{ color: C.coral }}>Bütün kanallarda stok düşer.</span>
      </div>
      <div style={{ marginTop: 18, fontFamily: SANS, fontWeight: 500, fontSize: 28, lineHeight: 1.3, color: C.pale }}>12 kanal, tek stok, tek panel.</div>
    </div>
    <AbsoluteFill>
      <Orbit cx={905} cy={262} rx={225} ry={185} node={62} w={1200} h={627} scale={0.62} />
    </AbsoluteFill>
    <div style={{ position: "absolute", left: 0, right: 0, bottom: 0 }}>
      <Strip size={28} pad="24px 64px" />
    </div>
  </AbsoluteFill>
);
