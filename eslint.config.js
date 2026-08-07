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
        // BGA's legacy counter/stock widget namespace, also injected as a page global.
        ebg: "readonly",
      },
    },
    rules: {
      // Framework callbacks (onLeavingState, onPlayerActivationChange, etc.) receive a
      // fixed positional signature even when a given state doesn't need every argument --
      // prefix intentionally-unused params with `_` instead of dropping them, same
      // convention already used for the PHP stubs (see docs/bga-studio-reference.md).
      "no-unused-vars": ["error", { args: "after-used", argsIgnorePattern: "^_" }],
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
