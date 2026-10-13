import mapping from './aps-mapping-v169.js';

export function convertAps(text) {
  // APS stores short-i before its consonant and reph after the syllable.
  // Apply the encoding's ordered ligature expansions, then restore Unicode order.
  for (const [from, to] of mapping) text = text.split(from).join(to);
  text = text.replace(/µ([\u0900-\u097f])/gu, '$1ि');
  for (let i=0; i<8 && /िं?्[\u0915-\u0939]/u.test(text); i++) {
    text = text.replace(/ि(ं?)्([\u0915-\u0939])/gu, '्$2ि$1');
  }
  text = text.replace(/([\u0915-\u0939\u0958-\u095f](?:्[\u0915-\u0939\u0958-\u095f])*[\u093c-\u094c\u0900-\u0903]*)&/gu, 'र्$1');
  return text.normalize('NFC');
}

export function isApsFont(name) { return /APS[-_ ]?(?:C[-_ ]?)?DV/i.test(name || ''); }
