#!/usr/bin/env node
// Stages 6-7: voiceover, captions, frame-accurate render and encode for one ad.
// Usage: node render.mjs <campaignDir>/ads/<id>/storyboard.json
//          [--aspects 9x16,4x5,1x1] [--stills 4x5,1x1] [--no-video] [--fps 30] [--preview] [--voice elevenlabs|kokoro|espeak|none]
// Writes: ads/<id>/render/<id>_<aspect>.mp4, ads/<id>/render/thumb.jpg, ads/<id>/stills/<aspect>/NN.png
import fs from 'node:fs';
import path from 'node:path';
import { spawn, execFileSync } from 'node:child_process';
import { once } from 'node:events';
import { pathToFileURL } from 'node:url';
import { fontFaceCss } from './fonts.mjs';
import { ROOT, parseArgs, readJSON, writeJSON, launchBrowser, ffmpeg, python, log } from './lib.mjs';

const args = parseArgs();
const sbPath = path.resolve(args._[0] || '');
if (!args._[0] || !fs.existsSync(sbPath)) {
  console.error('Usage: node render.mjs <campaignDir>/ads/<id>/storyboard.json [--aspects 9x16,4x5,1x1] [--stills 4x5,1x1] [--no-video] [--fps 30] [--preview] [--voice elevenlabs|kokoro|espeak|none]');
  process.exit(1);
}
const sb = readJSON(sbPath);
const adDir = path.dirname(sbPath);
const campaignDir = findCampaignDir(adDir);
const brand = readJSON(path.join(campaignDir, 'brand.json'));
const ASPECTS = readJSON(path.join(ROOT, 'config', 'aspects.json'));
const fps = Number(args.fps || sb.fps || 30);
const preview = !!args.preview; // quick low-res check: half size, 15 fps
const aspects = args['no-video'] ? [] : String(args.aspects || sb.aspects?.join(',') || '9x16,4x5,1x1').split(',');
const stillAspects = args.stills === true ? ['4x5', '1x1'] : args.stills ? String(args.stills).split(',') : (sb.stills || []);
const renderDir = path.join(adDir, preview ? 'preview' : 'render');
const workDir = path.join(adDir, '.work');
fs.mkdirSync(renderDir, { recursive: true });
fs.mkdirSync(workDir, { recursive: true });

function findCampaignDir(dir) {
  for (let d = dir; d !== path.dirname(d); d = path.dirname(d)) if (fs.existsSync(path.join(d, 'brand.json'))) return d;
  throw new Error('No brand.json found above ' + dir + '. Run the brand-kit step first.');
}
const fileUrl = p => (p ? pathToFileURL(path.resolve(campaignDir, p)).href : null);

