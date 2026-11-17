import sys, os, re, time, hashlib, json, urllib.request, urllib.parse
from collections import deque
BASE='https://baza-petrovich.by/'
OUT=sys.argv[1]; os.makedirs(OUT+'/pages',exist_ok=True)
START=['','catalog/','uslugi/','company/','contacts/','information/','sale/','specialnye-predlozheniya/','calculator/','rannee-bronirovanie/','sotrudnichestvo/','sovet/','nashi-raboty/']
ALLOW=re.compile(r'^(catalog|uslugi|company|contacts|information|sale|specialnye-predlozheniya|calculator|rannee-bronirovanie|sotrudnichestvo|nashi-raboty|dostavka|oplata)')
seen=set(); q=deque(START); index={}
def norm(href,cur):
    if href.startswith(('mailto:','tel:','javascript:','#')): return None
    u=urllib.parse.urljoin(BASE, href)  # site uses <base href="/">
    p=urllib.parse.urlparse(u)
    if p.netloc not in ('baza-petrovich.by','www.baza-petrovich.by'): return None
    path=urllib.parse.unquote(p.path).lstrip('/')
    if re.search(r'\.(jpe?g|png|gif|svg|webp|pdf|xlsx?|docx?|css|js|ico|zip)$',path,re.I): return None
    if path and not ALLOW.match(path): return None
    return path
while q and len(seen)<3000:
    path=q.popleft()
    if path in seen: continue
    seen.add(path)
    url=BASE+urllib.parse.quote(path)
    try:
        req=urllib.request.Request(url,headers={'User-Agent':'Mozilla/5.0'})
        html=urllib.request.urlopen(req,timeout=30).read().decode('utf-8','replace')
    except Exception as e:
        print('ERR',path,e,flush=True); continue
    fn=hashlib.md5(path.encode()).hexdigest()+'.html'
    open(OUT+'/pages/'+fn,'w',encoding='utf-8').write(html)
    index[path]=fn
    for h in re.findall(r'href=["\']([^"\']+)["\']',html):
        n=norm(h,path)
        if n is not None and n not in seen: q.append(n)
    if len(seen)%50==0: print(len(seen),len(q),flush=True)
    time.sleep(0.25)
json.dump(index,open(OUT+'/index.json','w',encoding='utf-8'),ensure_ascii=False,indent=1)
print('DONE',len(index))
