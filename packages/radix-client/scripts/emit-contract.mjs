// The contract a consumer states is the newest version any endpoint in the document it was generated from
// requires, which is the one value every endpoint in that document answers. It is read from the bounds each
// operation publishes rather than from `info.version`, which names the release a deployment runs and says nothing
// about what a consumer has to send.
import { readFile, writeFile } from 'node:fs/promises';
import { parse } from 'yaml';

import { contract } from './version.mjs';

const document = parse(await readFile(new URL('../../../openapi.yaml', import.meta.url), 'utf8'));

await writeFile(
  new URL('../generated/contract.ts', import.meta.url),
  `/* tslint:disable */
/* eslint-disable */
/**
 * The contract this client speaks: the newest version any endpoint it carries requires, so every one of them
 * answers it. Endpoints added since the contract was versioned need it, as \`X-Api-Version\` or as the \`version\`
 * parameter of the vendor \`Accept\` header. Do not write the value out by hand; it moves with the document.
 */
export const RADIX_API_VERSION = '${contract(document)}' as const;
`,
);
