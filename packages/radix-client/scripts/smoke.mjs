// Both entry points are loaded the way a consumer loads them, because the failure this catches is invisible to
// `tsc`: an ESM build whose relative imports carry no extension type-checks and bundles, and then throws the first
// time Node resolves it itself.
import { createRequire } from 'node:module';

import { bounds, compare, isVersion } from './version.mjs';

const require = createRequire(import.meta.url);

const spec = require('../dist/openapi.json');
const cjs = require('../dist/index.js');
const esm = await import('../dist/esm/index.js');

const declared = bounds(spec);

for (const [name, module] of [
  ['CommonJS', cjs],
  ['ESM', esm],
]) {
  if (typeof module.Configuration !== 'function' || typeof module.ActivityApi !== 'function') {
    throw new Error(`the ${name} build does not export the client`);
  }

  const stated = module.RADIX_API_VERSION;

  if (!isVersion(stated)) {
    throw new Error(`the ${name} build states ${stated} as the contract, which is not a version`);
  }

  // What the constant is for: one value that every endpoint shipped here accepts, both bounds considered.
  for (const { minimum, maximum } of declared) {
    if (compare(stated, minimum) < 0) {
      throw new Error(`the ${name} build states ${stated}, but an endpoint here requires ${minimum}`);
    }

    if (maximum !== null && compare(stated, maximum) > 0) {
      throw new Error(`the ${name} build states ${stated}, but an endpoint here answers only up to ${maximum}`);
    }
  }
}
