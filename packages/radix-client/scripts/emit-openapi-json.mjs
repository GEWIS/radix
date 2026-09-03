// The document is committed as YAML because that is what reviews a contract change readably. Shipping the JSON of
// it as well saves every consumer of this package a YAML parser to read the spec it was built from.
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { parse } from 'yaml';

const document = parse(await readFile(new URL('../../../openapi.yaml', import.meta.url), 'utf8'));

await mkdir(new URL('../dist/', import.meta.url), { recursive: true });
await writeFile(new URL('../dist/openapi.json', import.meta.url), `${JSON.stringify(document, null, 2)}\n`);
