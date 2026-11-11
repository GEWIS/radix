# Frontend assets

- Stimulus controllers live in `assets/controllers/<domain>/` as `*_controller.ts`. **Write new controllers in TypeScript, never plain JS.** The single exception is `assets/controllers/csrf_protection_controller.js`, which is recipe-managed by Symfony Flex; leave it as JS so recipe updates apply cleanly.
- **SWC strips types but does not type-check.** There is no `tsc` or eslint gate, so type errors surface at runtime. Verify changed behaviour in a browser (a real one against the seeded stack, or the browser suite; see `tests/AGENTS.md`).
- No npm. Third-party JS is vendored under `assets/vendor/`.
- Dev watches `assets/` and rebuilds Sass and the asset map; no manual build step.
