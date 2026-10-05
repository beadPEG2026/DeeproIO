export function keyboardVisible({focused,width,baseline,height,scale=1}) {
 return focused && width<=900 && Math.abs(scale-1)<0.05 && baseline-height>120;
}
export function keepInputVisible(element, viewport, win=window) {
 if(!element?.getBoundingClientRect)return;
 const box=element.getBoundingClientRect(),top=(viewport?.offsetTop||0)+16,bottom=(viewport?.offsetTop||0)+(viewport?.height||win.innerHeight)-32;
 const delta=box.bottom>bottom ? box.bottom-bottom : box.top<top ? box.top-top : 0;
 if(delta)win.scrollBy({top:delta,behavior:'auto'});
}
export function watchKeyboard(root, win=window, doc=document) {
 let baseline=win.innerHeight, frame=null, timer=null;
 const editable=el=>!!el && root.contains(el) && el.matches('input:not([type=button]):not([type=checkbox]):not([type=radio]),textarea,[contenteditable=true]');
 const update=()=>{
  const viewport=win.visualViewport,focused=editable(doc.activeElement),height=viewport?.height || win.innerHeight;
  if(!focused)baseline=Math.max(win.innerHeight,height);
  const open=keyboardVisible({focused,width:win.innerWidth,baseline,height,scale:viewport?.scale||1});
  root.classList.toggle('dp-keyboard-open',open);
  root.style.setProperty('--dp-keyboard-inset',open ? Math.max(0,baseline-height)+'px' : '0px');
  if(focused){if(frame)win.cancelAnimationFrame(frame);frame=win.requestAnimationFrame(()=>keepInputVisible(doc.activeElement,viewport,win));}
 };
 const focus=()=>{update();win.clearTimeout(timer);timer=win.setTimeout(update,350);};
 const rotate=()=>{baseline=win.innerHeight;focus();};
 root.addEventListener('focusin',focus);root.addEventListener('focusout',focus);
 win.addEventListener('resize',update);win.addEventListener('orientationchange',rotate);
 win.visualViewport?.addEventListener('resize',update);win.visualViewport?.addEventListener('scroll',update);
 return ()=>{win.clearTimeout(timer);if(frame)win.cancelAnimationFrame(frame);root.classList.remove('dp-keyboard-open');root.style.removeProperty('--dp-keyboard-inset');root.removeEventListener('focusin',focus);root.removeEventListener('focusout',focus);win.removeEventListener('resize',update);win.removeEventListener('orientationchange',rotate);win.visualViewport?.removeEventListener('resize',update);win.visualViewport?.removeEventListener('scroll',update);};
}
