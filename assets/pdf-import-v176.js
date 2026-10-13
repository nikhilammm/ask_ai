// English rollback: original selectable-text extraction. Hindi uses its existing path.
import './pdf-import-v169.js';
const hindiExtractor=window.eduAiExtractPdfText;
const pdfEngine=import('./pdfjs/pdf.min.mjs');
export async function legacyEnglish(file){
 const {getDocument,GlobalWorkerOptions}=await pdfEngine;
 GlobalWorkerOptions.workerSrc=new URL('./pdfjs/pdf.worker.min.mjs',import.meta.url).href;
 const extract=async function(file){const data=new Uint8Array(await file.arrayBuffer());const documentTask=getDocument({data});const pdf=await documentTask.promise;const pages=[];try{for(let pageNumber=1;pageNumber<=pdf.numPages;pageNumber+=1){const page=await pdf.getPage(pageNumber);const content=await page.getTextContent();const lines=[];const seenItems=[];let line=[];let previousY=null;const orderedItems=[...content.items].sort((a,b)=>{const ay=Array.isArray(a?.transform)?a.transform[5]:0,by=Array.isArray(b?.transform)?b.transform[5]:0,ax=Array.isArray(a?.transform)?a.transform[4]:0,bx=Array.isArray(b?.transform)?b.transform[4]:0;return Math.abs(by-ay)>2?by-ay:ax-bx});for(const item of orderedItems){if(!item||typeof item.str!=='string')continue;const currentY=Array.isArray(item.transform)?item.transform[5]:previousY;const currentX=Array.isArray(item.transform)?item.transform[4]:null;const duplicate=seenItems.some(previous=>previous.str===item.str&&currentX!==null&&currentY!==null&&Math.abs(previous.x-currentX)<3&&Math.abs(previous.y-currentY)<3);if(duplicate)continue;if(currentX!==null&&currentY!==null)seenItems.push({str:item.str,x:currentX,y:currentY});if(previousY!==null&&currentY!==null&&Math.abs(currentY-previousY)>2&&line.length){lines.push(line.join(' ').replace(/\s+/g,' ').trim());line=[]}if(item.str.trim())line.push(item.str.trim());if(item.hasEOL&&line.length){lines.push(line.join(' ').replace(/\s+/g,' ').trim());line=[]}previousY=currentY}if(line.length)lines.push(line.join(' ').replace(/\s+/g,' ').trim());pages.push(lines.filter(Boolean).join('\n'))}}finally{await documentTask.destroy()}return pages.join('\n\n').trim()};
 return extract(file);
}
window.eduAiExtractPdfText=async function(file,options={}){
 if(/hindi|हिंदी|हिन्दी/i.test(options.subject || ''))return hindiExtractor(file,options);
 options.onProgress?.('Reading selectable PDF text (original English importer)...');
 window.eduAiLastPdfReport='';
 const text=await legacyEnglish(file);
 if(text.length<20)throw Error('No selectable text was found. Original English mode does not use OCR. Upload a PDF with a readable text layer.');
 return '[[EDUAI_LEGACY_ENGLISH]]\n'+text;
};
