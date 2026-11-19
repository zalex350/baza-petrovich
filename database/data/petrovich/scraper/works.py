"""«Наши работы» и «Отзывы» со старого baza-petrovich.by → ../works.json.

python3 works.py CRAWL_DIR EXTRA_DIR ../works.json
CRAWL_DIR — обход crawl.py (страницы nashi-raboty/*), EXTRA_DIR — скачанные страницы отзывов
(otzyvy.html, r-*.html со ссылками на сканы).
"""
import html as H
import json
import re
import sys

BASE = 'https://baza-petrovich.by/'
crawl, extra, out = sys.argv[1], sys.argv[2], sys.argv[3]
idx = json.load(open(crawl + '/index.json'))


def txt(s):
    return re.sub(r'\s+', ' ', H.unescape(re.sub(r'<[^>]+>', ' ', s))).strip()


def clean(seg):
    seg = re.sub(r'<(script|style|svg|form|button|iframe)\b.*?</\1>', '', seg, flags=re.S)
    seg = re.sub(r'<img[^>]*>', '', seg)
    seg = re.sub(r'<a\b[^>]*>(.*?)</a>', r'\1', seg, flags=re.S)
    seg = re.sub(r'<(/?)(p|ul|ol|li|h2|h3|h4|strong|b|em|i|br)\b[^>]*>', r'<\1\2>', seg)
    seg = re.sub(r'</?(?!(?:p|ul|ol|li|h2|h3|h4|strong|b|em|i|br)\b)[a-zA-Z][^>]*>', '', seg)
    seg = re.sub(r'<(p|li|h2|h3|h4|strong|b)>\s*</\1>', '', seg)
    return re.sub(r'\s+', ' ', seg).strip()


works = []
for path, f in idx.items():
    if not path.startswith('nashi-raboty/') or path == 'nashi-raboty/':
        continue
    h = open(crawl + '/pages/' + f, encoding='utf-8').read()
    m = re.search(r'<h1[^>]*>(.*?)</h1>', h, re.S)
    i = h.find('content_page')
    j = h.find('top-block', i)
    seg = h[i:j]
    start = seg.find('</h1>') + 5 if '</h1>' in seg else 0
    body = seg[start:]
    images = []
    for u in re.findall(r'<a[^>]+href="([^"]+\.(?:jpe?g|png|webp))"', body, re.I):
        u = BASE + u.lstrip('/') if not u.startswith('http') else u
        if u not in images:
            images.append(u)
    if not images:
        for u in re.findall(r'<img[^>]+src="([^"]+\.(?:jpe?g|png|webp))"', body, re.I):
            u = BASE + u.lstrip('/') if not u.startswith('http') else u
            if 'templates/' not in u and u not in images:
                images.append(u)
    text = clean(re.split(r'class="[^"]*gallery', body)[0])
    slug_src = path.strip('/').split('/')[-1]
    works.append({'source_key': path.strip('/'), 'title': txt(m.group(1)) if m else slug_src, 'description': text, 'images': images[:30]})

reviews = []
groups = {'r-dostavka-_-prodazha-metalla.html': 'Доставка и продажа металла', 'r-otzyvy-zabory-pod-klyuch.html': 'Заборы «под ключ»'}
for f, group in groups.items():
    h = open(extra + '/' + f, encoding='utf-8').read()
    i = h.find('content_page')
    j = h.find('top-block', i)
    for n, u in enumerate(re.findall(r'<a[^>]+href="([^"]+\.(?:jpe?g|png))"[^>]*>\s*<img', h[i:j])):
        reviews.append({'source_key': f'{f[2:-5]}-{n + 1}', 'group': group, 'image': BASE + u.lstrip('/')})

json.dump({'works': works, 'reviews': reviews, 'yandex_maps': 'https://yandex.by/maps/org/std_petrovich/1726129679/'},
          open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print('works', len(works), 'images', sum(len(w['images']) for w in works), 'reviews', len(reviews))
