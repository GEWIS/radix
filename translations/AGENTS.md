# Translatable strings

`make translations` extracts into `translations/{messages,validators}.{en,nl}.xlf` with the project's expected flags (`--sort=asc --no-fill --force --clean`). Never hand-roll `bin/console translation:extract`. `--clean` removes entries no longer referenced in source, which is safe for `validators` because Symfony falls back to the vendor files.

**Extraction alone is not enough.** `--no-fill` leaves new entries with an empty `<target/>` in *both* locales, and an empty target is used *as* the translation, so the interface renders blank rather than falling back. Find them:

```
grep -rn -e '<target/>' -e '<target></target>' translations/
```

Fill every one: `en` targets get the source text verbatim, `nl` targets your best Dutch. **List the Dutch you wrote in the final report** so a human can review it.

## Three extraction traps

- `t()` and `TranslatableMessage` are extracted by their **literal** argument. An enum that builds a label must put the constructor *inside* each match arm:
  ```php
  return match ($this) {
      self::Chair => new TranslatableMessage('Voorzitter'),
  };
  ```
  Not `new TranslatableMessage(match ($this) { ... })`: the extractor cannot see through that, and `--clean` then deletes every translation for those labels.
- A bare string handed to the form theme for it to translate (a `help:` on a `form_row()` call) is invisible to the extractor, so `--clean` deletes its translation while the template still uses it. Write `help: t('…')`.
- **A validation message has to be written where the extractor can see it.** It reads `t('…')`, `TranslatableMessage`, and the message arguments of a constraint that is written out; it does not read a literal handed to `$context->buildViolation('…')`, a literal passed on to a helper, or the default of a `$message` property on a constraint class. So: hand `buildViolation()` the key as `t('…', [], 'validators')->getMessage()`, give a helper that raises errors a `TranslatableMessage` rather than a string, and put a constraint's messages in the attribute that applies them rather than in a property default. In form types, wrap user-facing labels and `invalid_message` strings with `t()` (the extractor does not recurse into `RepeatedType`'s `first_options` / `second_options`), and don't call `$translator->trans()` at form-build time: it locks the locale before render.
