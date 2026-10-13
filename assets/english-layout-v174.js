// Reconstruct OCR regions from word coordinates, not a page-wide text stream.
export function englishLayout(data) {
  const output = [], issues = [];
  const median = values => {
    const a=values.filter(n=>n>0).sort((a,b)=>a-b);
    return a.length ? a[Math.floor(a.length/2)] : 1;
  };
  for (const block of data.blocks || []) {
    const lines=(block.paragraphs || []).flatMap(p=>p.lines || []);
    const all=lines.flatMap(l=>l.words || []);
    if (!all.length) continue;
    if (block.confidence < 50 && all.length <= 5) {
      issues.push('Some low-confidence illustration/caption text was omitted. Compare with the image and restore any missing text.');
      continue;
    }
    const gaps=[];
    lines.forEach((line,row)=>{
      const words=line.words || [];
      const height=median(words.map(w=>w.bbox.y1-w.bbox.y0));
      const spaces=words.slice(1).map((w,i)=>w.bbox.x0-words[i].bbox.x1);
      const threshold=Math.max(height*1.6,median(spaces)*2.3);
      for(let i=1;i<words.length;i++) {
        const left=words[i-1].bbox.x1,right=words[i].bbox.x0;
        if(right-left>Math.max(height*.9,median(spaces)*1.45)) gaps.push({left,right,row,strong:right-left>threshold,y0:line.bbox.y0,y1:line.bbox.y1});
      }
    });
    let gutter=null;
    for(const gap of gaps){
      const x=(gap.left+gap.right)/2;
      const matches=gaps.filter(g=>g.left<x&&g.right>x);
      const rows=new Set(matches.map(g=>g.row));
      if(rows.size>=2 && matches.some(g=>g.strong) && (!gutter || rows.size>gutter.count)) gutter={x,count:rows.size,y0:Math.min(...matches.map(g=>g.y0)),y1:Math.max(...matches.map(g=>g.y1))};
    }
    const main=[],aside=[],captions=[];
    const firstStart=lines[0]?.words?.[0]?.bbox.x0;
    for(const line of lines){
      const left=[],right=[];
      for(const word of line.words || []){
        const value=(word.text || '').normalize('NFC').trim();
        if(!value)continue;
        // Do not erase uncertain lexical words (e.g. electronics). Flag them.
        if(/[a-z]/i.test(value) && word.confidence<65) issues.push('Uncertain words need checking.');
        if(!/[\p{L}\p{N}]/u.test(value) && (word.confidence<50 || /[|~�]/u.test(value))) {issues.push('Uncertain symbols need checking.');continue;}
        const box=word.bbox;
        const side=gutter && box.x0>=gutter.x && box.y1>=gutter.y0 && box.y0<=gutter.y1;
        (side?right:left).push({...word,text:value});
      }
      if(!gutter && left.length>2 && firstStart!==undefined && left[0].bbox.x0<firstStart-40 && Math.abs(left[1].bbox.x0-firstStart)<15 && left[1].bbox.x0-left[0].bbox.x1>50){
        captions.push(left.shift().text);issues.push('Caption reading order needs checking.');
      }
      const append=(words,target)=>{
        if(!words.length)return;
        if(words.length<=2 && words.reduce((s,w)=>s+w.confidence,0)/words.length<65){
          issues.push('Low-confidence fragment needs checking.');
          // Only discard isolated decoration, never uncertain full words.
          if(words.every(w=>!/[a-z]{3}/i.test(w.text)))return;
        }
        target.push(words.map(w=>w.text).join(' '));
      };
      append(left,main);append(right,aside);
    }
    if(main.length)output.push(main.join('\n'));
    if(aside.length){output.push(aside.join('\n'));issues.push('Columns/side-box reading order needs checking.');}
    if(captions.length)output.push(captions.join('\n'));
  }
  const text=output.join('\n\n').trim();
  if(!text || data.confidence<80)issues.push('Low overall OCR confidence.');
  return {text,issues:[...new Set(issues)]};
}

