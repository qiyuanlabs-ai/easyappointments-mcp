// Opens a rendered consent page in headless Chrome at desktop and phone widths, checks where the
// Allow and Deny buttons land, and saves a screenshot of each.
// Usage: CHROME=/usr/bin/google-chrome node demo/consent-layout.mjs consent.html screenshots/
import puppeteer from 'puppeteer-core';
import { pathToFileURL } from 'node:url';
import { mkdirSync } from 'node:fs';

const [file, outDir = 'screenshots'] = process.argv.slice(2);
mkdirSync(outDir, { recursive: true });
const browser = await puppeteer.launch({ executablePath: process.env.CHROME, args: ['--no-sandbox'] });
const failures = [];
const check = (ok, what) => { console.log(`${ok ? '  ok  ' : '  FAIL'} ${what}`); if (!ok) failures.push(what); };

for (const [name, width, height] of [['desktop', 1280, 900], ['phone', 390, 844]]) {
  for (const scheme of ['light', 'dark']) {
    const page = await browser.newPage();
    await page.setViewport({ width, height, deviceScaleFactor: 2 });
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);
    await page.goto(pathToFileURL(file).href);
    const box = (label) => page.evaluate((label) => {
      const b = [...document.querySelectorAll('button')].find((el) => el.textContent.trim() === label);
      if (!b) return null;
      const r = b.getBoundingClientRect(), card = b.closest('.card').getBoundingClientRect();
      return { left: r.left, right: r.right, top: r.top, width: r.width, cardLeft: card.left, cardRight: card.right };
    }, label);
    const [allow, deny] = [await box('Allow'), await box('Deny')];
    check(allow && deny, `${name}/${scheme}: both buttons are inside the card`);
    if (allow && deny) {
      if (name === 'desktop') {
        check(deny.right <= allow.left && Math.abs(deny.top - allow.top) < 2, `${name}/${scheme}: Deny sits left of Allow on one line`);
        check(allow.cardRight - allow.right < 40, `${name}/${scheme}: Allow is at the right edge of the card`);
      } else {
        check(allow.top < deny.top, `${name}/${scheme}: Allow is above Deny`);
        check(allow.width > 300 && deny.width > 300, `${name}/${scheme}: both buttons are full width`);
      }
    }
    await page.screenshot({ path: `${outDir}/consent-${name}-${scheme}.png`, fullPage: true });
    await page.close();
  }
}
await browser.close();
console.log(failures.length ? `\n${failures.length} layout check(s) failed` : '\nConsent layout looks right');
process.exit(failures.length ? 1 : 0);
