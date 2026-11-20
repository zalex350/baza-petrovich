"""Описания товаров без текста в catalog.json — с нашего второго сайта zabor-petrovich.by.

    python3 descriptions.py fetch      # sitemap + страницы в кэш (≤2 запроса/с), разбор → CACHE/zabor_pages.json
    python3 descriptions.py baza       # старые страницы baza-petrovich.by (source_url) товаров без описания → CACHE/baza_recheck.json
    python3 descriptions.py build      # тексты zabor-petrovich.by по descriptions_match.json → ../product_descriptions.json

Первый разбор baza-petrovich.by (desc.py) пропустил текст под ценой/контактами у ~400 товаров;
`baza` достаёт его заново — это сырьё (факты, характеристики), а не готовый текст: он перемешан
с рекламой и обрывками, поэтому описания с source «baza-petrovich.by» переписаны вручную по этим фактам.
`build` переписывает только записи с source «zabor-petrovich.by»; записи «generated» и
«baza-petrovich.by» (написаны как данные) сохраняются как есть.
Кэш HTML — вне репозитория (CACHE), повторный запуск сеть не трогает.
"""
import html as H
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
CATALOG = os.path.join(HERE, '..', 'catalog.json')
OUT = os.path.join(HERE, '..', 'product_descriptions.json')
CACHE = os.environ.get('ZABOR_CACHE', '/tmp/claude-0/-home-user/ba7c5ded-88d3-5c96-965b-a71aeb3ed11c/scratchpad/zabor_cache')
UA = 'Mozilla/5.0 (compatible; catalog-sync)'
SITE = 'https://zabor-petrovich.by'
_last = [0.0]


def get(url):
    """HTML страницы из кэша или сети (пауза ≥0,5 с между запросами)."""
    os.makedirs(CACHE, exist_ok=True)
    name = re.sub(r'[^A-Za-z0-9._-]+', '_', urllib.parse.unquote(url).split('//', 1)[-1])[:180] + '.html'
    path = os.path.join(CACHE, name)
    if os.path.exists(path):
        return open(path, encoding='utf-8', errors='replace').read()
    wait = 0.5 - (time.time() - _last[0])
    if wait > 0:
        time.sleep(wait)
    req = urllib.request.Request(urllib.parse.quote(url, safe=':/?&=%,*()'), headers={'User-Agent': UA})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            body = r.read().decode('utf-8', errors='replace')
    except Exception as e:  # noqa: BLE001
        print('ERR', url, e)
        body = ''
    _last[0] = time.time()
    with open(path, 'w', encoding='utf-8') as f:
        f.write(body)
    return body


def text(s):
    s = re.sub(r'<br\s*/?>', ' ', s)
    s = re.sub(r'<[^>]+>', ' ', s)
    return re.sub(r'\s+', ' ', H.unescape(s)).replace('\xa0', ' ').strip()


def block(h, cls):
    """Содержимое <div class="tabs__content {cls}…"> до закрывающего </div> того же уровня."""
    m = re.search(r'<div class="tabs__content %s[^"]*">' % cls, h)
    if not m:
        return ''
    i, depth = m.end(), 1
    for t in re.finditer(r'<(/?)div\b', h[i:]):
        depth += -1 if t.group(1) else 1
        if depth == 0:
            return h[i:i + t.start()]
    return h[i:]


def parse(h):
    h1 = re.search(r'<h1[^>]*>(.*?)</h1>', h, re.S)
    desc = block(h, 'description')
    paras = [text(p) for p in re.findall(r'<(?:p|li|h2|h3)\b[^>]*>(.*?)</(?:p|li|h2|h3)>', desc, re.S)]
    if not paras and text(desc):
        paras = [text(desc)]
    chars = {}
    for row in re.findall(r'<tr\b.*?</tr>', block(h, 'chars'), re.S):
        cells = [text(c) for c in re.findall(r'<td\b.*?</td>', row, re.S)]
        if len(cells) >= 2 and cells[0]:
            chars[cells[0]] = cells[1]
    return {'h1': text(h1.group(1)) if h1 else '', 'paras': [p for p in paras if p], 'chars': chars}


