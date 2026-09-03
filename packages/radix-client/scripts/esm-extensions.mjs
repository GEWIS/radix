// tsc emits the extensionless specifiers the generated sources are written with, which Node's ESM resolver
// refuses. Bundlers paper over it; `node --import` does not, so the extensions are added back here and the
// directory is marked as modules.
import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

// `fileURLToPath` rather than `.pathname`, which stays percent-encoded and turns a checkout under a path with a
// space in it into an ENOENT naming a directory that plainly exists.
const dir = fileURLToPath(new URL('../dist/esm/', import.meta.url));
// The declarations as well as the code: under node16 resolution a `.d.ts` importing `'./api'` is as unresolvable
// as the `.js` doing the same, and that is the half a runtime smoke test cannot see.
const files = (await readdir(dir)).filter((file) => file.endsWith('.js') || file.endsWith('.d.ts'));

for (const file of files) {
  const path = join(dir, file);
  const source = await readFile(path, 'utf8');
  const rewritten = source.replace(
    /(\bfrom\s+['"])(\.\.?\/[^'"]+?)(['"])/g,
    (match, before, specifier, after) => (specifier.endsWith('.js') ? match : `${before}${specifier}.js${after}`),
  );

  if (rewritten !== source) {
    await writeFile(path, rewritten);
  }
}

await writeFile(join(dir, 'package.json'), `${JSON.stringify({ type: 'module' }, null, 2)}\n`);
