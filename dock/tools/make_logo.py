"""Génère le logo et l'icône DockPolice.

- Bouclier avec dégradé bleu police
- 3 étoiles dorées en haut (symbole autorité)
- Barre dock stylisée avec 4 mini-icônes colorées
- Sortie : Assets/logo.png (1024) + Assets/DockPolice.ico (multi-taille)
"""
import math
import os
from PIL import Image, ImageDraw


def bezier(p0, p1, p2, t):
    return (
        (1 - t) ** 2 * p0[0] + 2 * (1 - t) * t * p1[0] + t ** 2 * p2[0],
        (1 - t) ** 2 * p0[1] + 2 * (1 - t) * t * p1[1] + t ** 2 * p2[1],
    )


def shield_polygon(w, h, pad):
    top_y = pad
    mid_y = int(h * 0.55)
    bot_y = h - pad

    points = [
        (pad, top_y),
        (w - pad, top_y),
        (w - pad, mid_y),
    ]

    right_mid = (w - pad, mid_y)
    right_ctrl = (w - pad, bot_y)
    bot_tip = (w // 2, bot_y)

    steps = 40
    for i in range(1, steps + 1):
        points.append(bezier(right_mid, right_ctrl, bot_tip, i / steps))

    left_ctrl = (pad, bot_y)
    left_mid = (pad, mid_y)
    for i in range(1, steps + 1):
        points.append(bezier(bot_tip, left_ctrl, left_mid, i / steps))

    return points


def gradient_bg(size, top_color, bot_color):
    img = Image.new("RGB", (size, size), top_color)
    draw = ImageDraw.Draw(img)
    for y in range(size):
        t = y / size
        c = tuple(int(top_color[i] * (1 - t) + bot_color[i] * t) for i in range(3))
        draw.line([(0, y), (size, y)], fill=c)
    return img


def draw_star(draw, cx, cy, radius, fill, outline=None):
    points = []
    for i in range(10):
        angle = -math.pi / 2 + i * math.pi / 5
        r = radius if i % 2 == 0 else radius * 0.42
        points.append((cx + r * math.cos(angle), cy + r * math.sin(angle)))
    if outline:
        draw.polygon(points, fill=fill, outline=outline)
    else:
        draw.polygon(points, fill=fill)


def render(size=1024):
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    pad = int(size * 0.08)
    poly = shield_polygon(size, size, pad)

    # Shield fill (blue gradient)
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).polygon(poly, fill=255)
    grad = gradient_bg(size, (70, 140, 230), (15, 35, 110)).convert("RGBA")
    grad.putalpha(mask)
    img = Image.alpha_composite(img, grad)

    draw = ImageDraw.Draw(img)

    # Outer white border + subtle inner dark edge
    draw.polygon(poly, outline=(255, 255, 255, 235), width=max(3, size // 160))
    inset_poly = [
        (x + (1 if x < size / 2 else -1) * (size // 96),
         y + (1 if y < size / 2 else -1) * (size // 96))
        for (x, y) in poly
    ]
    draw.polygon(inset_poly, outline=(0, 0, 40, 120), width=max(1, size // 384))

    # Stars
    star_r = int(size * 0.055)
    star_y = int(size * 0.22)
    star_gap = int(star_r * 2.6)
    for i in (-1, 0, 1):
        cx = size // 2 + i * star_gap
        draw_star(draw, cx, star_y, star_r, fill=(255, 215, 90, 255), outline=(180, 130, 30, 220))

    # Dock bar
    bar_w = int(size * 0.62)
    bar_h = int(size * 0.14)
    bar_x = (size - bar_w) // 2
    bar_y = int(size * 0.48)
    radius = bar_h // 3

    # Shadow
    shadow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).rounded_rectangle(
        (bar_x, bar_y + size // 96, bar_x + bar_w, bar_y + bar_h + size // 96),
        radius=radius,
        fill=(0, 0, 0, 140),
    )
    from PIL import ImageFilter
    shadow = shadow.filter(ImageFilter.GaussianBlur(size // 96))
    img = Image.alpha_composite(img, shadow)
    draw = ImageDraw.Draw(img)

    # Bar body
    draw.rounded_rectangle(
        (bar_x, bar_y, bar_x + bar_w, bar_y + bar_h),
        radius=radius,
        fill=(18, 22, 40, 235),
        outline=(255, 255, 255, 90),
        width=max(1, size // 384),
    )

    # Mini icons in the bar
    n = 4
    gap = int(bar_h * 0.22)
    icon_size = bar_h - 2 * gap
    total = n * icon_size + (n - 1) * gap
    ix = bar_x + (bar_w - total) // 2
    iy = bar_y + (bar_h - icon_size) // 2
    colors = [
        (232, 70, 70),
        (80, 200, 120),
        (255, 200, 70),
        (90, 170, 235),
    ]
    for i in range(n):
        x = ix + i * (icon_size + gap)
        draw.rounded_rectangle(
            (x, iy, x + icon_size, iy + icon_size),
            radius=icon_size // 4,
            fill=colors[i],
        )

    # Highlight reflection on top half of shield
    highlight = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    hl_draw = ImageDraw.Draw(highlight)
    hl_mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(hl_mask).polygon(poly, fill=60)
    hl_grad = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    hlg = ImageDraw.Draw(hl_grad)
    for y in range(int(size * 0.55)):
        a = int(90 * (1 - y / (size * 0.55)))
        hlg.line([(0, y), (size, y)], fill=(255, 255, 255, a))
    hl_grad.putalpha(hl_mask)
    img = Image.alpha_composite(img, hl_grad)

    return img


def main():
    out = os.path.join(os.path.dirname(__file__), "..", "DockLite", "Assets")
    out = os.path.abspath(out)
    os.makedirs(out, exist_ok=True)

    big = render(1024)
    big.save(os.path.join(out, "logo.png"))
    print(f"logo.png -> {out}")

    # Multi-size ICO
    sizes = [256, 128, 64, 48, 32, 24, 16]
    resized = [big.resize((s, s), Image.LANCZOS) for s in sizes]
    resized[0].save(
        os.path.join(out, "DockPolice.ico"),
        format="ICO",
        sizes=[(s, s) for s in sizes],
    )
    print(f"DockPolice.ico -> {out}")


if __name__ == "__main__":
    main()
