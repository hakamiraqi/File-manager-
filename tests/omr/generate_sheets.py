# Synthetic bubble sheets, drawn with the same geometry as the printed sheet (omrLayout.js),
# then marked like students do and distorted like scanners and phones do.
import json, math, random, sys
import numpy as np, cv2
from PIL import Image, ImageDraw, ImageFont

MARK=10; MARKS=[(15,15),(195,15),(15,282),(195,282)]
TOP=62; ROW=7; ROWS=30; COLS=[14,59,104,149]; R=2.4; GAP=7
def slot(k, n):
    col=k//ROWS; row=k%ROWS; y=TOP+row*ROW; x0=COLS[col]
    return [(x0+10+i*GAP, y) for i in range(n)]

def page(dpi, questions, marks, code, rng, scale=1.0):
    s=dpi/25.4*scale
    W,H=int(210*dpi/25.4),int(297*dpi/25.4)
    im=Image.new('L',(W,H),255); d=ImageDraw.Draw(im)
    off=((1-scale)*105*dpi/25.4,(1-scale)*148.5*dpi/25.4)
    P=lambda x,y:(x*s+off[0],y*s+off[1])
    for (cx,cy) in MARKS:
        a=P(cx-MARK/2,cy-MARK/2); b=P(cx+MARK/2,cy+MARK/2); d.rectangle([a,b],fill=0)
    # header text lines (noise)
    for y in (17,23,34,41,49):
        x=P(30,y-3); d.rectangle([x,P(110+rng.random()*20,y)],fill=None,outline=None)
        for k in range(rng.randint(10,25)):
            px=30+k*3.5; d.rectangle([P(px,y-2.5),P(px+2.2,y)],fill=60)
    qr=cv2.QRCodeEncoder.create().encode(code)
    q=Image.fromarray(qr).resize((int(30*s),int(30*s)),Image.NEAREST)
    im.paste(q,(int(P(146,24)[0]),int(P(146,24)[1])))
    for k,qq in enumerate(questions):
        x0=COLS[k//ROWS]; y=TOP+(k%ROWS)*ROW
        d.rectangle([P(x0,y-1.5),P(x0+3,y+1)],fill=40)   # the number
        for i,(bx,by) in enumerate(slot(k,qq['options'])):
            d.ellipse([P(bx-R,by-R),P(bx+R,by+R)],outline=0,width=max(1,int(0.3*s)))
            d.rectangle([P(bx-0.6,by-0.9),P(bx+0.6,by+0.9)],fill=110)   # the letter
            m=marks.get((k,i))
            if m:
                kind,val=m
                jx,jy=rng.uniform(-0.4,0.4),rng.uniform(-0.4,0.4)
                if kind=='fill':
                    rr=R*rng.uniform(0.75,0.98); d.ellipse([P(bx+jx-rr,by+jy-rr),P(bx+jx+rr,by+jy+rr)],fill=val)
                elif kind=='part':
                    rr=R*0.55; d.ellipse([P(bx+jx-rr,by+jy-rr),P(bx+jx+rr,by+jy+rr)],fill=val)
                elif kind=='tick':
                    d.line([P(bx-R*0.7,by),P(bx,by+R*0.7),P(bx+R*0.9,by-R*0.9)],fill=val,width=max(1,int(0.5*s)))
    return np.array(im)

def distort(a, kind, rng):
    h,w=a.shape
    bg=255
    if kind in ('rotate','photo','photo_dark','photo_shadow'):
        ang=rng.uniform(-3,3) if kind=='rotate' else rng.uniform(-6,6)
        pad=int(0.06*w)
        bgc=40 if kind=='photo_dark' else 235
        big=np.full((h+2*pad,w+2*pad),bgc,np.uint8); big[pad:pad+h,pad:pad+w]=a; a=big; h,w=a.shape
        if kind=='rotate':
            M=cv2.getRotationMatrix2D((w/2,h/2),ang,1.0); a=cv2.warpAffine(a,M,(w,h),borderValue=bgc)
        else:
            src=np.float32([[0,0],[w,0],[0,h],[w,h]]); j=0.04
            dst=np.float32([[rng.uniform(0,j)*w,rng.uniform(0,j)*h],[w-rng.uniform(0,j)*w,rng.uniform(0,j)*h],[rng.uniform(0,j)*w,h-rng.uniform(0,j)*h],[w-rng.uniform(0,j)*w,h-rng.uniform(0,j)*h]])
            a=cv2.warpPerspective(a,cv2.getPerspectiveTransform(src,dst),(w,h),borderValue=bgc)
            a=cv2.GaussianBlur(a,(3,3),0.8)
            # uneven light, and for the shadow case a dark band
            yy,xx=np.mgrid[0:h,0:w]; light=0.80+0.2*(xx/w)
            if kind=='photo_shadow': light=light*np.where((xx/w>0.55)&(xx/w<0.8),0.62,1.0)
            a=np.clip(a*light+np.random.default_rng(int(rng.random()*1e6)).normal(0,6,a.shape),0,255).astype(np.uint8)
    if kind=='noise':
        a=np.clip(a.astype(float)+np.random.default_rng(int(rng.random()*1e6)).normal(0,14,a.shape),0,255).astype(np.uint8)
    if kind=='lowres':
        a=cv2.resize(cv2.resize(a,(w//2,h//2),interpolation=cv2.INTER_AREA),(w//2,h//2))
    if kind=='jpeg':
        ok,enc=cv2.imencode('.jpg',a,[cv2.IMWRITE_JPEG_QUALITY,35]); a=cv2.imdecode(enc,0)
    return a

def main():
    rng=random.Random(7); out=[]
    cases=[('clean','fill',200,1.0),('rotate','fill',200,1.0),('noise','fill',200,1.0),('lowres','fill',200,1.0),('jpeg','fill',200,1.0),
           ('photo','fill',150,1.0),('photo_dark','fill',150,1.0),('photo_shadow','fill',150,1.0),('scaled97','fill',200,0.97),
           ('pencil','pencil',200,1.0),('partial','part',200,1.0),('tick','tick',200,1.0),('double','double',200,1.0),('erased','erased',200,1.0)]
    n=0
    for case,mark,dpi,scale in cases:
        for rep in range(12):
            nq=rng.choice([30,60,90,100,120])
            qs=[{'id':1000+k,'type':'multiple' if rng.random()<0.12 else 'single','options':rng.choice([4,4,4,5,2])} for k in range(nq)]
            truth={}; marks={}; expect_doubt=set()
            for k,q in enumerate(qs):
                if rng.random()<0.06: truth[q['id']]=[]; continue   # left empty
                picks=[rng.randrange(q['options'])]
                if q['type']=='multiple' and q['options']>2 and rng.random()<0.5:
                    picks=sorted(set(picks+[rng.randrange(q['options'])]))
                truth[q['id']]=picks
                for i in picks:
                    if mark=='fill': marks[(k,i)]=('fill',rng.randint(10,70))
                    elif mark=='pencil': marks[(k,i)]=('fill',rng.randint(95,140))
                    elif mark=='part': marks[(k,i)]=('part',rng.randint(10,60))
                    elif mark=='tick': marks[(k,i)]=('tick',rng.randint(10,60))
                    elif mark in ('double','erased'): marks[(k,i)]=('fill',rng.randint(10,60))
                if mark=='double' and q['type']=='single' and rng.random()<0.25 and q['options']>1:
                    other=(picks[0]+1)%q['options']; marks[(k,other)]=('fill',rng.randint(10,60)); expect_doubt.add(q['id']); truth[q['id']]=None   # ambiguous
                if mark=='erased' and rng.random()<0.3 and q['options']>1:
                    other=(picks[0]+1)%q['options']
                    if other not in picks: marks[(k,other)]=('fill',rng.randint(185,215))  # erasure residue
            code=f'EXOMR:1:{n}:TESTCODE00'
            a=page(dpi,qs,marks,code,rng,scale)
            a=distort(a, case if case not in ('scaled97','pencil','partial','tick','double','erased') else 'none', rng)
            # As the page would: the picture drawn onto a canvas at most 1800 px on its long side.
            sc=min(1.0,1800/max(a.shape)); 
            if sc<1: a=cv2.resize(a,(round(a.shape[1]*sc),round(a.shape[0]*sc)),interpolation=cv2.INTER_AREA)
            rgba=np.dstack([a,a,a,np.full_like(a,255)])
            fn=f'img{n}.raw'; rgba.tofile(fn)
            out.append({'file':fn,'w':a.shape[1],'h':a.shape[0],'case':case,'questions':qs,'truth':{str(k):v for k,v in truth.items()},'code':code})
            n+=1
    json.dump(out,open('sheets.json','w'))
    print(n,'sheets')
main()