def fetch():
    sm = get(SITE + '/sitemap.xml')
    urls = re.findall(r'<loc>([^<]+)</loc>', sm)
    pages = {}
    for u in urls:
        pages[u] = parse(get(H.unescape(u)))
    json.dump(pages, open(os.path.join(CACHE, 'zabor_pages.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('pages', len(pages), 'with description', sum(1 for p in pages.values() if p['paras']))


def baza():
    """Старый сайт: есть ли на странице товара текст, пропущенный первым разбором (вкладки и т.п.)."""
    cat = json.load(open(CATALOG, encoding='utf-8'))
    found = {}
    for p in cat['products']:
        if (p.get('description') or '').strip() or not p.get('source_url'):
            continue
        h = get(p['source_url'])
        i = h.find('content_page')
        if i < 0:
            continue
        seg = h[i:]
        seg = re.sub(r'<(script|style|svg|form|button)\b.*?</\1>', '', seg, flags=re.S)
        t = text(re.sub(r'<(p|div|br|li|tr|h\d)\b', r'\n<\1', seg))
        for stop in ('С этим товаром покупают', 'Популярные товары', 'Советы петровича', 'Обращаем Ваше внимание'):
            t = t.split(stop)[0]
        # блок цены и контактов: от «Цена:» до последнего мессенджера
        t = re.sub(r'^.*?(Заказать звонок по кредиту|руб\.)', '', t, count=1, flags=re.S)
        t = re.sub(r'(Отправить заявку|Уточнить стоимость).*(Viber|Telegram|WhatsApp)', ' ', t, flags=re.S)
        t = re.sub(r'\s+', ' ', t).strip()
        if len(t) > 150:
            found[p['path']] = t
    json.dump(found, open(os.path.join(CACHE, 'baza_recheck.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('baza pages with text', len(found))


# ---- очистка текста zabor-petrovich.by -------------------------------------------------
DROP = re.compile(
    r'(\+375|звон|позвон|заявк|по телефону|акци|скидк|руб\.|BYN|zabor-petrovich|baza-petrovich|www\.|http|'
    r'в наличии и под заказ|консультац|оставьте|напишите|Viber|Telegram|кредит|рассрочк|доставк[аеу] (бесплат|по звонку))',
    re.I)


def clean(paras, extra_drop=()):
    out = []
    for p in paras:
        p = re.sub(r'\s+', ' ', p).strip()
        p = p.replace('Металлобазы Петрович', 'нашей базы').replace('Металлобаза Петрович', 'наша база')
        p = re.sub(r'\s+([,.;:!?)])', r'\1', p)
        p = re.sub(r'\(\s+', '(', p)
        if not p or DROP.search(p) or any(x in p for x in extra_drop):
            continue
        out.append(p)
    return out


def to_html(paras):
    return ''.join('<p>%s</p>' % H.escape(p, quote=False) for p in paras)


# ---- соответствие страниц zabor-petrovich.by нашим товарам ------------------------------
# descriptions_match.json: [{"url": страница zabor-petrovich.by, "paths": {путь товара catalog.json: html}}].
# Тексты сопоставлены вручную по смыслу (профиль, толщина, покрытие, размер) и отредактированы:
# убраны цены, телефоны, призывы, добавлены параметры конкретного варианта (высота, сечение,
# покрытие), чтобы тексты вариантов не совпадали. Для черновика правки — clean(pages[url]['paras']).
MATCH_FILE = os.path.join(HERE, 'descriptions_match.json')


def build():
    pages = json.load(open(os.path.join(CACHE, 'zabor_pages.json'), encoding='utf-8'))
    match = json.load(open(MATCH_FILE, encoding='utf-8'))
    cat = json.load(open(CATALOG, encoding='utf-8'))
    empty = {p['path'] for p in cat['products'] if not (p.get('description') or '').strip()}
    out = json.load(open(OUT, encoding='utf-8')) if os.path.exists(OUT) else {}
    out = {k: v for k, v in out.items() if v.get('source') != 'zabor-petrovich.by' and k in empty}
    for item in match:
        url = item['url']
        if url not in pages:
            print('not in sitemap any more:', url)
        for path, html in item['paths'].items():
            if path not in empty:
                print('skip (has description or unknown):', path)
                continue
            out[path] = {'html': html, 'source': 'zabor-petrovich.by', 'source_url': url}
    out = dict(sorted(out.items()))
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=1)
        f.write('\n')
    print('written', len(out), 'missing', len(empty - set(out)))


if __name__ == '__main__':
    {'fetch': fetch, 'baza': baza, 'build': build}[sys.argv[1] if len(sys.argv) > 1 else 'fetch']()
