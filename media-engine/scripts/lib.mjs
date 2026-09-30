// Shared helpers for the social-video-maker scripts.
import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const HOME = process.env.SVM_HOME || path.join(os.homedir(), '.social-video-maker');

// Minimal flag parser: --key value, --flag, and positionals.
export function parseArgs(argv = process.argv.slice(2)) {
  const out = { _: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const key = a.slice(2);
      const next = argv[i + 1];
      if (next === undefined || next.startsWith('--')) out[key] = true;
      else { out[key] = next; i++; }
    } else out._.push(a);
  }
  return out;
}

export function readJSON(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

export function writeJSON(file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n');
}

export function slugify(s) {
  return String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60) || 'item';
}

// Chromium: explicit env var, then Playwright's bundled browser.
export async function launchBrowser(opts = {}) {
  const { chromium } = await import('playwright');
  const executablePath = process.env.SVM_CHROMIUM || undefined;
  return chromium.launch({ executablePath, ...opts });
}

export async function launchPersistent(userDataDir, opts = {}) {
  const { chromium } = await import('playwright');
  const executablePath = process.env.SVM_CHROMIUM || undefined;
  return chromium.launchPersistentContext(userDataDir, { executablePath, ...opts });
}

let ffmpegPath;
// ffmpeg: FFMPEG env var, then PATH, then the static build shipped by the imageio-ffmpeg pip package.
export function ffmpeg() {
  if (ffmpegPath) return ffmpegPath;
  const candidates = [process.env.FFMPEG, 'ffmpeg'].filter(Boolean);
  for (const c of candidates) {
    const r = spawnSync(c, ['-version'], { stdio: 'ignore' });
    if (r.status === 0) return (ffmpegPath = c);
  }
  try {
    const p = execFileSync(python(), ['-c', 'import imageio_ffmpeg;print(imageio_ffmpeg.get_ffmpeg_exe())'], { encoding: 'utf8' }).trim();
    if (p) return (ffmpegPath = p);
  } catch {}
  throw new Error('ffmpeg not found. Install ffmpeg, or run `npm run setup` to get a bundled copy.');
}

export function python() {
  return process.env.PYTHON || (process.platform === 'win32' ? 'python' : 'python3');
}

export function log(...a) {
  console.error('[svm]', ...a);
}
