import sys,json,re,html as H
D=sys.argv[1]; idx=json.load(open(D+'/index.json'))
STOP=['Советы петровича','Популярные товары','top-block','<footer','Уточнить стоимость или']
def segment(h):
    h=re.sub(r'<(script|style|svg|noscript)\b.*?</\1>','',h,flags=re.S)
    h=re.sub(r'<!--.*?-->','',h,flags=re.S)
    i=h.find('content_page')
    if i<0: return ''
    # товар: описание — в col-md-9 после <hr>; категория — info-section; услуга — после h1
    m=re.search(r'<div class="col-md-9">\s*<hr>',h[i:])
    if m: start=i+m.end()
    elif '<p style="font-size: 18px;">Цена:' in h: return ''
    else:
        j=h.find('info-section',i)
        if j>0: start=h.find('>',j)+1
        else:
            m=re.search(r'</h1>',h[i:]); start=i+(m.end() if m else 0)
    end=min([x for x in (h.find(s,start) for s in STOP) if x>0] or [len(h)])
    seg=h[start:end]
    # drop product tables, forms, buttons, images, links' hrefs kept as text
    seg=re.sub(r'<table class="product-table">.*?</table>','',seg,flags=re.S)
    seg=re.sub(r'<(form|button|select|iframe)\b.*?</\1>','',seg,flags=re.S)
    seg=re.sub(r'<img[^>]*>','',seg)
    seg=re.sub(r'<a\b[^>]*>(.*?)</a>',r'\1',seg,flags=re.S)
    seg=re.sub(r'<(/?)(p|ul|ol|li|h2|h3|h4|strong|b|em|i|br|table|tr|td|th|tbody|thead)\b[^>]*>',r'<\1\2>',seg)
    seg=re.sub(r'</?(?!(?:p|ul|ol|li|h2|h3|h4|strong|b|em|i|br|table|tr|td|th|tbody|thead)\b)[a-zA-Z][^>]*>','',seg)
    seg=re.sub(r'<(p|li|h2|h3|h4|strong|b)>\s*</\1>','',seg)
    seg=re.sub(r'\s+',' ',seg).strip()
    # Дисклеймер старого сайта и хвост без текста
    seg=re.split(r'Обращаем Ваше внимание на то, что данный интернет-сайт',seg)[0]
    seg=re.sub(r'(\s*<(br|p|h3|h2|strong|b)>\s*)+$','',seg)
    text=re.sub(r'<[^>]+>',' ',seg); text=re.sub(r'\s+',' ',H.unescape(text)).strip()
    return seg if len(text)>40 else ''
out={}
for path,f in idx.items():
    if not path.startswith(('catalog/','uslugi/')): continue
    out[path]=segment(open(D+'/pages/'+f,encoding='utf-8').read())
json.dump(out,open(sys.argv[2],'w',encoding='utf-8'),ensure_ascii=False)
n=sum(1 for v in out.values() if v); print('with desc',n,'of',len(out))
