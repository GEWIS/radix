// One comparison, imported by everything that needs it, because two copies of a version comparison drift the
// moment one of them learns about a suffix the other does not.
const parts = (version) => version.split('.').map(Number);

export const isVersion = (value) => typeof value === 'string' && /^\d+\.\d+\.\d+$/.test(value);

/** Negative when `a` is older than `b`, positive when it is newer, zero when they are the same release. */
export const compare = (a, b) => {
  const left = parts(a);
  const right = parts(b);

  for (let i = 0; i < 3; i += 1) {
    if (left[i] !== right[i]) {
      return left[i] - right[i];
    }
  }

  return 0;
};

// A bound that is present but unreadable is refused, never filtered away. Filtering is what turns `v5.0.0`, or a
// two-part `5.1` that YAML hands over as the number 5.1, into "no bound at all", which is the one outcome the
// checks below exist to prevent.
const bound = (operation, key) => {
  const value = operation?.[key];

  if (value === undefined) {
    return null;
  }

  if (!isVersion(value)) {
    throw new Error(`${operation.operationId ?? 'an operation'} publishes ${key} as ${JSON.stringify(value)}, which is not a version.`);
  }

  return value;
};

/** The bounds each operation of a document publishes, as `{ minimum, maximum }` pairs. */
export const bounds = (document) =>
  Object.values(document?.paths ?? {}).flatMap((item) =>
    Object.values(item ?? {})
      .map((operation) => ({
        operation,
        minimum: bound(operation, 'x-gewis-version-minimum'),
        maximum: bound(operation, 'x-gewis-version-maximum'),
      }))
      .filter(({ minimum }) => minimum !== null),
  );

/**
 * The version this client states: the newest any operation requires, so every one of them answers it.
 *
 * An operation capped below that would refuse the value, which means the document describes two shapes at once and
 * no single version satisfies it. That is a contract that cannot be spoken by one client, so it is refused here
 * rather than published as a constant that quietly 406s.
 */
export const contract = (document) => {
  const declared = bounds(document);

  if (declared.length === 0) {
    throw new Error('no operation publishes an x-gewis-version-minimum to take the contract from.');
  }

  const newest = declared.reduce((highest, { minimum }) => (compare(minimum, highest) > 0 ? minimum : highest), declared[0].minimum);
  const capped = declared.find(({ maximum }) => maximum !== null && compare(maximum, newest) < 0);

  if (capped !== undefined) {
    throw new Error(
      `the contract would be ${newest}, but an operation answers only up to ${capped.maximum}; no one version speaks to every endpoint here.`,
    );
  }

  return newest;
};
