#!/usr/bin/env node
// One-time setup of the free toolchain. Safe to re-run.
//   node scripts/setup.mjs          install everything
//   node scripts/setup.mjs --check  report what is installed
//   node scripts/setup.mjs --elevenlabs-key   save an ElevenLabs API key (typed hidden, stored only on this machine)
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import { ROOT, HOME, parseArgs, ffmpeg, python, launchBrowser } from './lib.mjs';

const args = parseArgs();
const models = path.join(HOME, 'models');
const FILES = {
  'kokoro-v1.0.onnx': 'https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/kokoro-v1.0.onnx',
  'voices-v1.0.bin': 'https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/voices-v1.0.bin',
};
const run = (cmd, a, opts = {}) => spawnSync(cmd, a, { stdio: 'inherit', cwd: ROOT, shell: process.platform === 'win32', ...opts }).status === 0;
const report = [];
const keyFile = path.join(HOME, 'elevenlabs.key');

// Hidden prompt: the key is never echoed, logged, or written anywhere but keyFile (mode 600).
async function promptHidden(question) {
  const readline = await import('node:readline');
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout, terminal: true });
  rl._writeToOutput = s => { if (s.startsWith(question)) process.stdout.write(s); };
  const answer = await new Promise(resolve => rl.question(question, resolve));
  rl.close();
  process.stdout.write('\n');
  return answer.trim();
}

async function elevenlabsStatus(key) {
  const res = await fetch('https://api.elevenlabs.io/v1/user/subscription', { headers: { 'xi-api-key': key } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const s = await res.json();
  return `${s.tier} plan, ${(s.character_limit - s.character_count).toLocaleString()} of ${s.character_limit.toLocaleString()} credits left`;
}

if (args['elevenlabs-key']) {
  if (!process.stdin.isTTY) { console.error('Run this in a terminal so the key can be typed privately.'); process.exit(1); }
  const key = await promptHidden('Paste your ElevenLabs API key (input hidden): ');
  if (!key) { console.error('No key entered; nothing saved.'); process.exit(1); }
  try { console.log('Key works: ' + await elevenlabsStatus(key)); }
  catch (e) { console.log(`Could not verify the key (${e.message}); saving it anyway.`); }
  fs.mkdirSync(HOME, { recursive: true });
  fs.writeFileSync(keyFile, key + '\n', { mode: 0o600 });
  fs.chmodSync(keyFile, 0o600);
  console.log(`Saved to ${keyFile}. Videos will now use ElevenLabs voices. Delete that file to go back to the free voice.`);
  process.exit(0);
}

if (!args.check && !args['elevenlabs-key']) {
  if (!fs.existsSync(path.join(ROOT, 'node_modules', 'playwright'))) run('npm', ['install', '--no-audit', '--no-fund']);
  if (!process.env.SVM_CHROMIUM) run('npx', ['playwright', 'install', 'chromium']);
  run(python(), ['-m', 'pip', 'install', '--quiet', '--user', 'kokoro-onnx', 'soundfile', 'imageio-ffmpeg']) ||
    run(python(), ['-m', 'pip', 'install', '--quiet', 'kokoro-onnx', 'soundfile', 'imageio-ffmpeg']);
  fs.mkdirSync(models, { recursive: true });
  for (const [name, url] of Object.entries(FILES)) {
    const file = path.join(models, name);
    if (fs.existsSync(file) && fs.statSync(file).size > 1e6) continue;
    console.log(`Downloading ${name} (free Kokoro voice model)…`);
    run('curl', ['-fL', '--retry', '3', '-o', file + '.part', url]) && fs.renameSync(file + '.part', file);
  }
}

try { const b = await launchBrowser(); await b.close(); report.push('✓ Chromium'); } catch (e) { report.push('✗ Chromium: ' + e.message.split('\n')[0]); }
try { report.push('✓ ffmpeg: ' + ffmpeg()); } catch (e) { report.push('✗ ' + e.message); }
const kok = spawnSync(python(), ['-c', 'import kokoro_onnx, soundfile'], { stdio: 'ignore' }).status === 0
  && Object.keys(FILES).every(f => fs.existsSync(path.join(models, f)));
report.push(kok ? '✓ Kokoro voice (free, local)' : '✗ Kokoro voice: not installed; videos fall back to espeak-ng or captions only');
const keychain = () => {
  if (process.platform !== 'darwin') return '';
  const r = spawnSync('security', ['find-generic-password', '-s', 'svm-elevenlabs', '-w'], { encoding: 'utf8' });
  return r.status === 0 ? r.stdout.trim() : '';
};
const elKey = process.env.ELEVENLABS_API_KEY || keychain() || (fs.existsSync(keyFile) ? fs.readFileSync(keyFile, 'utf8').trim() : '');
if (elKey) {
  try { report.push('✓ ElevenLabs voice: ' + await elevenlabsStatus(elKey)); }
  catch (e) { report.push(`✗ ElevenLabs key found but not usable (${e.message}); videos fall back to Kokoro`); }
} else report.push('○ ElevenLabs voice: no key (optional). Add one with: node scripts/setup.mjs --elevenlabs-key');
console.log(report.join('\n'));