// Review happens before the caller receives text, so Cancel cannot save a book.
export async function reviewEnglishPages(pdf, pages, reports) {
  return new Promise((resolve,reject)=>{
    const overlay=document.createElement('div');
    overlay.style.cssText='position:fixed;inset:0;z-index:2147483647;background:#0009;padding:16px;display:flex;align-items:center;justify-content:center';
    const panel=document.createElement('section');
    panel.setAttribute('role','dialog');panel.setAttribute('aria-modal','true');panel.setAttribute('aria-label','Review extracted English text');
    panel.style.cssText='background:white;color:#222;border-radius:12px;padding:18px;width:min(1200px,98vw);height:92vh;display:flex;flex-direction:column;gap:10px;font:14px system-ui';
    const heading=document.createElement('strong');heading.textContent='Check scanned English text before saving';
    const description=document.createElement('div');description.textContent='Compare the page image with its text. Correct any OCR mistakes. Use the checkbox after checking each flagged page. No book is saved until you finish.';
    const controls=document.createElement('div'),select=document.createElement('select');
    select.setAttribute('aria-label','PDF page');
    pages.forEach((_,i)=>{const option=document.createElement('option');option.value=String(i);option.textContent=`Page ${i+1}${reports.has(i)?' — check OCR':''}`;select.append(option)});
    controls.append(select);
    const label=document.createElement('label'),checkbox=document.createElement('input');checkbox.type='checkbox';label.append(checkbox,document.createTextNode(' I checked this page'));controls.append(label);
    const issue=document.createElement('div');issue.style.color='#9a3412';
    const columns=document.createElement('div');columns.style.cssText='display:flex;flex:1;gap:14px;min-height:0;overflow:auto';
    const imagePane=document.createElement('div');imagePane.style.cssText='flex:1;min-width:240px;overflow:auto;background:#eee';
    const textarea=document.createElement('textarea');textarea.setAttribute('aria-label','Editable extracted page text');textarea.style.cssText='flex:1;min-width:240px;padding:12px;resize:none;font:15px/1.6 system-ui;white-space:pre-wrap';columns.append(imagePane,textarea);
    const footer=document.createElement('div'),cancel=document.createElement('button'),save=document.createElement('button');
    cancel.textContent='Cancel upload';save.textContent='Use checked text and continue';footer.append(cancel,save);
    for(const b of [cancel,save])b.style.cssText='padding:10px 15px;margin-right:10px;border:1px solid #aaa;border-radius:6px;cursor:pointer';
    save.style.background='#7253ed';save.style.color='white';
    panel.append(heading,description,controls,issue,columns,footer);overlay.append(panel);document.body.append(overlay);
    let active=Number(reports.keys().next().value ?? 0),generation=0,closed=false;
    const checked=new Set();
    function update(){save.disabled=[...reports.keys()].some(i=>!checked.has(i));save.style.opacity=save.disabled?'.45':'1';}
    async function show(index){
      const revision=++generation;active=index;select.value=String(index);textarea.value=pages[index];checkbox.checked=checked.has(index);
      issue.textContent=reports.get(index)?.join(' ') || 'Selectable text / no OCR warning on this page.';
      imagePane.textContent='Loading page image…';
      try{
        const page=await pdf.getPage(index+1),natural=page.getViewport({scale:1}),v=page.getViewport({scale:Math.min(2,1100/natural.width)});
        const canvas=document.createElement('canvas');canvas.width=Math.ceil(v.width);canvas.height=Math.ceil(v.height);canvas.style.width='100%';
        await page.render({canvasContext:canvas.getContext('2d'),viewport:v,background:'white'}).promise;
        if(!closed&&revision===generation)imagePane.replaceChildren(canvas);
      }catch(e){if(!closed&&revision===generation)imagePane.textContent='Page preview failed: '+e.message;}
      update();
    }
    textarea.oninput=()=>{pages[active]=textarea.value;checked.delete(active);checkbox.checked=false;update()};
    checkbox.onchange=()=>{if(checkbox.checked)checked.add(active);else checked.delete(active);update()};
    select.onchange=()=>show(Number(select.value));
    cancel.onclick=()=>{closed=true;generation++;overlay.remove();reject(Error('Upload cancelled. No book was saved.'))};
    save.onclick=()=>{if(save.disabled)return;closed=true;generation++;overlay.remove();resolve()};
    show(active);
  });
}