// 1. Voiceover, one clip per scene.
const TRANSITION = 0.35, VO_LEAD = 0.3, VO_TAIL = 0.55;
const MIN = { hook: 2.8, image: 3.0, stat: 3.0, quote: 3.8, split: 3.2, cta: 3.8 };
const voLines = sb.scenes.map((s, i) => ({ id: `s${i}`, text: s.vo, out: path.join(workDir, `vo-${i}.wav`) })).filter(l => l.text);
let tts = { engine: 'none', durations: {}, words: {} };
if (voLines.length && sb.voice?.engine !== 'none') {
  const jobFile = path.join(workDir, 'tts-job.json');
  writeJSON(jobFile, {
    engine: args.voice || sb.voice?.engine || 'auto', voice: sb.voice?.voice, speed: sb.voice?.speed,
    model: sb.voice?.model, voiceSettings: sb.voice?.settings, fallbackVoice: sb.voice?.fallbackVoice, lines: voLines,
  });
  log('voiceover…');
  tts = JSON.parse(execFileSync(python(), [path.join(ROOT, 'scripts', 'tts.py'), jobFile], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim().split('\n').at(-1));
  tts.words ||= {};
  log('voice engine:', tts.engine);
}

// 2. Timeline and caption cues.
let t = 0;
const cues = [];
sb.scenes.forEach((s, i) => {
  // Captions show the spoken words only, without ElevenLabs [tags] or <break/> markup.
  const words = (s.vo || '').replace(/\[[^\]]*\]|<[^>]*>/g, ' ').split(/\s+/).filter(Boolean);
  const vo = tts.durations[`s${i}`] ?? (words.length ? words.length / 2.7 : 0);
  const min = s.type === 'list' ? 1.6 + 0.45 * (s.items?.length || 0) + 1.2 : MIN[s.type] || 3;
  const dur = s.duration || Math.max(min, vo ? VO_LEAD + vo + VO_TAIL : 0);
  s._t = { start: +t.toFixed(3), end: +(t + dur).toFixed(3), voStart: +(t + VO_LEAD).toFixed(3), vo };
  if (words.length) {
    // Exact word timings when the voice engine provides them (ElevenLabs), else spread by word length.
    const timed = tts.words[`s${i}`];
    let ws;
    if (timed?.length) {
      ws = timed.map(w => ({ text: w.text, start: s._t.voStart + w.start, end: s._t.voStart + w.end }));
    } else {
      const weights = words.map(w => w.length + 2), total = weights.reduce((a, b) => a + b, 0);
      let acc = 0;
      ws = words.map((w, k) => { const st = s._t.voStart + (acc / total) * vo; acc += weights[k]; return { text: w, start: st, end: s._t.voStart + (acc / total) * vo }; });
    }
    const groups = [];
    let g = [];
    ws.forEach((w, k) => { g.push(w); if (g.length >= 4 || /[.,!?;:]$/.test(w.text) || k === ws.length - 1) { groups.push(g); g = []; } });
    const out = groups.map((gw, k) => ({ start: gw[0].start, end: k < groups.length - 1 ? groups[k + 1][0].start : Math.min(s._t.end, gw.at(-1).end + 0.4), words: gw }));
    cues.push({ start: s._t.voStart, end: out.at(-1).end, groups: out });
  }
  t += dur;
});
const total = +t.toFixed(3);
log(`timeline ${total.toFixed(1)} s, ${sb.scenes.length} scenes`);
if (sb.maxSeconds && total > sb.maxSeconds) log(`warning: ${total.toFixed(1)} s is longer than maxSeconds ${sb.maxSeconds}`);

// 3. Audio mix: voice clips at their offsets, optional music bed, loudness to about -14 LUFS.
const audioFile = path.join(workDir, 'mix.m4a');
{
  const inputs = [], filters = [], labels = [];
  sb.scenes.forEach((s, i) => {
    const f = path.join(workDir, `vo-${i}.wav`);
    if (!s.vo || !tts.durations[`s${i}`] || !fs.existsSync(f)) return;
    inputs.push('-i', f);
    const ms = Math.round(s._t.voStart * 1000);
    filters.push(`[${labels.length}:a]aresample=48000,adelay=${ms}|${ms},aformat=channel_layouts=stereo[v${labels.length}]`);
    labels.push(`[v${labels.length}]`);
  });
  const music = sb.music?.file ? path.resolve(campaignDir, sb.music.file) : null;
  let n = labels.length;
  if (music && fs.existsSync(music)) {
    inputs.push('-stream_loop', '-1', '-i', music);
    filters.push(`[${n}:a]aresample=48000,aformat=channel_layouts=stereo,volume=${sb.music.volume ?? (n ? 0.16 : 0.6)},atrim=0:${total},afade=t=out:st=${Math.max(0, total - 1.5)}:d=1.5[m]`);
    labels.push('[m]');
  }
  if (!labels.length) {
    execFileSync(ffmpeg(), ['-y', '-loglevel', 'error', '-f', 'lavfi', '-i', `anullsrc=r=48000:cl=stereo`, '-t', String(total), '-c:a', 'aac', audioFile]);
  } else {
    filters.push(`${labels.join('')}amix=inputs=${labels.length}:normalize=0:duration=longest,apad,atrim=0:${total},loudnorm=I=-14:TP=-1.5:LRA=11[out]`);
    execFileSync(ffmpeg(), ['-y', '-loglevel', 'error', ...inputs, '-filter_complex', filters.join(';'), '-map', '[out]', '-ar', '48000', '-c:a', 'aac', '-b:a', '192k', audioFile]);
  }
}

// 4. Frames: one HTML player per aspect, seeked frame by frame in Chromium, piped to ffmpeg.
const template = fs.readFileSync(path.join(ROOT, 'scripts', 'templates', 'player.html'), 'utf8');
const scenesForPage = sb.scenes.map(s => ({ ...s, image: fileUrl(s.image) }));
const brandForPage = {
  ...brand,
  logo: fileUrl(brand.logo),
  colors: { primary: '#1d3b6f', accent: '#f5b82e', dark: '#111418', light: '#f6f4ef', ...brand.colors },
  fonts: { heading: 'Montserrat', body: 'Inter', ...brand.fonts },
};

