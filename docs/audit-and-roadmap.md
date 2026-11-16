# Audit and roadmap

Audit of the repository at commit `eadc1e8` (November 2026), followed by a scored roadmap.
The architecture itself is described in `docs/architecture.md`.

## Top 10 risks

Ranked by how badly they can hurt: a bricked ECU or a damaged engine first, then security, then
debt that blocks new platforms.

| # | Risk | Where | Consequence |
|---|---|---|---|
| 1 | **Export without a checksum gate.** A version could be saved and downloaded with invalid checksums (correction unticked), or with no checksum module at all (EDC16C35 BMW, unknown files). Only the file name said `_NOCHK`. | `EcuFileController::saveVersion` | ECU that does not start after flashing. **Fixed in increment 1.** |
| 2 | **Checksum code has no regression tests.** `Me7Checksum` (1,100 lines), `Edc15Checksum`, `Edc16Checksum`, `Edc17Checksum` were verified by hand against real files, but nothing in the repo proves it stays true. | `tests/` | A refactor silently breaks correction. **Harness added in increment 1; needs your files.** |
| 3 | **Guessed map names drive automatic edits.** On files without a definition, Stage 1 acts on names the classifier guessed from value ranges. The EDC16C35 work found EGR hysteresis curves and ×1.0 factor tables in the torque-limiter group. | `DieselClassifier` → `MapRoles` → `Stage1Planner` | Wrong maps raised: engine damage or a file that behaves unpredictably. |
| 4 | **EDC16C and EDC17 cannot be corrected, EDC16C not even checked.** `Edc16Checksum` only knows VAG EDC16U and is unverified; `Edc17Checksum` skips RSA and monitoring checksums. | `app/Ecu/Checksum` | Every BMW/PSA EDC16 and every EDC17 export depends on the flash tool. |
| 5 | **The audit trail is supplied by the browser.** `changed_maps` on a version is whatever the client sent; the server never diffed the bytes. | `saveVersion` | The record of what a customer file contains cannot be trusted. **Fixed in increment 1.** |
| 6 | **ME7 RSA re-signing is not reproducible.** Like ME7Sum, it generates a new random key and replaces the modulus, so two corrections of the same file differ in ~256 bytes. | `Me7Checksum::rsaSign` | Byte diffs that cannot be explained or reproduced; clashes with "never silently change bytes". |
| 7 | **Map definitions from the client are stored unvalidated.** `PATCH /files/{id}` accepts any `maps` array: no bounds on address, rows, cols or type. | `EcuFileController::update` | Out-of-range reads, memory exhaustion (rows × cols), corrupt definitions feeding Stage 1. **Fixed in increment 2.** |
| 8 | **Identification is a text search.** EDC16/EDC17 are detected by the string `EDC16`/`EDC17` anywhere in the file. No file kind (full, calibration-only, EEPROM), no calibration ID, no size/layout check. | `Edc16Family`, `Edc17Family`, `IdStrings` | Wrong family, wrong byte order, wrong checksum module on a look-alike file. |
| 9 | **Files at rest are plain and unbacked.** Customer dumps sit unencrypted on the local disk, the stored SHA-1 is never re-checked on read, and there is no backup job. | `Storage::disk('local')` | Data loss or leak of customer files; silent corruption of a stock read. |
| 10 | **Everything runs inside the web request.** Analysis, map scan and checksum runs are synchronous, and every API call loads the full `maps` JSON (2 MB for a 3,000-map XDF). | `EcuFile::analyze`, controllers | Timeouts on 4–8 MB EDC17/MED17 files; blocks the newer platforms. |

Smaller findings: `APP_DEBUG=true` in `.env.example` (fixed in increment 2); no roles (every user is equal) and no password
reset; versions always diff against stock, with no parent chain (stock → v1 → v2); 8 MB upload cap
is below some MED17/MD1 flash sizes.

## Duplicated logic to generalize

1. **Map decode/encode exists twice**: `resources/js/lib/binary.js` and `Maps\MapValues` +
   `Maps\MapWriter`. Rounding differs for negative halves (JS `Math.round(-2.5)` is −2, PHP
   `round(-2.5)` is −3). One spec with shared test vectors, or encode only on the server.
2. **Names are the interface between classifier and roles.** `DieselClassifier` and `Me7\MapCatalog`
   produce names; `MapRoles` re-parses them with regular expressions. A `role` (and `quantity`)
   field on `MapDef`, set by the finder or by the user, removes the string coupling.
3. **Unit handling is scattered**: pressure conversion in `DynoEstimator`, `TuneReview` and
   `Stage1Planner`; the fuel-axis pattern in `InjectionCheck`, `MapRoles`, `DynoEstimator`. One
   `Units` helper.
