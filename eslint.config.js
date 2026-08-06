import js from "@eslint/js";

export default [
  {
    ignores: ["vendor/**", "node_modules/**"],
  },
  {
    ...js.configs.recommended,
    files: ["modules/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "module",
      globals: {
        document: "readonly",
        window: "readonly",
        console: "readonly",
        setTimeout: "readonly",
        // BGA's client-side translation helper, injected as a page global by BGA's own
        // runtime -- never imported, same as bga-framework.d.ts declares it for tsc.
        _: "readonly",
      },
    },
  },
  {
    ...js.configs.recommended,
    files: ["tests/js/**/*.js"],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: "module",
      globals: {
        // A jsdom-based test helper that assigns globalThis.document itself (see the
        // starter guide / reference doc for the pattern) makes these read as real globals
        // from the test files' point of view, not imports.
        document: "readonly",
        window: "readonly",
        console: "readonly",
      },
    },
  },
];
