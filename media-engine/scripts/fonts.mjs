// Google Fonts, cached locally so renders are offline, fast and repeatable.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { HOME, log } from './lib.mjs';

const SYSTEM = /^(liberation|dejavu|arial|helvetica|georgia|times|verdana|system-ui|sans-serif|serif)/i;
// A modern browser user agent makes Google return woff2.
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

async function get(url, binary) {
  try {
    const res = await fetch(url, { headers: { 'user-agent': UA } });
    if (!res.ok) throw new Error(res.status);
    return binary ? Buffer.from(await res.arrayBuffer()) : await res.text();
  } catch {
    // curl honours HTTPS_PROXY and system CA settings where Node's fetch may not.
    const out = execFileSync('curl', ['-sfL', '-m', '30', '-A', UA, url], { encoding: binary ? 'buffer' : 'utf8' });
    return out;
  }
}

// Returns a <style> block with @font-face rules pointing at cached files, or '' if unavailable.
export async function fontFaceCss(families) {
  const wanted = [...new Set(families.filter(f => f && !SYSTEM.test(f)))];
  let css = '';
  for (const family of wanted) {
    const dir = path.join(HOME, 'fonts', family.replace(/[^a-z0-9]+/gi, '_'));
    const cached = path.join(dir, 'font.css');
    if (!fs.existsSync(cached)) {
      try {
        const src = await get(`https://fonts.googleapis.com/css2?family=${encodeURIComponent(family)}:wght@400;500;600;700;800;900&display=block`);
        fs.mkdirSync(dir, { recursive: true });
        let n = 0, local = src;
        for (const url of new Set(src.match(/https:\/\/fonts\.gstatic\.com\/[^)]+/g) || [])) {
          const file = path.join(dir, `f${n++}.woff2`);
          fs.writeFileSync(file, await get(url, true));
          local = local.split(url).join(pathToFileURL(file).href);
        }
        fs.writeFileSync(cached, local);
      } catch (e) {
        log(`font "${family}" unavailable (${e.message.split('\n')[0]}); using a fallback font`);
        continue;
      }
    }
    css += fs.readFileSync(cached, 'utf8');
  }
  return css ? `<style>${css}</style>` : '';
}