4. **Per-family identification** repeats the same steps (size check, ident strings, score). A
   declarative signature per family (sizes, byte patterns at offsets, ident regexes) would replace
   four hand-written `detect()` methods.
5. **Checksum primitives** (16/32-bit sums, CRC32 tables, block walking, "verify after fix") are
   re-implemented in each module. A small shared toolbox keeps new modules short and testable.
6. **Request plumbing** for "working buffer + engine data + role overrides" is right in
   `DynoRequest` but the three controllers each rebuild the map list from JSON.

## Roadmap

Score = (career value × reliability gain) ÷ effort, each 1–5. Higher is better.

| # | Item | Career | Reliab. | Effort | Score | Notes |
|---|---|---|---|---|---|---|
| 1 | **Export gate, server-side change record, golden-fixture harness** | 5 | 5 | 2 | **12.5** | Increment 1 (done). Risks 1, 2, 5. |
| 2 | **Validate stored map definitions; debug off; audit log table** | 3 | 4 | 1 | **12.0** | Increment 2 (done). Risk 7. |
| 3 | **Identification v2**: declarative signatures, file kind (full / calibration / EEPROM), calibration ID, explicit unknown path | 4 | 4 | 2 | **8.0** | Risk 8. Prerequisite for every new platform. |
| 4 | **`role` field on maps + confidence gate for Stage 1** (no automatic edits on guessed names without confirmation) | 3 | 5 | 2 | **7.5** | Risk 3. |
| 5 | **EDC16C (BMW) checksum module**, verify then correct | 5 | 5 | 4 | **6.3** | Risk 4. Needs stock + corrected pairs from you. |
| 6 | **Sanity rules as an export step** (extend `TuneReview`: rail ceiling vs. duration axes, lambda/smoke vs. torque request, ignition bounds), severities shown in the gate | 3 | 4 | 2 | **6.0** | Builds on the review that exists. |
| 7 | **Projects: customer → vehicle → stock read → v1 → v2**, parent chain, notes | 5 | 3 | 3 | **5.0** | Business backbone. |
| 8 | **Log import and overlay** (ME7Logger CSV first, then diesel formats), cell hit counts, active limiter | 5 | 3 | 3 | **5.0** | Also validates the dyno estimate. |
| 9 | **Emissions-related change labelling**: flag changed maps in emission categories on the version record, "motorsport/off-road only" notice, kept in the audit log | 3 | 3 | 2 | **4.5** | Labelling and record only. |
| 10 | **Family module contract v2**: memory layout, file kinds, checksum engines, definitions, patches declared per module; registry instead of a hard-coded list | 4 | 3 | 3 | **4.0** | Most of it exists as `EcuFamily`. |
| 11 | **Operations**: queued analysis, encryption at rest, backups, roles (owner / tuner / reseller), password reset | 3 | 4 | 3 | **4.0** | Risks 9, 10. |
| 12 | **Definition database**: versioned and diffable per SW, signature matching to carry definitions to another SW version | 5 | 3 | 4 | **3.8** | |
| 13 | **Import A2L and DAMOS; KP later** | 5 | 3 | 4 | **3.8** | KP is a closed WinOLS format: scope it separately. |
| 14 | **EDC17 / MED17 module** (identification, layout, block checksums, RSA handling policy) | 5 | 3 | 5 | **3.0** | After 3 and 10; needs files. |
| 15 | **Master/slave file workflow and reseller accounts** | 4 | 2 | 3 | **2.7** | After 7 and 11. |
| 16 | **Hitachi M32R (Subaru) and Mercedes EDC15C0 modules** | 4 | 3 | 5 | **2.4** | After 3 and 10; needs files. |
| 17 | **Firmware patch library**: hook signatures, free-space finder, relocation, verification | 5 | 2 | 5 | **2.0** | Highest brick risk. Only after fixtures, the gate and file kinds are solid. |

Order of work: 1 → 2 → 3 → 4, then 5 and 6 while collecting fixture files, then 7 and 8.

## Open questions for the owner

1. **Hosting and production database**: where does binbench.itsnot.us run, and is production on
   SQLite? This decides backups, encryption and the queue (item 11).
2. **Gate policy for files BinBench cannot verify** (EDC16C, EDC17, unknown): increment 1 allows the
   export only with an explicit acknowledgement that is recorded. Should some families be blocked
   outright instead?
3. **ME7 RSA re-signing** (risk 6): keep ME7Sum's random key, or derive the key from the file so
   the output is reproducible?
4. **Fixture files**: which stock and corrected pairs can go into `test-fixtures/` (they stay out
   of git)? One pair per supported SW family is the minimum.
