// Declaration emit cannot name the type `axios.request` infers from axios 1.19 onwards, so `tsc` refuses the
// generated `common.ts` with TS2527. The generator's own template has no annotation to give it, and vendoring the
// template to add one keeps a copy that quietly goes stale on a generator bump; this states the annotation instead
// and fails when the text it expects is gone, which is the point at which the patch is either wrong or no longer
// needed.
import { readFile, writeFile } from 'node:fs/promises';

const patches = [
  {
    file: 'generated/common.ts',
    from: 'basePath: string = BASE_PATH) => {',
    to: 'basePath: string = BASE_PATH): Promise<R> => {',
  },
  {
    file: 'generated/common.ts',
    from: 'return axios.request<T, R>(axiosRequestArgs);',
    to: 'return axios.request<T, R>(axiosRequestArgs) as Promise<R>;',
  },
];

// The generator owns index.ts, so the contract version is re-exported into it here rather than kept in a second
// entry point the generator would not know about.
const reexport = 'export * from "./contract";\n';

for (const { file, from, to } of patches) {
  const path = new URL(`../${file}`, import.meta.url);
  const source = await readFile(path, 'utf8');

  if (!source.includes(from)) {
    throw new Error(`${file} no longer contains ${JSON.stringify(from)}; re-check the patch against the generator.`);
  }

  await writeFile(path, source.replaceAll(from, to));
}

const index = new URL('../generated/index.ts', import.meta.url);
const source = await readFile(index, 'utf8');

if (!source.includes(reexport)) {
  await writeFile(index, source.endsWith('\n') ? source + reexport : `${source}\n${reexport}`);
}
