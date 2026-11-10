/**
 * Fail the release build if cPanel's exact static-file allowlist is not met.
 * Specifically catches CopyWebpackPlugin misclassifying ".htaccess" as a
 * directory; a plain "test -s" can succeed for a nonempty directory.
 */
import { existsSync, lstatSync, readFileSync } from 'node:fs';

const built = new URL('../../public/assets/admin/', import.meta.url);
const files = ['app.css', 'app.js', 'theme-init.js', 'icons.svg', '.htaccess'];
for (const name of files) {
  const file = new URL(name, built);
  if (!existsSync(file) || !lstatSync(file).isFile()) {
    throw new Error(`Expected a regular built file: public/assets/admin/${name}`);
  }
  if (lstatSync(file).size === 0) {
    throw new Error(`Empty built asset: public/assets/admin/${name}`);
  }
}

const expected = readFileSync(new URL('../src/cache.htaccess', import.meta.url));
const actual = readFileSync(new URL('.htaccess', built));
if (!expected.equals(actual)) {
  throw new Error('Admin cache rules differ from the build source');
}
if (existsSync(new URL('.htaccess/cache.htaccess', built))) {
  throw new Error('Invalid nested .htaccess/cache.htaccess output');
}
if (existsSync(new URL('app.js.LICENSE.txt', built))) {
  throw new Error('Unexpected license sidecar: copyright must remain bundled');
}
console.log('PASS exact admin asset allowlist and .htaccess build output');
