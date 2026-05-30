// Ambient declaration for Vite CSS side-effect imports (e.g. `import
// './header.css'` in header.ts). Vite extracts these into a sibling
// stylesheet at build time; this keeps svelte-check happy about the
// otherwise-untyped module specifier.
declare module '*.css';
