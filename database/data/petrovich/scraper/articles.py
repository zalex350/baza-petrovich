"""Статьи со старого baza-petrovich.by (/news/*, /sovet/*) → ../articles.json.

python3 articles.py SITEMAP_URLS_TXT CACHE_DIR ../articles.json

SITEMAP_URLS_TXT — адреса из https://baza-petrovich.by/sitemap.xml (по строке, с lastmod через пробел),
CACHE_DIR — кэш скачанных страниц. Устаревшие новости (графики работы, поздравления, вакансии,
розыгрыши, разовые акции) не переносятся: skip = причина, адрес ведёт на /stati (LegacyUrls).
Картинки — абсолютные адреса старого сайта: команда petrovich:articles --images скачивает их к себе.
"""
import html as H
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

BASE = 'https://baza-petrovich.by/'
src, cache, out = sys.argv[1], sys.argv[2], sys.argv[3]
os.makedirs(cache, exist_ok=True)

# Не переносятся: потеряли смысл (даты, люди, разовые события)
OUTDATED = re.compile(
    r'график|режим[- ]?раб|праздник|поздрав|с рождеств|новым год|новогод|рождеств|8 марта|23 феврал|защитник|'
    r'женск|знаний|пожарн|вакан|ищем|ищет|розыгрыш|победител|сертификат|прямой эфир|covid|коронав|инвентаризац|'
    r'выходные|итоги|казан|черная неделя|чёрная неделя|распродаж|до \d|раннее бронирование|бережливый|сезон продолжа|'
    r'новые условия|новост|news\d|подарки|предложение недели|рабочие и|посещение|успей|зима близко|сезонные скидки|'
    r'независимост|отзывы клиентов|видеоотзыв|кредитован|теперь в наличии',
    re.I)


def fetch(url):
    name = re.sub(r'[^a-z0-9]+', '_', url.lower())[-150:] + '.html'
    path = os.path.join(cache, name)
    if os.path.exists(path):
        return open(path, encoding='utf-8', errors='ignore').read()
    url = urllib.parse.quote(url, safe=':/?&=%#!*,.~-_')
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (compatible; catalog-sync)'})
    try:
        body = urllib.request.urlopen(req, timeout=30).read().decode('utf-8', 'ignore')
    except Exception as e:  # noqa: BLE001
        print('ERR', url, e, file=sys.stderr)
        return ''
    open(path, 'w', encoding='utf-8').write(body)
    time.sleep(0.4)
    return body


def txt(s):
    return re.sub(r'\s+', ' ', H.unescape(re.sub(r'<[^>]+>', ' ', s))).strip()


def clean(seg, page_url, images):
    seg = re.sub(r'<(script|style|svg|form|button)\b.*?</\1>', '', seg, flags=re.S | re.I)
    seg = re.sub(r'<!--.*?-->', '', seg, flags=re.S)

    # YouTube — ссылкой (iframe на сайте очищается)
    def iframe(m):
        src = re.search(r'src="([^"]+)"', m.group(0))
        if src and 'youtube' in src.group(1):
            vid = re.search(r'(?:embed/|v=)([\w-]{6,})', src.group(1))
            if vid:
                return f'<p><a href="https://www.youtube.com/watch?v={vid.group(1)}">Смотреть видео на YouTube</a></p>'
        return ''
    seg = re.sub(r'<iframe\b.*?</iframe>|<iframe[^>]*>', iframe, seg, flags=re.S | re.I)

    # Картинки — в список, в тексте метка {{img:N}} (команда подставит свой адрес)
    def img(m):
        s = re.search(r'src="([^"]+)"', m.group(0))
        if not s:
            return ''
        url = urllib.parse.urljoin(BASE, H.unescape(s.group(1)))
        if url not in images:
            images.append(url)
        return '{{img:%d}}' % images.index(url)
    seg = re.sub(r'<img[^>]*>', img, seg, flags=re.I)

    # Ссылки: внутренние — относительными (старые адреса редиректит сайт), внешние — как есть
    def link(m):
        href = re.search(r'href="([^"]+)"', m.group(0))
        inner = m.group(2)
        if not href or href.group(1).startswith(('mailto:', 'tel:', 'javascript')):
            return inner
        url = urllib.parse.urljoin(page_url, H.unescape(href.group(1)))
        if url.startswith(BASE) or url.startswith('http://baza-petrovich.by/'):
            url = '/' + url.split('baza-petrovich.by/', 1)[1]
        return f'<a href="{H.escape(url)}">{inner}</a>'
    seg = re.sub(r'<a\b([^>]*)>(.*?)</a>', link, seg, flags=re.S | re.I)

    keep = r'p|ul|ol|li|h2|h3|h4|strong|b|em|i|br|a|table|thead|tbody|tr|td|th'
    seg = re.sub(r'<(/?)(%s)\b(?:(?!href)[^>])*?(\s+href="[^"]*")?[^>]*>' % keep, r'<\1\2\3>', seg, flags=re.I)
    seg = re.sub(r'</?(?!(?:%s)\b)[a-zA-Z][^>]*>' % keep, '', seg)
    seg = seg.replace('&nbsp;', ' ')
    seg = re.sub(r'<(p|li|h2|h3|h4|strong|b|em|i)>\s*</\1>', '', seg)
    seg = re.sub(r'(<br>\s*){2,}', '<br>', seg)
    seg = re.sub(r'<h1>', '<h2>', seg)
    return re.sub(r'\s+', ' ', seg).strip()


rows = []
for line in open(src, encoding='utf-8'):
    parts = line.split()
    if not parts:
        continue
    url, lastmod = parts[0], (parts[1] if len(parts) > 1 else None)
    path = url.split('baza-petrovich.by/', 1)[-1].strip('/')
    if not re.match(r'^(news|sovet)/.+', path):
        continue

    h = fetch(url)
    m = re.search(r'<h1[^>]*>(.*?)</h1>', h, re.S)
    if not m:
        print('NO H1', url, file=sys.stderr)
        continue
    title = txt(m.group(1))
    start = m.end()
    end = h.find('padding-top: 30px', start)
    end = h.rfind('<div', start, end) if end > 0 else h.find('</div>', start)
    images = []
    body = clean(h[start:end], url, images)
    plain = txt(re.sub(r'\{\{img:\d+\}\}', ' ', body))

    skip = None
    if OUTDATED.search(title + ' ' + path):
        skip = 'устарело'
    elif len(plain) < 500:
        skip = 'короткая заметка'

    desc = re.search(r'<meta name="description" content="([^"]*)"', h)
    rows.append({
        'source_key': path,
        'kind': path.split('/')[0],
        'slug': path.split('/', 1)[1],
        'title': title,
        'description': H.unescape(desc.group(1)).strip() if desc and desc.group(1).strip() else None,
        'html': body,
        'images': images,
        'date': lastmod,
        'chars': len(plain),
        'skip': skip,
    })

json.dump(rows, open(out, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
kept = [r for r in rows if not r['skip']]
print(f'всего {len(rows)}, переносим {len(kept)}, пропущено {len(rows) - len(kept)}')
