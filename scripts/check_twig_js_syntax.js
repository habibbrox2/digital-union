#!/usr/bin/env node
/**
 * Scans all .twig templates for <script>...</script> blocks and runs
 * Node's syntax check on each.
 *
 * Twig {{ ... }} output expressions are masked with a safe placeholder so
 * scripts that embed Twig variables are still syntax-checked. Blocks using
 * control-flow delimiters ({% ... %}) are skipped because they restructure
 * the emitted JS (e.g. {% if %} wrapping statements) and cannot be checked
 * as-is.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const os = require('os');

function walk(dir, out = []) {
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    if (ent.name === 'node_modules' || ent.name === '.git') continue;
    const full = path.join(dir, ent.name);
    if (ent.isDirectory()) walk(full, out);
    else if (ent.name.endsWith('.twig')) out.push(full);
  }
  return out;
}

function maskTwigOutput(body) {
  // Replace {{ ... }} with a safe numeric literal ('0' is a valid expression
  // wherever an expression is expected, including inside strings/template
  // literals). Non-greedy matching stops at the first }} — the true Twig
  // terminator — so nested }}} can't occur. Known edge cases (acceptable for
  // a dev utility): a quoted '}}' inside a Twig string literal would end the
  // match early, and an unbalanced '{{' inside a JS string could over-match.
  return body.replace(/\{\{[\s\S]*?\}\}/g, '0');
}

const files = walk(path.resolve(__dirname, '..', 'templates'));
let failures = 0;
let checked = 0;
let skipped = 0;

for (const file of files) {
  const content = fs.readFileSync(file, 'utf8');
  const re = /<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/gi;
  let m;
  while ((m = re.exec(content)) !== null) {
    let body = m[1];
    // Skip empty blocks
    if (!body.trim()) continue;
    // Skip blocks with Twig control-flow delimiters (restructure the JS)
    if (/\{%[\s\S]*?%\}/.test(body)) {
      skipped++;
      continue;
    }
    body = maskTwigOutput(body);
    const tmp = path.join(os.tmpdir(), 'twig-script-check-' + Date.now() + '-' + Math.random().toString(36).slice(2) + '.js');
    fs.writeFileSync(tmp, body);
    checked++;
    try {
      execFileSync(process.execPath, ['--check', tmp], { stdio: ['ignore', 'ignore', 'pipe'] });
    } catch (e) {
      const msg = (e.stderr ? e.stderr.toString() : String(e)).split('\n').slice(0, 5).join('\n');
      console.log(`\n=== SYNTAX ERROR IN: ${path.relative(process.cwd(), file)}`);
      console.log(msg);
      failures++;
    } finally {
      fs.unlinkSync(tmp);
    }
  }
}

console.log(`\nChecked ${checked} inline script block(s). ${failures} failure(s). (${skipped} skipped for Twig control-flow delimiters.)`);
process.exit(failures ? 1 : 0);
