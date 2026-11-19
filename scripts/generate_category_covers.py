# -*- coding: utf-8 -*-
"""为每个分类生成一张统一的竖版封面（3:4），替换掉逐条资料的封面。

同一分类下所有资料共用一张，卡片与详情页都按 3:4 显示，不会被裁切。
"""
import os
import math
import random
from PIL import Image, ImageDraw, ImageFont, ImageFilter

ROOT = "E:/知集科技"
OUT = os.path.join(ROOT, "market", "catcovers")
os.makedirs(OUT, exist_ok=True)

W, H = 750, 1000
FONT_BD = "C:/Windows/Fonts/msyhbd.ttc"
FONT_RG = "C:/Windows/Fonts/msyh.ttc"

# 分类 id -> (分类名, 英文, 图标, 主色深, 主色浅)
CATS = {
    5:  ("开发技能", "DEVELOPMENT", "code",   (23, 47, 122),  (43, 96, 199)),
    6:  ("设计创意", "DESIGN",      "design", (88, 28, 135),  (168, 62, 168)),
    7:  ("办公效率", "PRODUCTIVITY", "office", (10, 78, 80),   (13, 148, 136)),
    25: ("文案写作", "WRITING",     "write",  (122, 18, 62),  (190, 45, 96)),
    26: ("自媒体运营", "SOCIAL MEDIA", "social", (68, 25, 128), (128, 60, 200)),
    27: ("财务金融", "FINANCE",     "finance", (110, 52, 8),  (183, 108, 24)),
    28: ("人事行政", "HR & ADMIN",  "people",  (8, 62, 92),   (16, 118, 158)),
    29: ("营销销售", "MARKETING",   "sales",   (122, 32, 20), (198, 74, 38)),
    30: ("思维决策", "THINKING",    "think",   (42, 35, 128), (79, 70, 199)),
    31: ("电商运营", "E-COMMERCE",  "ecom",    (139, 20, 74), (236, 72, 153)),
    32: ("研学教育", "EDUCATION",   "edu",     (11, 66, 52),  (16, 148, 108)),
    33: ("生活家居", "HOME & LIVING", "home",  (92, 44, 14),  (168, 106, 20)),
    34: ("法务合规", "LEGAL",       "legal",   (24, 32, 46),  (58, 74, 98)),
    18: ("性格测试", "PERSONALITY", "star",    (44, 42, 120), (98, 92, 190)),
    20: ("情绪压力", "EMOTION",     "heart",   (14, 74, 96),  (24, 140, 170)),
    22: ("趣味测试", "FUN QUIZ",    "smile",   (96, 30, 100), (176, 72, 150)),
}