const fontLinks = await fontFaceCss([brandForPage.fonts.heading, brandForPage.fonts.body]);

async function openPlayer(browser, aspect, scale, captions) {
  const { width, height } = ASPECTS[aspect];
  const data = { width, height, brand: brandForPage, scenes: scenesForPage, cues, captions, transition: TRANSITION };
  const html = template.replace('<!--FONTS-->', `${fontLinks}<script>window.__DATA__=${JSON.stringify(data).replace(/</g, '\\u003c')}</script>`);
  const file = path.join(workDir, `player-${aspect}.html`);
  fs.writeFileSync(file, html);
  const page = await browser.newPage({ viewport: { width: Math.round(width * scale), height: Math.round(height * scale) }, deviceScaleFactor: 1 });
  page.on('pageerror', e => log('page error:', e.message));
  await page.goto(pathToFileURL(file).href);
  if (scale !== 1) await page.evaluate(s => { document.body.style.zoom = s; }, scale);
  await page.evaluate(() => window.__ready);
  return page;
}

const browser = await launchBrowser({ args: ['--allow-file-access-from-files', '--font-render-hinting=none'] });
const outputs = [];
try {
  for (const aspect of aspects) {
    const scale = preview ? 0.5 : 1;
    const rate = preview ? 15 : fps;
    const page = await openPlayer(browser, aspect, scale, sb.captions !== false);
    const out = path.join(renderDir, `${sb.id}_${aspect}.mp4`);
    const frames = Math.ceil(total * rate);
    const enc = spawn(ffmpeg(), ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-framerate', String(rate), '-c:v', 'mjpeg', '-i', '-', '-i', audioFile,
      '-c:v', 'libx264', '-preset', preview ? 'veryfast' : 'medium', '-crf', '19', '-pix_fmt', 'yuv420p', '-r', String(rate),
      '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart', out], { stdio: ['pipe', 'inherit', 'inherit'] });
    const started = Date.now();
    for (let f = 0; f < frames; f++) {
      await page.evaluate(tt => window.__seek(tt), f / rate);
      const buf = await page.screenshot({ type: 'jpeg', quality: 92 });
      if (!enc.stdin.write(buf)) await once(enc.stdin, 'drain');
      if (f % (rate * 5) === 0) log(`${aspect}: ${(f / rate).toFixed(0)}/${total.toFixed(0)} s`);
    }
    if (aspect === aspects[0]) {
      const hook = sb.scenes[0]._t;
      await page.evaluate(tt => window.__seek(tt), Math.min(hook.end - 0.1, hook.start + 1.6));
      await page.screenshot({ path: path.join(renderDir, 'thumb.jpg'), type: 'jpeg', quality: 90 });
    }
    enc.stdin.end();
    const [code] = await once(enc, 'close');
    if (code !== 0) throw new Error(`ffmpeg failed for ${aspect}`);
    log(`${aspect}: done in ${((Date.now() - started) / 1000).toFixed(0)} s -> ${out}`);
    outputs.push(path.relative(campaignDir, out));
    await page.close();
  }

  // Stills: one slide per scene, captions off. Good for carousels and static posts.
  for (const aspect of stillAspects) {
    const page = await openPlayer(browser, aspect, 1, false);
    const dir = path.join(adDir, 'stills', aspect);
    fs.mkdirSync(dir, { recursive: true });
    for (const [i, s] of sb.scenes.entries()) {
      await page.evaluate(tt => window.__seek(tt), Math.min(s._t.end - 0.05, s._t.start + 2.6));
      const file = path.join(dir, `${String(i + 1).padStart(2, '0')}-${s.type}.png`);
      await page.screenshot({ path: file });
      outputs.push(path.relative(campaignDir, file));
    }
    await page.close();
    log(`stills ${aspect}: ${sb.scenes.length} slides`);
  }
} finally {
  await browser.close();
}

writeJSON(path.join(renderDir, 'timeline.json'), {
  id: sb.id, seconds: total, voiceEngine: tts.engine, renderedAt: new Date().toISOString(),
  scenes: sb.scenes.map(s => ({ type: s.type, ...s._t })), outputs,
});
console.log(JSON.stringify({ id: sb.id, seconds: total, voiceEngine: tts.engine, outputs }, null, 2));
