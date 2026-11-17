import sys,json,re,html as H
D=sys.argv[1]; idx=json.load(open(D+'/index.json'))
def txt(s): return re.sub(r'\s+',' ',H.unescape(re.sub(r'<[^>]+>',' ',s))).strip()
pages={p:open(D+'/pages/'+f,encoding='utf-8').read() for p,f in idx.items()}
h1={p:(txt(m.group(1)) if (m:=re.search(r'<h1[^>]*>(.*?)</h1>',h,re.S)) else '') for p,h in pages.items()}
rows={}
for p,h in pages.items():
    for t in re.findall(r'<table class="product-table">(.*?)</table>',h,re.S):
        head=[txt(x) for x in re.findall(r'<td[^>]*>(.*?)</td>',t.split('<tbody>')[0],re.S)]
        for tr in re.findall(r'<tr class="product-shadow">(.*?)</tr>',t,re.S):
            tds=re.findall(r'<td[^>]*>(.*?)</td>',tr,re.S)
            a=re.search(r"href=['\"]([^'\"]+)",tds[0])
            u=a.group(1).lstrip('/') if a else None
            key=u or p+'#'+txt(tds[0])
            cand=dict(list_page=p,cells=[txt(x) for x in tds],head=head,hit='Хит' in tds[0])
            # Товар бывает в нескольких таблицах (спецпредложения, главная) — берётся таблица ближайшего раздела
            old=rows.get(key)
            # Сначала таблица с колонкой «Ед.изм» (у части подразделов шапка скопирована с чужой таблицы)
            def score(r): return ((1 if any(h.startswith('Ед') for h in r['head']) else 0), len(r['list_page']) if key.startswith(r['list_page']) else -1)
            if old is None or score(cand)>score(old): rows[key]=cand
prods=[]
for p,h in pages.items():
    if not p.startswith('catalog/'): continue
    m=re.search(r'<p style="font-size: 18px;">Цена:(.*?)</p>',h,re.S)
    if not m and p not in rows: continue
    if not m and 'Цена' not in h: continue
    if not m: continue
    d=re.search(r'Цена со скидкой:(.*?)</p>',h,re.S)
    imgs=re.findall(r'class="fancybox" href="([^"]+)"',h)
    sm=re.search(r'</p>\s*<small>(.*?)</small>',h,re.S)
    # description blocks below
    desc=''
    dm=re.search(r'<div class="container content_page">(.*?)<footer',h,re.S)
    parts=p.strip('/').split('/')
    cats=[h1.get('/'.join(parts[:i])+'/','') for i in range(2,len(parts))]
    r=rows.get(p,{})
    prods.append(dict(url='https://baza-petrovich.by/'+p,name=h1[p],category_path=[c for c in cats if c],
        price=txt(m.group(1)),price_discount=txt(d.group(1)) if d else '',
        unit=(r.get('cells') or ['',''])[1] if r else '',weight=(r.get('cells') or ['','',''])[2] if r and len(r['cells'])>2 else '',
        table_head=r.get('head'),table_cells=r.get('cells'),hit=r.get('hit',False),
        images=['https://baza-petrovich.by/'+i.lstrip('/') for i in imgs],short=txt(sm.group(1)) if sm else ''))
json.dump(prods,open(D+'/products.json','w',encoding='utf-8'),ensure_ascii=False,indent=1)
print('products',len(prods),'rows',len(rows))
nolink=[k for k in rows if '#' in k]; print('table rows without own page',len(nolink))
cats=sorted({p for p in pages if p.startswith('catalog/')} - {x["url"][26:] for x in prods})
print('non-product catalog pages',len(cats))
for c in cats: print(' ',c,'|',h1[c])