def gradient(c1, c2, angle, seed):
    """线性渐变 + 柔和光晕。"""
    small = Image.new("RGB", (256, 256))
    sd = ImageDraw.Draw(small)
    for x in range(256):
        t = x / 255
        col = tuple(int(c1[i] * (1 - t) + c2[i] * t) for i in range(3))
        sd.line([(x, 0), (x, 255)], fill=col)
    small = small.rotate(angle - 45, expand=False, resample=Image.BILINEAR)
    diag = int(math.hypot(W, H))
    big = small.resize((diag, diag), Image.BILINEAR)
    left, top = (diag - W) // 2, (diag - H) // 2
    img = big.crop((left, top, left + W, top + H)).convert("RGBA")

    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    rng = random.Random(seed)
    gx = rng.randint(W // 2, W + 60)
    gy = rng.randint(-40, H // 3)
    gd.ellipse([gx - 320, gy - 320, gx + 320, gy + 320], fill=(255, 255, 255, 48))
    gx2, gy2 = rng.randint(-60, W // 3), rng.randint(H // 2, H)
    gd.ellipse([gx2 - 260, gy2 - 260, gx2 + 260, gy2 + 260], fill=(255, 255, 255, 26))
    glow = glow.filter(ImageFilter.GaussianBlur(80))
    return Image.alpha_composite(img, glow)


def draw_icon(d, cx, cy, s, kind, c=(255, 255, 255, 240)):
    w = max(4, int(s * 0.055))
    if kind == "code":
        d.line([(cx - s * 0.22, cy - s * 0.28), (cx - s * 0.44, cy), (cx - s * 0.22, cy + s * 0.28)],
               fill=c, width=w, joint="curve")
        d.line([(cx + s * 0.22, cy - s * 0.28), (cx + s * 0.44, cy), (cx + s * 0.22, cy + s * 0.28)],
               fill=c, width=w, joint="curve")
        d.line([(cx + s * 0.08, cy - s * 0.33), (cx - s * 0.08, cy + s * 0.33)], fill=c, width=w)
    elif kind == "design":
        d.ellipse([cx - s * 0.42, cy - s * 0.42, cx + s * 0.42, cy + s * 0.42], outline=c, width=w)
        for ox, oy in [(-0.17, -0.11), (0.13, -0.16), (0.19, 0.13), (-0.11, 0.19)]:
            r = s * 0.075
            d.ellipse([cx + ox * s - r, cy + oy * s - r, cx + ox * s + r, cy + oy * s + r], fill=c)
    elif kind == "office":
        d.rounded_rectangle([cx - s * 0.4, cy - s * 0.44, cx + s * 0.4, cy + s * 0.44],
                            radius=int(s * 0.06), outline=c, width=w)
        d.line([(cx - s * 0.4, cy - s * 0.16), (cx + s * 0.4, cy - s * 0.16)], fill=c, width=w)
        for yy in (-0.31, 0.0, 0.22):
            d.ellipse([cx - s * 0.29, cy + yy * s - s * 0.04, cx - s * 0.21, cy + yy * s + s * 0.04], fill=c)
            d.line([(cx - s * 0.13, cy + yy * s), (cx + s * 0.25, cy + yy * s)], fill=c, width=max(3, w - 1))
    elif kind == "write":
        # 铅笔：笔身 + 笔尖 + 顶部箍
        d.rounded_rectangle([cx - s * 0.135, cy - s * 0.36, cx + s * 0.135, cy + s * 0.12],
                            radius=int(s * 0.02), outline=c, width=w)
        d.line([(cx - s * 0.135, cy - s * 0.22), (cx + s * 0.135, cy - s * 0.22)], fill=c, width=w)
        d.line([(cx - s * 0.135, cy + s * 0.12), (cx, cy + s * 0.4)], fill=c, width=w)
        d.line([(cx + s * 0.135, cy + s * 0.12), (cx, cy + s * 0.4)], fill=c, width=w)
    elif kind == "social":
        pts = [(-0.31, -0.24), (0.29, -0.3), (0.37, 0.22), (-0.23, 0.31), (0.02, 0.0)]
        for a, b in [(0, 4), (1, 4), (2, 4), (3, 4), (0, 1), (2, 3)]:
            d.line([(cx + pts[a][0] * s, cy + pts[a][1] * s), (cx + pts[b][0] * s, cy + pts[b][1] * s)],
                   fill=(255, 255, 255, 150), width=max(3, w - 2))
        for i, (ox, oy) in enumerate(pts):
            r = s * (0.095 if i == 4 else 0.07)
            d.ellipse([cx + ox * s - r, cy + oy * s - r, cx + ox * s + r, cy + oy * s + r], fill=c)
    elif kind == "finance":
        for x0, h in [(-0.34, 0.3), (-0.09, 0.5), (0.16, 0.68)]:
            d.rounded_rectangle([cx + (x0 - 0.075) * s, cy - h * s, cx + (x0 + 0.075) * s, cy + 0.36 * s],
                                radius=int(s * 0.02), fill=c)
        d.line([(cx - s * 0.42, cy + 0.36 * s), (cx + s * 0.42, cy + 0.36 * s)], fill=c, width=w)
    elif kind == "people":
        d.ellipse([cx - s * 0.34, cy - s * 0.4, cx - s * 0.06, cy - s * 0.12], outline=c, width=w)
        d.arc([cx - s * 0.42, cy - s * 0.06, cx + s * 0.02, cy + s * 0.4], 180, 360, fill=c, width=w)
        d.ellipse([cx + s * 0.06, cy - s * 0.34, cx + s * 0.3, cy - s * 0.1], outline=c, width=w)
        d.arc([cx + s * 0.02, cy - s * 0.02, cx + s * 0.42, cy + s * 0.38], 180, 360, fill=c, width=w)
    elif kind == "sales":
        d.line([(cx - s * 0.38, cy + s * 0.34), (cx - s * 0.12, cy + s * 0.02),
                (cx + s * 0.06, cy + s * 0.16), (cx + s * 0.4, cy - s * 0.3)], fill=c, width=w, joint="curve")
        d.line([(cx + s * 0.16, cy - s * 0.32), (cx + s * 0.42, cy - s * 0.32), (cx + s * 0.42, cy - s * 0.06)],
               fill=c, width=w, joint="curve")
    elif kind == "think":
        # 决策树：一个起点分叉到三个结果
        d.line([(cx - s * 0.34, cy), (cx - s * 0.02, cy)], fill=c, width=w)
        for oy in (-0.3, 0.0, 0.3):
            d.line([(cx - s * 0.02, cy), (cx + s * 0.24, cy + oy * s)], fill=c, width=w, joint="curve")
            r = s * 0.075
            d.ellipse([cx + s * 0.26 - r, cy + oy * s - r, cx + s * 0.26 + r, cy + oy * s + r], fill=c)
        r0 = s * 0.08
        d.ellipse([cx - s * 0.34 - r0, cy - r0, cx - s * 0.34 + r0, cy + r0], fill=c)
    elif kind == "star":
        # 五角星：性格/人格
        pts = []
        for i in range(10):
            ang = math.pi / 2 + i * math.pi / 5
            rr = s * (0.42 if i % 2 == 0 else 0.18)
            pts.append((cx + rr * math.cos(ang), cy - rr * math.sin(ang)))
        d.polygon(pts, outline=c, width=w)
    elif kind == "smile":
        # 笑脸：趣味测试
        d.ellipse([cx - s * 0.42, cy - s * 0.42, cx + s * 0.42, cy + s * 0.42], outline=c, width=w)
        for ox in (-0.15, 0.15):
            r = s * 0.045
            d.ellipse([cx + ox * s - r, cy - s * 0.14 - r, cx + ox * s + r, cy - s * 0.14 + r], fill=c)
        d.arc([cx - s * 0.24, cy - s * 0.1, cx + s * 0.24, cy + s * 0.26], 20, 160, fill=c, width=w)
    elif kind == "heart":
        # 心形：情绪压力
        r = s * 0.21
        d.arc([cx - 2 * r, cy - r * 1.5, cx, cy + r * 0.5], 150, 360, fill=c, width=w)
        d.arc([cx, cy - r * 1.5, cx + 2 * r, cy + r * 0.5], 180, 30, fill=c, width=w)
        d.line([(cx - r * 0.98, cy - r * 0.02), (cx, cy + s * 0.36)], fill=c, width=w)
        d.line([(cx + r * 0.98, cy - r * 0.02), (cx, cy + s * 0.36)], fill=c, width=w)
    elif kind == "home":
        d.line([(cx - s * 0.46, cy - s * 0.02), (cx, cy - s * 0.42), (cx + s * 0.46, cy - s * 0.02)],
               fill=c, width=w, joint="curve")
        d.line([(cx - s * 0.33, cy - s * 0.06), (cx - s * 0.33, cy + s * 0.38)], fill=c, width=w)
        d.line([(cx + s * 0.33, cy - s * 0.06), (cx + s * 0.33, cy + s * 0.38)], fill=c, width=w)
        d.line([(cx - s * 0.33, cy + s * 0.38), (cx + s * 0.33, cy + s * 0.38)], fill=c, width=w)
        d.rounded_rectangle([cx - s * 0.1, cy + s * 0.1, cx + s * 0.1, cy + s * 0.38],
                            radius=int(s * 0.02), outline=c, width=max(3, w - 1))
    elif kind == "legal":
        d.line([(cx, cy - s * 0.4), (cx, cy + s * 0.32)], fill=c, width=w)
        d.line([(cx - s * 0.36, cy - s * 0.28), (cx + s * 0.36, cy - s * 0.28)], fill=c, width=w)
        d.line([(cx - s * 0.24, cy + s * 0.36), (cx + s * 0.24, cy + s * 0.36)], fill=c, width=w)
        for sx in (-1, 1):
            ex = cx + sx * s * 0.36
            d.line([(ex, cy - s * 0.28), (ex - s * 0.11, cy - s * 0.02)], fill=c, width=max(3, w - 1))
            d.line([(ex, cy - s * 0.28), (ex + s * 0.11, cy - s * 0.02)], fill=c, width=max(3, w - 1))
            d.arc([ex - s * 0.13, cy - s * 0.05, ex + s * 0.13, cy + s * 0.11], 0, 180, fill=c, width=max(3, w - 1))
    elif kind == "ecom":
        d.line([(cx - s * 0.36, cy - s * 0.24), (cx + s * 0.36, cy - s * 0.24)], fill=c, width=w)
        d.rounded_rectangle([cx - s * 0.33, cy - s * 0.24, cx + s * 0.33, cy + s * 0.38],
                            radius=int(s * 0.05), outline=c, width=w)
        d.arc([cx - s * 0.15, cy - s * 0.44, cx + s * 0.15, cy - s * 0.1], 180, 360, fill=c, width=w)
    elif kind == "edu":
        d.polygon([(cx, cy - s * 0.38), (cx + s * 0.42, cy - s * 0.14), (cx, cy + s * 0.1),
                   (cx - s * 0.42, cy - s * 0.14)], outline=c)
        d.line([(cx - s * 0.42, cy - s * 0.14), (cx - s * 0.42, cy + s * 0.2)], fill=c, width=w)
        d.arc([cx - s * 0.3, cy + s * 0.02, cx + s * 0.3, cy + s * 0.42], 0, 180, fill=c, width=w)


def wrap_center(d, text, font, max_w):
    """中文按字数均分折行。"""
    n = len(text)

    def fit(w):
        lines, cur = [], ""
        for ch in text:
            t = cur + ch
            if d.textlength(t, font=font) > w and cur:
                lines.append(cur)
                cur = ch
            else:
                cur = t
        if cur:
            lines.append(cur)
        return lines

    lines = fit(max_w)
    if len(lines) > 1:
        for k in range(2, min(4, n) + 1):
            size = -(-n // k)
            cand = [text[i * size:(i + 1) * size] for i in range(k)]
            cand = [c for c in cand if c]
            if all(d.textlength(c, font=font) <= max_w for c in cand):
                return cand
    return lines


def make(cid, name, en, icon, c1, c2, idx, total):
    seed = cid * 37
    img = gradient(c1, c2, 118, seed)

    # 半透明元素必须先画在独立图层再合成，否则 alpha 会被直接截断
    ov = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    # 极淡的斜向细纹，增加质感
    for i in range(-H, W, 46):
        d.line([(i, 0), (i + H, H)], fill=(255, 255, 255, 14), width=1)

    # 顶部：品牌 + 分类英文
    d.text((58, 46), "知集科技", font=ImageFont.truetype(FONT_BD, 26), fill=(255, 255, 255, 215))
    d.text((56, 90), en, font=ImageFont.truetype(FONT_RG, 18), fill=(255, 255, 255, 135))

    # 中部图标：大圆盘衬托，缩到卡片缩略图也认得出
    cx, cy, R = W // 2, 430, 244
    d.ellipse([cx - R, cy - R, cx + R, cy + R], fill=(255, 255, 255, 38))
    d.ellipse([cx - R, cy - R, cx + R, cy + R], outline=(255, 255, 255, 80), width=2)
    draw_icon(d, cx, cy, 300, icon)

    # 分类名（居中大字）
    ft = ImageFont.truetype(FONT_BD, 84)
    lines = wrap_center(d, name, ft, W - 90)
    y = 800 if len(lines) == 1 else 742
    for ln in lines:
        tw = d.textlength(ln, font=ft)
        d.text((cx - tw / 2 + 4, y + 5), ln, font=ft, fill=(0, 0, 0, 55))
        d.text((cx - tw / 2, y), ln, font=ft, fill=(255, 255, 255, 245))
        y += 108

    img = Image.alpha_composite(img, ov)
    path = os.path.join(OUT, f"cat-{cid}.png")
    img.convert("RGB").save(path, "PNG", optimize=True)
    return path


items = sorted(CATS.items())
for i, (cid, (name, en, icon, c1, c2)) in enumerate(items, 1):
    p = make(cid, name, en, icon, c1, c2, i, len(items))
    print(os.path.basename(p), os.path.getsize(p) // 1024, "KB", name)
