#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""为 265 个 skill 生成吸引人的封面。

设计：9 个分类各有主题配色 + 专属图标；每个 skill 用标题 hash 决定
渐变角度、装饰图案位置和强调色，做到统一品牌感又不重复。
输出 1200x750 PNG。
"""
import os, json, hashlib, math, random
from PIL import Image, ImageDraw, ImageFont, ImageFilter

OUT = os.environ.get("COVER_OUT_DIR", "skill-covers")
os.makedirs(OUT, exist_ok=True)
FONT_BD = "C:/Windows/Fonts/msyhbd.ttc"
FONT_RG = "C:/Windows/Fonts/msyh.ttc"

W, H = 1000, 625

# 分类主题：(主色, 辅色, 图标类型, 英文名)
THEMES = {
    "开发技能":   ((30, 64, 175),  (58, 130, 246),  "code",    "DEVELOPMENT"),
    "设计创意":   ((-1, -1, -1),   (-1, -1, -1),    "design",  "DESIGN"),  # 特殊：多色
    "办公效率":   ((15, 118, 110), (20, 184, 166),  "office",  "PRODUCTIVITY"),
    "文案写作":   ((190, 24, 93),  (233, 87, 131),  "write",   "WRITING"),
    "自媒体运营": ((124, 45, 210), (167, 87, 240),  "social",  "SOCIAL MEDIA"),
    "财务金融":   ((180, 83, 9),   (234, 140, 30),  "finance", "FINANCE"),
    "人事行政":   ((2, 105, 145),  (14, 165, 205),  "people",  "HR & ADMIN"),
    "营销销售":   ((199, 56, 28),  (240, 122, 45),  "sales",   "MARKETING"),
    "思维决策":   ((67, 56, 202),  (99, 102, 241),  "think",   "THINKING"),
}
DESIGN_COLORS = [
    ((225, 29, 72), (249, 115, 22)), ((124, 58, 237), (236, 72, 153)),
    ((13, 148, 136), (16, 185, 129)), ((37, 99, 235), (14, 165, 233)),
    ((217, 70, 239), (79, 70, 229)),
]


def hexc(c):
    return tuple(int(x) for x in c)


def theme_for(cat):
    c1, c2, icon, en = THEMES.get(cat, THEMES["办公效率"])
    if icon == "design":
        c1, c2 = DESIGN_COLORS[hash(cat) % len(DESIGN_COLORS)]
    return c1, c2, icon, en


def gradient(c1, c2, angle, seed):
    """渐变 + 柔和光晕。用小图渐变线 + 旋转缩放，避免逐像素 Python 循环。"""
    small = Image.new("RGB", (256, 256))
    sd = ImageDraw.Draw(small)
    # 横向线性渐变画 256 条线
    for x in range(256):
        t = x / 255
        col = tuple(int(c1[i] * (1 - t) + c2[i] * t) for i in range(3))
        sd.line([(x, 0), (x, 255)], fill=col)
    # 旋转到目标角度并放大裁切铺满
    small = small.rotate(angle - 45, expand=False, resample=Image.BILINEAR)
    diag = int(math.hypot(W, H))
    big = small.resize((diag, diag), Image.BILINEAR)
    left, top = (diag - W) // 2, (diag - H) // 2
    img = big.crop((left, top, left + W, top + H)).convert("RGBA")
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    gx = random.Random(seed).randint(W // 2, W)
    gy = random.Random(seed + 1).randint(0, H // 2)
    gd.ellipse([gx - 260, gy - 260, gx + 260, gy + 260], fill=(255, 255, 255, 55))
    glow = glow.filter(ImageFilter.GaussianBlur(70))
    return Image.alpha_composite(img, glow)


def draw_icon(d, cx, cy, size, kind, color=(255, 255, 255, 235)):
    """在 (cx,cy) 绘制分类专属矢量图标。"""
    s = size
    w = max(3, int(s * 0.055))
    c = color
    if kind == "code":
        # 尖括号 </>
        d.line([(cx - s*0.22, cy - s*0.28), (cx - s*0.42, cy), (cx - s*0.22, cy + s*0.28)], fill=c, width=w, joint="curve")
        d.line([(cx + s*0.22, cy - s*0.28), (cx + s*0.42, cy), (cx + s*0.22, cy + s*0.28)], fill=c, width=w, joint="curve")
        d.line([(cx + s*0.06, cy - s*0.32), (cx - s*0.06, cy + s*0.32)], fill=c, width=w)
    elif kind == "design":
        # 调色板圆点 + 笔
        d.ellipse([cx - s*0.4, cy - s*0.4, cx + s*0.4, cy + s*0.4], outline=c, width=w)
        for i, (ox, oy) in enumerate([(-0.15,-0.1),(0.12,-0.15),(0.18,0.12),(-0.1,0.18)]):
            r = s*0.075
            d.ellipse([cx+ox*s-r, cy+oy*s-r, cx+ox*s+r, cy+oy*s+r], fill=c)
    elif kind == "office":
        # 清单表格
        d.rounded_rectangle([cx - s*0.38, cy - s*0.4, cx + s*0.38, cy + s*0.4], radius=int(s*0.06), outline=c, width=w)
        d.line([(cx - s*0.38, cy - s*0.12), (cx + s*0.38, cy - s*0.12)], fill=c, width=w)
        for yy in (-0.26, 0.02, 0.22):
            d.ellipse([cx - s*0.28, cy + yy*s - s*0.035, cx - s*0.21, cy + yy*s + s*0.035], fill=c)
            d.line([(cx - s*0.14, cy + yy*s), (cx + s*0.24, cy + yy*s)], fill=c, width=max(2,w-1))
    elif kind == "write":
        # 钢笔
        d.polygon([(cx - s*0.28, cy + s*0.34), (cx - s*0.12, cy - s*0.34),
                   (cx + s*0.12, cy - s*0.34), (cx + s*0.06, cy + s*0.16),
                   (cx, cy + s*0.4)], outline=c)
        d.line([(cx - s*0.12, cy - s*0.05), (cx + s*0.09, cy - s*0.05)], fill=c, width=w)
    elif kind == "social":
        # 节点网络
        pts = [(-0.3,-0.22),(0.28,-0.28),(0.36,0.2),(-0.22,0.3),(0.02,0.0)]
        for a, b in [(0,4),(1,4),(2,4),(3,4),(0,1),(2,3)]:
            d.line([(cx+pts[a][0]*s, cy+pts[a][1]*s), (cx+pts[b][0]*s, cy+pts[b][1]*s)], fill=(255,255,255,140), width=max(2,w-2))
        for i,(ox,oy) in enumerate(pts):
            r = s*(0.09 if i==4 else 0.065)
            d.ellipse([cx+ox*s-r, cy+oy*s-r, cx+ox*s+r, cy+oy*s+r], fill=c)
    elif kind == "finance":
        # 上升柱状 + 箭头
        for i,(x0,h) in enumerate([(-0.32,0.3),(-0.08,0.48),(0.16,0.62)]):
            d.rounded_rectangle([cx+(x0-0.07)*s, cy-(h)*s, cx+(x0+0.07)*s, cy+0.34*s], radius=int(s*0.02), fill=c)
        d.line([(cx - s*0.38, cy + s*0.34), (cx + s*0.4, cy + s*0.34)], fill=c, width=w)
        d.line([(cx - s*0.05, cy - s*0.4), (cx + s*0.3, cy - s*0.18), (cx + s*0.14, cy - s*0.16)], fill=c, width=w)
        d.line([(cx + s*0.3, cy - s*0.18), (cx + s*0.2, cy - s*0.36)], fill=c, width=w)
    elif kind == "people":
        # 三个人
        for ox, oy, sc in [(0,-0.18,1.0),(-0.26,0.02,0.72),(0.26,0.02,0.72)]:
            r = s*0.1*sc
            d.ellipse([cx+ox*s-r, cy+oy*s-r-s*0.12, cx+ox*s+r, cy+oy*s+r-s*0.12], fill=c)
            d.ellipse([cx+ox*s-r*1.5, cy+oy*s+r*0.5, cx+ox*s+r*1.5, cy+oy*s+r*2.2], fill=c)
    elif kind == "sales":
        # 喇叭
        d.polygon([(cx - s*0.36, cy - s*0.12), (cx - s*0.16, cy - s*0.12),
                   (cx + s*0.16, cy - s*0.38), (cx + s*0.16, cy + s*0.38),
                   (cx - s*0.16, cy + s*0.12), (cx - s*0.36, cy + s*0.12)], fill=c)
        d.arc([cx + s*0.2, cy - s*0.2, cx + s*0.44, cy + s*0.2], 290, 70, fill=c, width=w)
    elif kind == "think":
        # 灯泡
        d.ellipse([cx - s*0.24, cy - s*0.4, cx + s*0.24, cy + s*0.06], outline=c, width=w)
        d.line([(cx - s*0.13, cy + s*0.1), (cx + s*0.13, cy + s*0.1)], fill=c, width=w)
        d.line([(cx - s*0.1, cy + s*0.2), (cx + s*0.1, cy + s*0.2)], fill=c, width=w)
        d.line([(cx, cy - s*0.28), (cx - s*0.12, cy - s*0.06), (cx + s*0.12, cy - s*0.06), (cx, cy - s*0.28)], fill=c, width=max(2,w-2))
    else:
        d.ellipse([cx - s*0.3, cy - s*0.3, cx + s*0.3, cy + s*0.3], outline=c, width=w)


def wrap_text(draw, text, font, max_w):
    lines, cur = [], ""
    for ch in text:
        t = cur + ch
        if draw.textlength(t, font=font) > max_w and cur:
            lines.append(cur); cur = ch
        else:
            cur = t
    if cur: lines.append(cur)
    return lines


def gen(cat, title, idx):
    c1, c2, icon, en = theme_for(cat)
    h = int(hashlib.md5((cat + title).encode()).hexdigest(), 16)
    rng = random.Random(h)
    angle = rng.choice([20, 35, 45, 60, 200, 225])
    img = gradient(c1, c2, angle, h % 10000).convert("RGBA")
    d = ImageDraw.Draw(img, "RGBA")

    # 装饰几何
    mode = h % 4
    if mode == 0:
        for i in range(6):
            r = 90 + i * 55
            d.ellipse([W-120-r, -80-r, W-120+r, -80+r], outline=(255,255,255,22), width=2)
    elif mode == 1:
        for i in range(9):
            x = W - 520 + i * 62
            d.line([(x, 0), (x-260, H)], fill=(255,255,255,14), width=2)
    elif mode == 2:
        for i in range(5):
            y = 90 + i * 130
            d.rounded_rectangle([W-360, y, W-40, y+26], radius=13, fill=(255,255,255,18))
    else:
        for i in range(14):
            x = rng.randint(W//2, W); y = rng.randint(30, H - 120)
            r = rng.randint(3, 9)
            d.ellipse([x-r, y-r, x+r, y+r], fill=(255,255,255,40))

    # 图标（右侧）
    draw_icon(d, W - 175, H // 2 - 8, 200, icon, (255, 255, 255, 240))

    # 分类胶囊标签（深色底 + 白字，保证可读）
    fcat = ImageFont.truetype(FONT_BD, 26)
    tw = d.textlength(cat, font=fcat)
    d.rounded_rectangle([58, 52, 58 + tw + 48, 104], radius=26, fill=(0, 0, 0, 70))
    d.text((80, 58), cat, font=fcat, fill=(255, 255, 255, 255))

    # 英文小标
    fen = ImageFont.truetype(FONT_RG, 19)
    d.text((60, 116), en, font=fen, fill=(255, 255, 255, 175))

    # 主标题（自适应换行，最多3行）
    ftitle = ImageFont.truetype(FONT_BD, 56)
    lines = wrap_text(d, title, ftitle, W - 400)[:3]
    y0 = H // 2 - len(lines) * 40
    # 标题描边阴影提升可读性
    for i, ln in enumerate(lines):
        d.text((60 + 3, y0 + i * 78 + 3), ln, font=ftitle, fill=(0, 0, 0, 60))
        d.text((60, y0 + i * 78), ln, font=ftitle, fill=(255, 255, 255, 255))

    # 底部品牌 + 序号
    d.text((60, H - 68), "知集科技 · AI 技能库", font=ImageFont.truetype(FONT_RG, 24), fill=(255,255,255,205))
    num = f"{idx:03d}"
    d.text((W - 120, H - 68), num, font=ImageFont.truetype(FONT_RG, 24), fill=(255,255,255,150))

    img = img.convert("RGB")
    safe = "".join(ch if ch.isalnum() or ch in "-_" else "_" for ch in title)[:40]
    path = f"{OUT}/{idx:03d}_{safe}.png"
    img.save(path, "PNG", optimize=True)
    return path


catalog = json.load(open(os.environ.get("CATALOG_FILE", "docs/skills-catalog-zh.json"), encoding="utf-8"))
manifest = []
for i, item in enumerate(catalog, 1):
    p = gen(item["category"], item["title"], i)
    manifest.append({"id": i, "title": item["title"], "category": item["category"], "cover": p, "size": os.path.getsize(p)})

json.dump(manifest, open(os.path.join(OUT, "manifest.json"), "w"), ensure_ascii=False, indent=1)
print("generated:", len(manifest))
print("size MB:", round(sum(m["size"] for m in manifest) / 1024 / 1024, 2))
