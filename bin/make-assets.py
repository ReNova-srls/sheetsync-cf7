"""Generates the WordPress.org icon and banners in .wordpress-org/.

Usage (from the repository root): python bin/make-assets.py
Needs Pillow. Uses the Segoe UI fonts on Windows, DejaVu Sans elsewhere.
"""
import math
import pathlib

from PIL import Image, ImageDraw, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / ".wordpress-org"
OUT.mkdir(exist_ok=True)

TOP = (19, 80, 91)       # background gradient, top
BOTTOM = (10, 42, 48)    # background gradient, bottom
GREEN = (34, 160, 107)   # sheet header
AMBER = (245, 183, 0)    # sync badge
GRID = (213, 222, 227)
WHITE = (255, 255, 255)
MINT = (207, 232, 228)


def font(bold, size):
    candidates = (
        ["C:/Windows/Fonts/segoeuib.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"]
        if bold
        else ["C:/Windows/Fonts/segoeui.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"]
    )
    for path in candidates:
        if pathlib.Path(path).exists():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def gradient(size, radius=0):
    w, h = size
    img = Image.new("RGBA", size)
    px = img.load()
    for y in range(h):
        t = y / max(1, h - 1)
        c = tuple(round(TOP[i] + (BOTTOM[i] - TOP[i]) * t) for i in range(3)) + (255,)
        for x in range(w):
            px[x, y] = c
    if radius:
        mask = Image.new("L", size, 0)
        ImageDraw.Draw(mask).rounded_rectangle((0, 0, w - 1, h - 1), radius, fill=255)
        img.putalpha(mask)
    return img


def sheet(draw, x0, y0, x1, y1, s):
    """White sheet with a green header row and a light grid."""
    r = 14 * s
    draw.rounded_rectangle((x0, y0, x1, y1), r, fill=WHITE)
    head = y0 + (y1 - y0) * 0.2
    draw.rounded_rectangle((x0, y0, x1, head), r, fill=GREEN)
    draw.rectangle((x0, head - r, x1, head), fill=GREEN)
    rows = 4
    for i in range(1, rows):
        y = head + (y1 - head) * i / rows
        draw.line((x0 + 8 * s, y, x1 - 8 * s, y), fill=GRID, width=max(2, round(3 * s)))
    xc = x0 + (x1 - x0) * 0.36
    draw.line((xc, head + 6 * s, xc, y1 - 8 * s), fill=GRID, width=max(2, round(3 * s)))


def sync_badge(draw, cx, cy, r, ring, ring_color):
    """Amber disc with two circular arrows."""
    draw.ellipse((cx - r - ring, cy - r - ring, cx + r + ring, cy + r + ring), fill=ring_color)
    draw.ellipse((cx - r, cy - r, cx + r, cy + r), fill=AMBER)
    ar = r * 0.55
    w = max(3, round(r * 0.16))
    box = (cx - ar, cy - ar, cx + ar, cy + ar)
    for start, end in ((200, 340), (20, 160)):
        draw.arc(box, start, end, fill=WHITE, width=w)
        # Arrow head at the end of the arc.
        a = math.radians(end)
        px, py = cx + ar * math.cos(a), cy + ar * math.sin(a)
        tangent = a + math.pi / 2
        size = w * 1.6
        tip = (px + size * math.cos(tangent), py + size * math.sin(tangent))
        left = (px + size * 0.9 * math.cos(a), py + size * 0.9 * math.sin(a))
        right = (px - size * 0.9 * math.cos(a), py - size * 0.9 * math.sin(a))
        draw.polygon((tip, left, right), fill=WHITE)


def icon(size):
    s = 4  # supersampling
    big = size * s
    img = gradient((big, big), radius=round(big * 0.19))
    d = ImageDraw.Draw(img)
    u = big / 512
    sheet(d, 104 * u, 92 * u, 392 * u, 404 * u, u)
    sync_badge(d, 382 * u, 384 * u, 86 * u, 12 * u, BOTTOM)
    return img.resize((size, size), Image.LANCZOS)


def form_card(draw, x0, y0, x1, y1, s):
    """White form with three fields and a button."""
    draw.rounded_rectangle((x0, y0, x1, y1), 14 * s, fill=WHITE)
    pad = 22 * s
    fh = (y1 - y0 - pad * 2) / 5.2
    y = y0 + pad
    for _ in range(3):
        draw.rounded_rectangle((x0 + pad, y, x1 - pad, y + fh), 6 * s, outline=GRID, width=max(2, round(3 * s)))
        y += fh * 1.35
    draw.rounded_rectangle((x0 + pad, y + fh * 0.1, x0 + pad + (x1 - x0) * 0.42, y + fh * 1.05), 6 * s, fill=GREEN)


def banner(w, h):
    s = 2
    W, H = w * s, h * s
    img = gradient((W, H))
    d = ImageDraw.Draw(img)
    u = H / 500  # layout designed on 1544x500

    form_card(d, 60 * u, 135 * u, 260 * u, 365 * u, u)
    # Arrow form -> sheet.
    ay = 250 * u
    d.line((280 * u, ay, 336 * u, ay), fill=MINT, width=round(10 * u))
    d.polygon(((362 * u, ay), (330 * u, ay - 22 * u), (330 * u, ay + 22 * u)), fill=MINT)
    sheet(d, 382 * u, 120 * u, 622 * u, 380 * u, u)
    sync_badge(d, 612 * u, 372 * u, 58 * u, 10 * u, BOTTOM)

    x = 720 * u
    room = W - x - 60 * u
    size = 92
    title = font(True, round(size * u))
    while d.textlength("SheetSync for CF7", font=title) > room and size > 40:
        size -= 2
        title = font(True, round(size * u))
    d.text((x, 130 * u), "SheetSync for CF7", font=title, fill=WHITE)
    tag = font(False, round(40 * u))
    d.text((x, 258 * u), "Contact Form 7 → Google Sheets", font=tag, fill=MINT)
    d.text((x, 310 * u), "in real time, with your own key", font=tag, fill=MINT)
    return img.convert("RGB").resize((w, h), Image.LANCZOS)


icon(256).save(OUT / "icon-256x256.png", optimize=True)
icon(128).save(OUT / "icon-128x128.png", optimize=True)
banner(1544, 500).save(OUT / "banner-1544x500.png", optimize=True)
banner(772, 250).save(OUT / "banner-772x250.png", optimize=True)
print("assets written to", OUT.relative_to(ROOT))
