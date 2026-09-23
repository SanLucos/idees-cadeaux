// jeep-sqlite (the web implementation of @capacitor-community/sqlite)
// loads sql.js's WebAssembly from /assets: copy it there after install.
import { copyFileSync, mkdirSync } from 'node:fs';

mkdirSync('public/assets', { recursive: true });
copyFileSync('node_modules/sql.js/dist/sql-wasm.wasm', 'public/assets/sql-wasm.wasm');
