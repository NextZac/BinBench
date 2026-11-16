# BinBench architecture

How a file moves through BinBench: upload, identification, map finding, editing, checksums, export.
Keep this file current when a stage changes.

## Stack

- **Backend:** Laravel 13 on PHP 8.4. All ECU logic is plain PHP under `app/Ecu` with no framework
  dependencies, so it can be unit-tested without a database.
- **Frontend:** Vue 3 + Pinia + Vue Router, built with Vite, styled with Tailwind 4, charts with Plotly.
- **Database:** SQLite by default (`DB_CONNECTION`), sessions and queue tables in the database.
- **Storage:** dumps and saved versions as files on the `local` disk (`storage/app/private/bins`).
- **Auth:** session login for the SPA (`AuthController`), files private per user through policies.

## The pipeline

```
upload ──► Analyzer::identify ──► family->findMaps ──► family->checksum ──► EcuFile (DB row + .bin)
                                                                              │
browser editor (edits a copy in memory) ◄── GET /files/{id} + /raw ───────────┘
        │
        ├─► POST /dyno, /stage1, /diff      read-only analysis of the working buffer
        └─► POST /versions                  ExportGate: verify, correct, diff, store version
                                              └─► GET /versions/{id}/download
```

### 1. Upload and storage

`EcuFileController::store` accepts up to 8 MB, stores the bytes under a random name and creates an
`EcuFile` row owned by the user. The original file is never modified afterwards; every edit becomes
an `EcuFileVersion` with its own file.

### 2. Identification

`App\Ecu\Analyzer` holds a list of `EcuFamily` implementations and asks each one to `detect()`.
The highest score wins; `GenericFamily` (score 1) is the fallback for unknown files.

| Family | Class | Detects by | CPU / byte order |
|---|---|---|---|
| ME7.x | `Families\Me7Family` | ME7 ident string, 0261 HW number, C167 structure marks | C167, little-endian |
| EDC15 | `Families\Edc15Family` | size, BMW SW number position, `EDC` engine string | C167, little-endian |
| EDC16 | `Families\Edc16Family` | the text `EDC16…` in the file | MPC5xx, big-endian |
| EDC17 / MD1 | `Families\Edc17Family` | the text `EDC17…` / `MD1C…` | TriCore, little-endian |
| Unknown | `Families\GenericFamily` | always (score 1) | tries both byte orders |

`IdStrings` extracts HW, SW and part numbers by pattern. There is no notion yet of file kind
(full flash, calibration-only, EEPROM) outside the EDC15 checksum module.

### 3. Map finding

Two finders produce `MapDef` objects (`app/Ecu/Maps/MapDef.php`: address, size, storage type, byte
order, factor/offset, axes, name, category, source, confidence):

- **ME7:** `Families\Me7\LookupScanner` disassembles the C167 code, finds the calls to the map lookup
  routines and reads the table descriptors they pass; `Me7\MapCatalog` names the tables from their
  axis inputs and data shape (KFZW, KFMIRL, LDRXN, KFPED, …).
- **Diesel (EDC15/16/17) and unknown files:** `Maps\AxisScanner` finds inline-axis tables by
  structure (layouts A, B, C, documented in the class), `Maps\DieselClassifier` names them from axis
  and value ranges. These names are guesses with a confidence value.

Imported definitions (`Xdf\XdfImporter` → `MapDefinition` rows, keyed by SW number and owner) take
precedence over found maps at the same address. `EcuFile::analyze()` merges both and stores the
result as JSON on the file row.

### 4. Roles: which map does what

`Maps\MapRoles` assigns maps to roles (driver's wish, torque limiter, smoke limiter, boost target,
…) by name, unit, shape and axis type. Dyno, Stage 1 and the tune review all work on roles, never
on addresses. The user can override any role per request.

### 5. Editing

The browser loads the original bytes and the map list, and edits a copy in memory
(`resources/js/stores/editor.js`, undo stack per byte range). Map decoding and encoding exist twice:
`resources/js/lib/binary.js` for the editor, `Maps\MapValues` / `Maps\MapWriter` on the server.
Server-side analysis endpoints receive the working buffer as the request body and never store it.

### 6. Analysis on the working buffer

- `Dyno\DynoEstimator`: estimated torque/power from the limiting maps.
- `Tuning\Stage1Planner`: rule-based proposal (returns new cell values, the browser applies them).
- `Diff\BinDiff` + `Diff\TuneReview`: map-level diff and heuristic review with a verdict.
- `Tuning\TuningAdvisor`: optional LLM explanation of a proposal summary (never the file).

### 7. Checksums

Each family returns a `Checksum\ChecksumModule` (`check()`, `canFix()`, `fix()`):

| Module | Status |
|---|---|
| `Me7Checksum` | Port of ME7Sum 1.1.3; verify and correct, incl. RSA re-signing (needs `gmp`) |
| `Edc15Checksum` | BMW DDE4 (EDC15C); verify and correct; other EDC15 variants are rejected |
| `Edc16Checksum` | VAG EDC16U only; verify only. EDC16C (BMW/PSA/Opel) has no module |
| `Edc17Checksum` | Block checksums, verify only; RSA and monitoring checksums not covered |

`fix()` must return a file that passes its own `check()` or throw.

### 8. Export

`Export\ExportGate` is the only way a modified buffer becomes a stored, downloadable version
(`POST /files/{file}/versions`). It corrects checksums when possible, verifies the result, computes
the diff against the original on the server and classifies the version:

- `verified`: every checksum block validates after recalculation.
- `unverified`: no checksum module, or blocks that cannot be corrected. Stored only with an
  explicit acknowledgement, with the reason recorded and the download named `…_UNVERIFIED.bin`.

See `docs/checksums.md`.

## Data model

- `users`
- `ecu_files`: one uploaded dump; `ident`, `maps` (JSON), `checksums` (report at upload), owner.
- `ecu_file_versions`: one saved modified file; label, sha1, checksum report, export state,
  server-computed change summary.
- `map_definitions`: imported definition sets per SW number and owner.

## Tests

`tests/Unit` covers the ECU logic with synthetic dumps built by `tests/Support/SyntheticBin`.
`tests/Unit/GoldenFixturesTest` runs every real file listed in `test-fixtures/manifest.json`
(files themselves are not in the repository). `tests/Feature` covers the HTTP API.
