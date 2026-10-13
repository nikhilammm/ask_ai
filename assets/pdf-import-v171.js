// Shared browser extraction for books and answer keys. No hosting binaries needed.
import { convertAps, isApsFont } from './aps-text-v169.js';
window.eduAiPdfExtractorStatus = 'loading';
const engine = import('./pdfjs/pdf.min.mjs').then(pdfjs => {
  pdfjs.GlobalWorkerOptions.workerSrc = new URL('./pdfjs/pdf.worker.min.mjs', import.meta.url).href;
  window.eduAiPdfExtractorStatus = 'ready';
  return pdfjs;
}).catch(error => {
  window.eduAiPdfExtractorStatus = 'error';
  throw error;
});
// Prevent an unhandled rejection before a user starts an upload.
engine.catch(() => {});

// Memory-only, scoped to the signed-in user and selected material.
const chats = new Map();
window.eduAiChatHistory = {
  get(token, book, chapter, lesson) { return chats.get(JSON.stringify([token, book, chapter, lesson])) || []; },
  add(token, book, chapter, lesson, question, answer) {
    const key = JSON.stringify([token, book, chapter, lesson]);
    chats.set(key, [...(chats.get(key) || []), {role:'user',content:question}, {role:'assistant',content:answer}].slice(-6));
    if (chats.size > 20) chats.delete(chats.keys().next().value);
  },
  clear() { chats.clear(); }
};

export function joinPdfItems(items) {
  let result = '', previous = null;
  const seen = new Set();
  for (const item of items) {
    if (typeof item.str !== 'string') continue;
    const [,,, , x = 0, y = 0] = item.transform || [];
    const signature = `${item.str}:${Math.round(x * 2)}:${Math.round(y * 2)}`;
    if (item.str && seen.has(signature)) continue;
    seen.add(signature);
    const height = Math.max(1, Math.abs(item.height || item.transform?.[3] || 10));
    if (previous && !result.endsWith('\n')) {
      const newLine = Math.abs(y - previous.y) > Math.max(height, previous.height) * .65;
      const gap = x - previous.end;
      if (newLine) result += '\n';
      else if (gap > height * .16 && !/\s$/.test(result) && !/^\s|^[\u093a-\u094d\u0951-\u0957]/u.test(item.str) && !/\u094d$/u.test(result)) result += ' ';
    }
    result += item.str;
    if (item.hasEOL) result += '\n';
    previous = { y, end: x + (item.width || 0), height };
  }
  return result.normalize('NFC').replace(/[\t ]+/g, ' ').replace(/ *\n */g, '\n').trim();
}

function readableItems(items, page, width) {
  const names = Object.fromEntries([...new Set(items.map(i=>i.fontName))].map(key=>[key, page.commonObjs.has(key) ? page.commonObjs.get(key).name : '']));
  if (!Object.values(names).some(isApsFont)) return items;
  let ordered = items.filter(i=>typeof i.str === 'string');
  const meaningful = ordered.filter(i=>i.str.trim());
  const middle = width / 2;
  const crossing = meaningful.filter(i=>i.transform[4]<middle-10 && i.transform[4]+i.width>middle+10).length;
  const left = meaningful.filter(i=>i.transform[4]<middle).length;
  const right = meaningful.length-left;
  const columns = crossing < meaningful.length*.04 && left>10 && right>10;
  ordered = ordered.map((item,index)=>({...item,index})).sort((a,b)=>{
    if(columns) {const ca=+(a.transform[4]>=middle), cb=+(b.transform[4]>=middle);if(ca!==cb)return ca-cb;}
    const dy=b.transform[5]-a.transform[5];
    return Math.abs(dy)>3 ? dy : a.transform[4]-b.transform[4] || a.index-b.index;
  });
  const output=[], seen=new Map();
  for(const item of ordered) {
    const x=item.transform[4],y=item.transform[5];
    const locations=seen.get(item.str)||[];
    if(item.str && locations.some(p=>Math.abs(p[0]-x)<3 && Math.abs(p[1]-y)<3))continue;
    locations.push([x,y]);seen.set(item.str,locations);
    // APS letters can be split into separate PDF items; join same-font neighbours before mapping.
    const last=output.at(-1);
    if(last && isApsFont(names[item.fontName]) && last.fontName===item.fontName && Math.abs(last.transform[5]-y)<3 && x-(last.transform[4]+last.width)<3 && x>=last.transform[4]) {
      last.str+=item.str;last.width=x+item.width-last.transform[4];last.hasEOL=item.hasEOL;
    } else output.push({...item,hasEOL:false});
  }
  return output.map(item=>({...item,hasEOL:false,str:isApsFont(names[item.fontName])?convertAps(item.str):item.str}));
}

