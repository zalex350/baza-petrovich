"""Фото разделов каталога в едином стиле: database/data/petrovich/category-photos/src → {раздел}.jpg.

Настройки — photos.json рядом с src/: crop [x1, y1, x2, y2] (4:3) или fit = pad (товар на белом фоне).
Единый стиль: 1200×900 (4:3, как плитка), автоконтраст, приглушённая насыщенность, нейтральный тон,
лёгкая резкость; товары на белом фоне — по центру на фоне «светлая сталь» сайта (#E6EAF0).
Запуск: python scripts/category-photos.py  →  затем php artisan petrovich:category-photos --force
"""
import json
import os

from PIL import Image, ImageEnhance, ImageFilter, ImageOps

DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'database', 'data', 'petrovich', 'category-photos')
W, H = 1200, 900
BG = (230, 234, 240)


def grade(im):
    im = ImageOps.autocontrast(im, cutoff=1)
    im = ImageEnhance.Color(im).enhance(0.88)
    im = ImageEnhance.Contrast(im).enhance(1.06)
    # Нейтральный, чуть холодный тон «стали»: лёгкое смешение с серо-голубым
    tint = Image.new('RGB', im.size, (120, 130, 145))
    im = Image.blend(im, tint, 0.06)
    return im.filter(ImageFilter.UnsharpMask(radius=1.2, percent=60, threshold=2))


def pad(im):
    # Белый фон товара → прозрачность не нужна: товар вписывается в 78 % рамки на фоне сайта
    bbox = ImageOps.invert(im.convert('L')).point(lambda v: 255 if v > 18 else 0).getbbox() or (0, 0, *im.size)
    im = im.crop(bbox)
    scale = min(W * 0.78 / im.width, H * 0.82 / im.height)
    im = im.resize((round(im.width * scale), round(im.height * scale)), Image.LANCZOS)
    # Белый фон товара перекрашивается в фон сайта
    mask = im.convert('L').point(lambda v: 0 if v > 242 else 255).filter(ImageFilter.GaussianBlur(1))
    canvas = Image.new('RGB', (W, H), BG)
    canvas.paste(im, ((W - im.width) // 2, (H - im.height) // 2), mask)
    return canvas


def main():
    conf = json.load(open(os.path.join(DIR, 'photos.json'), encoding='utf-8'))
    for key, c in conf.items():
        if key.startswith('_'):
            continue
        im = Image.open(os.path.join(DIR, 'src', c['src'])).convert('RGB')
        if c.get('fit') == 'pad':
            out = pad(grade(im))
        else:
            out = grade(im.crop(tuple(c['crop'])).resize((W, H), Image.LANCZOS))
        out.save(os.path.join(DIR, key + '.jpg'), 'JPEG', quality=84, optimize=True, progressive=True)
        print(key, out.size)


main()
