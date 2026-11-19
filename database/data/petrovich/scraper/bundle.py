import sys,json,re,html as H
D=sys.argv[1]; OUT=sys.argv[2]
idx=json.load(open(D+'/index.json')); P=json.load(open(D+'/products.json')); S=json.load(open(D+'/services.json'))
desc=json.load(open(sys.argv[3]))
def txt(s): return re.sub(r'\s+',' ',H.unescape(re.sub(r'<[^>]+>',' ',s))).strip()
def h1(path):
    if path not in idx: return None
    h=open(D+'/pages/'+idx[path],encoding='utf-8').read()
    m=re.search(r'<h1[^>]*>(.*?)</h1>',h,re.S); return txt(m.group(1)) if m else None
TOP={'metalloprokat':'Металлопрокат','setka':'Сетка','provoloka':'Проволока','profnastil':'Профнастил','metallocherepica':'Металлочерепица',
 'elementy-krovli':'Элементы кровли','zabory':'Заборы','proizvodstvo':'Ворота, калитки, столбы','soputstvuyushhie-tovary':'Сопутствующие товары',
 'metalloshtaketnik':'Металлоштакетник','komplektuyushhie-dlya-vorot':'Комплектующие и автоматика для ворот','armatura-kompozit':'Композитная арматура','zhalyuzi':'Жалюзи'}
ORDER=list(TOP)
FIX={'Арматуру гладкую А1, А5':'Арматура гладкая А1, А5','Арматура рифленая А3':'Арматура рифленая А3',
 'Лист горячекатанный стальной':'Лист горячекатаный','Лист холоднокатаный стальной':'Лист холоднокатаный','Лист оцинкованный':'Лист оцинкованный',
 'Арматура':'Арматура гладкая и рифленая'}
def clean_name(n):
    n=re.sub(r'(?i)(^|\s)купить(\s|$)',' ',n)
    n=re.sub(r'(?i)\s*(в Минске|в розницу|ГОСТ|, цена|цена)\s*',' ',n)
    n=re.sub(r'\s+',' ',n).strip(' .,')
    n=n[:1].upper()+n[1:] if n else n
    return FIX.get(n,n)
def slugify(seg): 
    s=re.sub(r'[^a-z0-9]+','-',seg.lower()).strip('-')
    return s
cats={}
prods=[]
for p in sorted(P,key=lambda x:x['url']):
    path=p['url'][26:]
    parts=path.strip('/').split('/')[1:]  # без catalog
    if len(parts)<2: continue
    # Категории — только префиксы адреса со своей страницей («/» бывает и внутри имени: polosa-g/k)
    chain=[k for k in range(1,len(parts)) if 'catalog/'+'/'.join(parts[:k])+'/' in idx]
    parent=None
    for k in chain:
        cp='/'.join(parts[:k])
        if cp not in cats:
            page='catalog/'+cp+'/'
            name=TOP.get(cp) if k==1 else clean_name(h1(page) or parts[k-1])
            cats[cp]={'path':cp,'parent':parent,'name':name or parts[k-1],
                      'slug_part':'','description':desc.get(page,''),
                      'sort':ORDER.index(cp) if k==1 and cp in ORDER else 1000}
        parent=cp
    catpath=parent
    leaf='-'.join(parts[chain[-1]:]) if chain else parts[-1]
    attrs={}
    hd,cl=p.get('table_head') or [],p.get('table_cells') or []
    UNIT=re.compile(r'^(м\.п\.|шт\.?|кг|компл\w*|рул\w*|м2|м²|лист|т)$',re.I)
    for kk,v in zip(hd[1:],cl[1:]):
        if UNIT.match(v.strip()) or re.match(r'^[\d.,]+$',kk.strip()): continue  # «0.45» — колонки цен по толщине
        kk={'Высота(м)':'Высота, м'}.get(kk.strip(),kk)
        if v and v!='-' and not re.search(r'цена|розниц|скидк|опт|бронир|тн\.|НДС|рождения|Ед|Единица|Вес|карта',kk,re.I): attrs[kk.strip()]=v
    if hd and cl and hd[0]!='Наименование' and cl[0]: attrs[hd[0]]=cl[0]
    unit=next((v for kk,v in zip(hd[1:],cl[1:]) if re.match(r'Ед|Единица',kk)),'') if hd else ''
    unit=unit or next((v for v in cl[1:] if UNIT.match(v.strip())),'')
    unit={'шт.':'шт','-':'','Комплект':'компл','комплект':'компл','м2':'м²','кв.м.':'м²','м.кв.':'м²'}.get(unit.strip(),unit.strip())
    def num(s):
        m=re.search(r'(\d[\d\s]*[,.]?\d*)',s or ''); 
        return float(m.group(1).replace(' ','').replace(',','.')) if m else None
    weight=next((num(v) for kk,v in zip(hd[1:],cl[1:]) if kk.startswith('Вес')),None) if hd else None
    pr=p['price'].lower()
    status='out' if 'нет в наличии' in pr else ('request' if num(p['price']) is None else 'in')
    qty_note=None
    if p['price_discount'] and num(p['price_discount']):
        lab=next((kk for kk in hd if re.search(r'скидк',kk,re.I)),'Со скидкой') if hd else 'Со скидкой'
        qty_note=f"{lab}: {p['price_discount']}"
    prods.append({'path':path.strip('/'),'category':catpath,'name':p['name'],'slug':slugify(leaf),
        'price':num(p['price']) if status!='request' else None,'status':status,'unit':unit,'weight':weight,
        'hit':p['hit'],'attributes':attrs,'images':p['images'],'description':desc.get(path,''),'volume_price':qty_note,
        'source_url':p['url']})
services=[]
for s in S:
    path=s['url'][26:]
    services.append({'slug':slugify(path.strip('/').split('/')[-1]) or 'usluga','title':s['name'],'description':desc.get(path,''),'source_url':s['url']})
# translit for cyrillic slugs
TR=dict(zip('абвгдеёжзийклмнопрстуфхцчшщъыьэюя',['a','b','v','g','d','e','e','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','h','c','ch','sh','sch','','y','','e','yu','ya']))
def tr(seg): return slugify(''.join(TR.get(c,c) for c in seg.lower()))
for c in cats.values():
    if not c['slug_part']: pass
for s,svc in zip(S,services):
    seg=s['url'][26:].strip('/').split('/')[-1]; svc['slug']=tr(seg)
for c in cats.values():
    seg=c['path'][len(c['parent'])+1:] if c['parent'] else c['path']; c['slug_part']=tr(seg.replace('/','-'))
# Решения заказчика по структуре: раздел → новый родитель
MOVE={'provoloka/vyazalnya':'metalloprokat','provoloka/dlya-natizheniya-setki':'metalloprokat'}
for c in cats.values():
    if c['path'] in MOVE: c['parent']=MOVE[c['path']]
json.dump({'source':'https://baza-petrovich.by','categories':sorted(cats.values(),key=lambda c:c['path']),'products':prods,'services':services},
          open(OUT,'w',encoding='utf-8'),ensure_ascii=False,indent=1)
import collections
print('categories',len(cats),'products',len(prods),'services',len(services),collections.Counter(p['status'] for p in prods))
print([c['name'] for c in cats.values() if c['parent'] is None])
print(sorted({c['name'] for c in cats.values()})[:60])