export function needsOcr(text, subject = '', styles = {}) {
  const letters = (text.match(/\p{L}/gu) || []).length;
  if (!letters) return true;
  const badGlyphs = (text.match(/[\uFFFD\uE000-\uF8FF]/gu) || []).length;
  const hindiSubject = /hindi|हिंदी|हिन्दी/i.test(subject);
  if (badGlyphs && (hindiSubject || letters < 40 || badGlyphs / letters > .02)) return true;
  if (Object.values(styles).some(s => /kruti|devlys|chanakya|shusha|krutidev/i.test(s.fontFamily || ''))) return true;
  const hindi = (text.match(/[\u0900-\u097f]/gu) || []).length;
  return /hindi|हिंदी|हिन्दी/i.test(subject) && hindi < letters * .25;
}

async function readPageImage(page, options, pageNumber) {
  options.onProgress?.(`Reading page ${pageNumber} from its image (Hindi/English text recovery)...`);
  const natural = page.getViewport({ scale: 1 });
  const viewport = page.getViewport({ scale: Math.min(3.5, 2800 / Math.max(natural.width, natural.height)) });
  const canvas = document.createElement('canvas');
  canvas.width = Math.ceil(viewport.width);
  canvas.height = Math.ceil(viewport.height);
  try {
    await page.render({ canvasContext: canvas.getContext('2d'), viewport, background: 'rgb(255,255,255)' }).promise;
    let local = null;
    try {
      const worker = await options.getOcrWorker();
      local = (await worker.recognize(canvas)).data;
      const text = local.text.normalize('NFC').trim();
      const hindiExpected = /hindi|हिंदी|हिन्दी/i.test(options.subject || '');
      if (local.confidence >= 65 && text.length >= 10 && (!hindiExpected || /[\u0900-\u097f]/u.test(text))) return text;
      if (options.localOnly && text) return text;
    } catch (error) {
      if (options.localOnly) throw error;
    }
    if (!options.token) {
      if (local?.text?.trim() && local.confidence >= 35) {
        options.reviewPages.push(pageNumber);
        return local.text.normalize('NFC').trim();
      }
      throw Error('Text recovery needs a signed-in staff session.');
    }
    const response = await fetch(`${options.base || ''}/api/content/ocr-page`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${options.token}` },
      body: JSON.stringify({ image: canvas.toDataURL('image/jpeg', .88), page: pageNumber })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || typeof data.text !== 'string') {
      if (local?.text?.trim() && local.confidence >= 35) {
        options.reviewPages.push(pageNumber);
        return local.text.normalize('NFC').trim();
      }
      throw Error(data.detail || `Text recovery failed on page ${pageNumber}. Please retry.`);
    }
    return data.text.normalize('NFC').trim();
  } finally { canvas.width = canvas.height = 0; }
}

window.eduAiExtractPdfText = async function(file, options = {}) {
  options = { ...options, reviewPages: [] };
  let worker = null;
  options.getOcrWorker = async () => {
    if (!worker) {
      if (!window.Tesseract) await new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = new URL('./ocr/tesseract.min.js', import.meta.url).href;
        script.onload = resolve;
        script.onerror = () => reject(Error('Local OCR engine could not load.'));
        document.head.append(script);
      });
      worker = await window.Tesseract.createWorker(/hindi|हिंदी|हिन्दी/i.test(options.subject || '') ? 'hin+eng' : /english/i.test(options.subject || '') ? 'eng' : 'eng+hin', 1, {
        workerPath: new URL('./ocr/worker.min.js', import.meta.url).href,
        corePath: new URL('./ocr/', import.meta.url).href,
        langPath: new URL('./ocr/lang/', import.meta.url).href,
        gzip: false,
        logger: event => { if (event.status === 'recognizing text') options.onProgress?.(`Recognizing Hindi/English text: ${Math.round(event.progress * 100)}%`); }
      });
      await worker.setParameters({ preserve_interword_spaces: '1' });
    }
    return worker;
  };
  const pdfjs = await engine;
  const bytes = new Uint8Array(await file.arrayBuffer());
  // Verified contents for this exact scan; never apply a book profile by filename.
  let bookProfile = '';
  if (bytes.byteLength === 187862775 && globalThis.crypto?.subtle) {
    const hash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)), b => b.toString(16).padStart(2, '0')).join('');
    if (hash === '41a428b872efdb51a72d51a06dd0cb05ffceca2a96e525f2f5a9607b6fc870c0') bookProfile = hash;
  }
  const task = pdfjs.getDocument({
    data: bytes,
    cMapUrl: new URL('./pdfjs/cmaps/', import.meta.url).href, cMapPacked: true,
    standardFontDataUrl: new URL('./pdfjs/standard_fonts/', import.meta.url).href,
    wasmUrl: new URL('./pdfjs/wasm/', import.meta.url).href,
    isEvalSupported: false, stopAtErrors: true, enableXfa: true, fontExtraProperties: true
  });
  try {
    const pdf = await task.promise, pages = [];
    for (let number = 1; number <= pdf.numPages; number++) {
      options.onProgress?.(`Reading PDF page ${number} of ${pdf.numPages}...`);
      const page = await pdf.getPage(number);
      try {
        let content = { items: [], styles: {} }, extractionFailed = false;
        try { content = await page.getTextContent(); } catch { extractionFailed = true; }
        if(content.items.some(i=>i.str?.trim())) await page.getOperatorList();
        let text = joinPdfItems(readableItems(content.items, page, page.getViewport({scale:1}).width));
        if (extractionFailed || needsOcr(text, options.subject, content.styles)) {
          text = await readPageImage(page, options, number);
        }
        pages.push(text);
      } catch (error) {
        throw Error(`Page ${number}: ${error.message} Book has not been saved; no pages were silently skipped.`);
      } finally { page.cleanup(); }
    }
    // Keep Hindi's existing import path intact. Other books retain physical pages
    // so Contents page numbers can be checked against actual chapter boundaries.
    const hindiBook = /hindi|हिंदी|हिन्दी/i.test(options.subject || '') || /[\u0900-\u097f]/u.test(pages.slice(0, 3).join(''));
    const text = bookProfile ? `[[EDUAI_BOOK:${bookProfile}]]\n${pages.join('\n\f\n')}` : hindiBook ? pages.join('\n\n').trim() : `[[EDUAI_PAGES_V1]]\n${pages.join('\n\f\n')}`;
    window.eduAiLastPdfReport = options.reviewPages.length ? ` OCR review recommended for page(s): ${options.reviewPages.join(', ')}.` : '';
    if (text.length < 20) throw Error('This PDF contains too little readable text after extraction and image recovery. Please check the file.');
    return text;
  } catch (error) {
    if (error.name === 'PasswordException') throw Error('This PDF is password protected. Upload an unlocked copy.');
    throw error;
  } finally { await task.destroy(); if (worker) await worker.terminate(); }
};
