import sys,json,re
from openpyxl import Workbook
from openpyxl.styles import Font,PatternFill,Alignment
from openpyxl.utils import get_column_letter
D=sys.argv[1]; OUT=sys.argv[2]
P=json.load(open(D+'/products.json')); S=json.load(open(D+'/services.json'))
idx=json.load(open(D+'/index.json'))
def num(s):
    m=re.search(r'(\d[\d\s]*[,.]?\d*)',s or '')
    return float(m.group(1).replace(' ','').replace(',','.')) if m else None
def status(s):
    s=(s or '').lower()
    if 'нет в наличии' in s: return 'нет в наличии'
    if 'запрос' in s or 'уточн' in s: return 'по запросу'
    return 'в наличии' if num(s) is not None else 'по запросу'
wb=Workbook(); ws=wb.active; ws.title='Товары'
H=['№','Раздел','Подраздел','Группа','Наименование','Ед.изм','Вес, кг','Цена розница, BYN','Цена со скидкой, BYN','Статус цены','Хит продаж','Характеристики','Краткое описание','Фото (основное)','Фото (доп.)','URL','Slug']
ws.append(H)
P.sort(key=lambda x:x['url'])
for i,p in enumerate(P,1):
    c=p['category_path']+['','','']
    attrs={}
    hd,cl=p.get('table_head') or [],p.get('table_cells') or []
    for k,v in zip(hd[1:],cl[1:]):
        if v and v!='-' and not re.search(r'цена|розниц|скидк|опт|бронир|тн\.|НДС|рождения',k,re.I): attrs[k]=v
    unit=next((v for k,v in attrs.items() if re.match(r'Ед|Единица',k)),'')
    w=next((num(v) for k,v in attrs.items() if k.startswith('Вес')),None)
    if hd and cl and hd[0]!='Наименование' and cl[0]: attrs[hd[0]]=cl[0]
    p['unit']=unit
    chars='; '.join(f'{k}: {v}' for k,v in attrs.items() if not re.match(r'Ед|Единица|Вес',k))
    ws.append([i,c[0],c[1],' / '.join(p['category_path'][2:]),p['name'],p['unit'],w,num(p['price']),num(p['price_discount']),status(p['price']),'да' if p['hit'] else '',chars,
               p['short'],(p['images'] or [''])[0],' | '.join(p['images'][1:]),p['url'],p['url'].rstrip('/').split('/')[-1]])
# categories
wc=wb.create_sheet('Категории'); wc.append(['Уровень','Путь (URL)','Название','Кол-во товаров'])
cnt={}
for p in P:
    path=p['url'][26:]
    parts=path.strip('/').split('/')
    for k in range(2,len(parts)):
        key='/'.join(parts[:k])+'/'; cnt[key]=cnt.get(key,0)+1
import html as Hh
for path in sorted(cnt):
    h=open(D+'/pages/'+idx[path],encoding='utf-8').read() if path in idx else ''
    m=re.search(r'<h1[^>]*>(.*?)</h1>',h,re.S)
    wc.append([path.count('/')-1,path,re.sub(r'\s+',' ',Hh.unescape(re.sub('<[^>]+>','',m.group(1)))).strip() if m else '',cnt[path]])
wsv=wb.create_sheet('Услуги'); wsv.append(['№','Услуга','Цена','Описание (кратко)','URL'])
for i,s in enumerate(S,1):
    t=re.sub(r'^content_page">\s*-->\s*','',s['text']); t=t[len(s['name']):].strip() if t.startswith(s['name']) else t
    wsv.append([i,s['name'],'по запросу',t[:600],s['url']])
wk=wb.create_sheet('Контакты'); 
for r in [['Компания','ООО «Петрович СТД», УНП 691816554'],
 ['Минск, адрес','Внешняя сторона МКАД, д. Большое Стиклево, ул. Молодежная, 1'],
 ['Минск, режим','Пн–Пт 9:00–17:30; 2-я и 4-я суббота (01.05–01.10); Вс — выходной'],
 ['Минск, отдел продаж','+375 17 248-91-19; +375 29 1-505-605 (А1); +375 29 5-101-101 (МТС)'],
 ['Минск, заборы/кровля/ворота, монтаж','+375 29 103-27-11 (А1); +375 29 350-52-05 (А1)'],
 ['Брест, адрес','ул. Коммерческая, 7 (кольцо Задворцы)'],['Брест, режим','Пн–Пт 9:00–17:30; Сб, Вс — выходной'],
 ['Брест, телефоны','+375 29 792-58-56 (МТС); +375 29 125-12-73 (А1)'],['E-mail','snab@baza-petrovich.by'],
 ['Мессенджеры Минск','WhatsApp/Viber +375 29 103-27-11; Telegram @svetlana_bazapetrovich'],
 ['Мессенджеры Брест','WhatsApp +375 33 691-27-06; Telegram @vitali_bazapetrovich'],
 ['Оплата','Наличные, карты (в т.ч. рассрочка Халва 2 мес., Карта Покупок 3, Магнит 3, Черепаха 8), ЕРИП (услуга 4468221), безнал, кредит «На сваё» / «Купляй! BY»'],
 ['Доставка','По звонку, оплата водителю; заказы до 12:00 — «день в день»; манипулятор при необходимости; 160 городов']]: wk.append(r)
for sh in wb.worksheets:
    for c in sh[1]: c.font=Font(bold=True,color='FFFFFF'); c.fill=PatternFill('solid',fgColor='305496'); c.alignment=Alignment(wrap_text=True,vertical='center')
    sh.freeze_panes='A2'
    for col in sh.columns:
        L=max(len(str(c.value or '')) for c in col[:200]); sh.column_dimensions[get_column_letter(col[0].column)].width=min(max(8,L+2),60)
ws.auto_filter.ref=ws.dimensions
wb.save(OUT)
st={}
for p in P: st[status(p['price'])]=st.get(status(p['price']),0)+1
print('products',len(P),st,'categories',len(cnt),'services',len(S))
print('top-level:',{k:v for k,v in cnt.items() if k.count('/')==2})
