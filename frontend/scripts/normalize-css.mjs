/**
 * Normalize Webpack's generated stylesheet to a single POSIX newline.
 *
 * The compiled asset is committed to Git, and Git whitespace checks reject
 * extra blank lines at EOF. Do not edit/minify or rewrite CSS selectors here.
 */
import { readFileSync, writeFileSync } from 'node:fs';

const output = new URL('../../public/assets/admin/app.css', import.meta.url);
const original = readFileSync(output, 'utf8');
const normalized = original.trimEnd() + '\n';

if (normalized !== original) {
  writeFileSync(output, normalized, 'utf8');
}
