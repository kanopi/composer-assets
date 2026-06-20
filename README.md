# kanopi/composer-assets

A **CMS-agnostic Composer plugin that scaffolds asset files** from dependency
packages into your project. It is a framework-neutral take on
[`drupal/core-composer-scaffold`](https://github.com/drupal/core-composer-scaffold):
same proven configuration shape, but with **no CMS-specific defaults**, so you
can use it for Drupal, WordPress, or any Composer-managed PHP project.

## What it does

During `composer install` / `composer update` (or on demand via
`composer assets`), the plugin reads `extra.composer-assets.file-mapping` from
your project and from any **allowed** dependency, then copies, symlinks, or
appends those files into your project tree.

Typical uses: dropping in `.htaccess`, `index.php`, `robots.txt`, `wp-config`
stubs, settings files, CI configs, or any boilerplate that lives in a reusable
"profile"/"recipe" package.

## Installation

```bash
composer require kanopi/composer-assets
```

Composer will ask you to trust the plugin. To pre-approve it in CI:

```json
{
    "config": {
        "allow-plugins": { "kanopi/composer-assets": true }
    }
}
```

## Configuration

All configuration lives under `extra.composer-assets`:

```json
{
    "extra": {
        "composer-assets": {
            "allowed-packages": ["acme/site-recipe"],
            "symlink": false,
            "gitignore": true,
            "file-mapping": {
                "web/.htaccess": "assets/.htaccess",
                "web/robots.txt": { "path": "assets/robots.txt", "overwrite": false },
                "web/sites/default/settings.php": false,
                "web/.htaccess-extra": {
                    "prepend": "assets/htaccess-header.txt",
                    "append": "assets/htaccess-extra.txt",
                    "default": "assets/htaccess-default.txt",
                    "force-append": true
                }
            }
        }
    }
}
```

### `file-mapping`

The keys are **destination paths relative to your project root** (the directory
containing the root `composer.json`). There is **no `locations`/`web-root`
indirection** — if your docroot is `web/`, just write `web/...`; to target the
project root, write the path directly.

Each value selects an operation:

| Value | Operation |
|-------|-----------|
| `"assets/file"` (string) | **Replace** — copy the source file to the destination. |
| `{ "path": "...", "overwrite": false }` | **Replace**, but don't clobber an existing destination. |
| `{ "path": "...", "symlink": true }` | **Replace** via symlink (overrides the global `symlink`). |
| `{ "append": "...", "prepend": "...", "default": "...", "force-append": true }` | **Append/Prepend** content (byte-level). |
| `{ "merge": "...", "default": "...", "format": "yaml", "array": "replace", "force-merge": true }` | **Merge** structured JSON/YAML. |
| `{ "path": "...", "mode": "0755" }` | Any write op, plus **`chmod`** the result to that mode. |
| `"assets/dir/"` or `"assets/*.yml"` (directory or glob source) | **Replace** every matched file (see below). |
| `false` | **Skip** — cancel a mapping inherited from another package. |

Source paths are resolved **relative to the package that declares them**
(for the root project, relative to the project root).

### Directory and glob mappings

A `replace` mapping (a plain string, or an object with `path`) whose **source is
a directory or a glob pattern** expands into one entry per matched file:

```json
".github/":            "assets/github/",          // directory: recursive, structure preserved
".circleci/":          "assets/circleci/*.yml",    // glob: single level, flattened by basename
"bin/":                { "path": "assets/bin/", "mode": "0755" }
```

- **Directory source** (`assets/github/`) — copies the whole tree recursively,
  **preserving subdirectories** (`assets/github/workflows/ci.yml` →
  `.github/workflows/ci.yml`). The trailing slash is optional. Includes dotfiles.
- **Glob source** (`assets/circleci/*.yml`) — matches a **single level** (no
  `**` recursion) and places each match in the destination directory **by its
  basename**. Like the shell, a leading-dot file is *not* matched by `*`; use a
  directory source to capture dotfiles. A glob that matches nothing warns and is
  skipped.
- The destination key is treated as a **target directory**; each file lands at
  `key + <relative path or basename>`. Use `""` to target the project root.
- Sibling options (`overwrite`, `symlink`, `gitignore`, `mode`) apply to **every**
  expanded file.
- Expansion produces ordinary per-file mappings, so everything downstream is
  unchanged: precedence still wins by concrete destination (a later package — or
  the root with `false` — can override or skip an individual expanded file), and
  drift, `.gitignore` management, and `mode` all work per file.
- Only `replace` expands. `append` / `prepend` / `merge` / `skip` entries are
  always single-file.

**Append/Prepend details**

- If the destination is missing and a `default` source is given, that default is
  written first.
- `prepend` content goes before the body, `append` after.
- By default the target must be a managed (scaffolded) file. Set
  `"force-append": true` to modify a pre-existing project file.
- Append/prepend is **idempotent**: content already present is not duplicated on
  re-runs.

### File permissions (`mode`)

Add `"mode"` to any **write** mapping (`replace`, `append`/`prepend`, or `merge`)
to `chmod` the destination after it is written — handy for executable scripts or
read-only settings files:

```json
"bin/deploy.sh":                  { "path": "assets/deploy.sh", "mode": "0755" },
"web/sites/default/settings.php": { "append": "assets/settings-tail.php", "force-append": true, "mode": "0444" }
```

- The value is an **octal string** (`"0755"`, `"755"`, and `"0o755"` are all
  accepted). An invalid value is an error.
- The mode is applied **each time the file is written** (created, copied,
  appended, or merged). It is not enforced on a run where the file is already in
  sync and nothing is rewritten.
- **Symlinks ignore `mode`** — `chmod` on a symlink follows it to the package
  source, so a mode would be meaningless (and unsafe) there and is skipped.
- The mode is **not** part of drift detection; only file *content* is compared.

### Structured merge (JSON / YAML)

Where `append` is byte-level (great for `.htaccess`, plain text), `merge` parses
the source and destination and **deep-merges the data structures**, so the
result is always valid. Built for config boilerplate — `composer.json` /
`package.json` fragments, CircleCI `.circleci/config.yml`, Tugboat
`.tugboat/config.yml`, `tsconfig.json`, `.eslintrc.json`, etc.

```json
"package.json":            { "merge": "assets/package-fragment.json", "force-merge": true },
".tugboat/config.yml":     { "merge": "assets/tugboat-overlay.yml", "force-merge": true },
".circleci/config.yml":    { "merge": "assets/ci-overlay.yml", "default": "assets/ci-base.yml" }
```

Merge semantics (RFC 7386-flavored):

- **Maps** merge key-by-key; the source wins on scalar conflicts.
- A source value of **`null` deletes** that key from the destination.
- **Arrays** follow the `array` strategy:
  - `replace` *(default)* — source array overwrites the destination array. **Idempotent.**
  - `concat` — destination then source. **Not idempotent** — re-runs grow the list. Use only with care.
  - `unique` — concat then de-duplicate (idempotent for scalar lists).
- **Format** is inferred from the destination extension (`.json`, `.yml`/`.yaml`);
  override with `"format": "json"` / `"yaml"`.
- `default` seeds the destination when it's missing; `force-merge: true` allows
  merging into a pre-existing project file (otherwise the target must already
  be a managed file).

> [!IMPORTANT]
> Re-serialization **discards comments**, and YAML **anchors/aliases are
> expanded inline**. Merge is intended for generated/managed config, not files a
> human hand-curates and comments. For those, prefer plain `replace`.

YAML support requires `symfony/yaml`, which ships as a dependency of this plugin.

### `allowed-packages`

The plugin is **agnostic by default**: no package is implicitly trusted
(unlike Drupal's plugin, which implicitly allows `drupal/core`). Only packages
listed here may contribute files.

- The **root project is always allowed** and is applied **last**, so it can
  override or `false`-out anything a dependency provides.
- Ordering is precedence: later entries win when two packages map the same
  destination.
- **Delegation**: an allowed package may declare its own `allowed-packages`,
  which are pulled in transitively (and ordered *before* the delegating package,
  so the delegator can still override them).

### `symlink`

`true` symlinks replace-mode files instead of copying them — handy in
development so edits flow back to the source. Defaults to `false`. Can be
overridden per file with `"symlink": false`/`true` on a `path` mapping.

### `gitignore`

Controls whether generated (scaffolded) files are added to the nearest
`.gitignore`:

- `true` — always manage `.gitignore`.
- `false` — never touch `.gitignore`.
- *unset* (default) — **auto**: manage only when the project is a git repo that
  already ignores its `vendor` directory.

Files modified via `force-append` / `force-merge` are **not** gitignored — they
are tracked project files, not generated artifacts.

#### Keeping a generated file tracked (`gitignore: false`)

Some scaffolded files **must be committed** to work — e.g. `.circleci/config.yml`
and `.github/workflows/*.yml`, since CI only runs when the config is in the
repository. Add `"gitignore": false` to an individual mapping to scaffold the
file but keep it out of `.gitignore` management:

```json
".circleci/config.yml": { "path": "assets/config.yml", "gitignore": false },
"web/.htaccess":        "assets/htaccess"
```

Here `.circleci/config.yml` is copied **and stays tracked**, while `web/.htaccess`
is still ignored. The flag works on `replace`, `merge`, and `append` mappings;
`"gitignore": true` conversely forces a file into management. (The plugin only
*adds* `.gitignore` entries — it won't remove one a previous run already wrote.)

## Running it

It runs automatically after `composer install` and `composer update`. To run on
demand:

```bash
composer assets
```

To preview a run without changing anything, pass `--dry-run`: every operation is
reported (`Would copy`, `Would append/prepend`, `Would merge …`) but no files are
written, `.gitignore` is left untouched, and the `post-composer-assets-cmd` script
is not dispatched.

```bash
composer assets --dry-run
```

After scaffolding completes, the plugin dispatches a **`post-composer-assets-cmd`**
script event so you can chain follow-up steps:

```json
{
    "scripts": {
        "post-composer-assets-cmd": ["@php scripts/fix-permissions.php"]
    }
}
```

## Drift detection

Files the **project owns** are the ones that silently rot: once you keep a local
copy (`"overwrite": false`) or merge a fragment into a tracked file
(`force-append` / `force-merge`), later updates to the source in the package
never reach it. Drift detection flags exactly that — when a destination on disk
no longer matches what its providing package would produce.

```bash
composer assets:check
```

This is **read-only** — it writes nothing. For each owned file it prints a
unified diff (`-` is what's on disk, `+` is what the package would produce):

```
composer-assets: 1 file(s) have drifted from their package source:

web/robots.txt
@@ -1,2 +1,2 @@
 User-agent: *
-Disallow: /admin
+Disallow: /private
```

In a color terminal the diff is highlighted (red for the on-disk lines, green
for what the package would produce); piped or `--no-ansi` output stays plain.

To check only specific files, pass their (project-relative) paths — the same
keys used in `file-mapping`:

```bash
composer assets:check web/robots.txt web/.htaccess
```

A path that isn't a managed file is reported and skipped.

It also runs automatically after `composer install` / `composer update`, where
it is **warn-only** — drifted files are reported but the run never fails:

```
composer-assets: web/robots.txt has drifted from its package source (run "composer assets:check" for the diff).
```

### Resolving drift (`assets:reapply`)

Where `assets:check` only reports drift, `assets:reapply` **resolves** it by
overwriting each drifted owned file with the content its package would produce:

```bash
composer assets:reapply
```

It shows the same diff as `assets:check` and then **asks before writing each
file** (default is *no*). Pass paths to limit it to specific files, and `--yes`
(`-y`) to accept every change without prompting — e.g. in a scripted update:

```bash
composer assets:reapply web/robots.txt        # one file, with confirmation
composer assets:reapply --yes                 # all drifted files, no prompts
```

> [!WARNING]
> This **overwrites local edits** to owned files (`"overwrite": false` copies and
> `force-append` / `force-merge` targets) with the package source. For a
> `force-append` target the package content is re-applied additively, so a stale
> older fragment may remain.

Only files that have **drifted** are touched. A file that is merely *missing* is
not created here (drift detection treats a missing destination as a "would
create") — run `composer assets` to scaffold those. To permanently exempt a file
you intentionally diverge, use `"drift": false` (below), which also hides it from
`assets:reapply`.

### What is (and isn't) checked

- **Checked:** `replace` with `"overwrite": false`, and `force-append` /
  `force-merge` targets — the files the project owns.
- **Not checked:** plain `replace` (overwrite:true) and symlinks (re-synced
  every run, so they can't drift), `skip`, and `merge` with `"array": "concat"`
  (not idempotent — a re-merge always differs, so drift can't be told apart from
  normal operation).
- A **missing** destination is reported as a "would create", not as drift.
- `force-append` drift means *a run would add/change content*; because append is
  additive it surfaces the divergence but can't remove a stale older fragment.

### Failing the build (`fail-on-drift`)

`composer assets:check` is warn-only by default (exit `0`). To make drift a hard
failure — e.g. a CI guard that breaks the build when an owned file falls behind
its package — set:

```json
{
    "extra": {
        "composer-assets": {
            "fail-on-drift": true
        }
    }
}
```

With this set, `composer assets:check` exits `1` when any file has drifted. The
`--strict` flag forces the same behavior for a single run without changing
config (`composer assets:check --strict`). Normal install/update runs stay
warn-only regardless, so scaffolding never blocks a dependency install.

### Silencing a file (`"drift": false`)

To opt an individual owned file out of drift reporting — say a file you
intentionally diverge from upstream — add `"drift": false` to its mapping
(mirrors the `"gitignore"` override):

```json
"web/robots.txt": { "path": "assets/robots.txt", "overwrite": false, "drift": false }
```

## How it compares to drupal/core-composer-scaffold

| | drupal/core-composer-scaffold | kanopi/composer-assets |
|---|---|---|
| Config key | `extra.drupal-scaffold` | `extra.composer-assets` |
| Implicit allowed package | `drupal/core` | none (agnostic) |
| Web root | `locations.web-root` token (`[web-root]`) | plain project-relative paths |
| Command | `composer drupal:scaffold` | `composer assets` |
| Post hook | `post-drupal-scaffold-cmd` | `post-composer-assets-cmd` |
| Replace / append / skip | ✅ | ✅ |
| Structured JSON/YAML merge | ❌ | ✅ |
| Drift detection (`assets:check`) | ❌ | ✅ |
| Drift resolution (`assets:reapply`) | ❌ | ✅ |
| Dry-run preview (`assets --dry-run`) | ❌ | ✅ |
| Per-file permissions (`mode`) | ❌ | ✅ |
| Directory / glob mappings | ❌ | ✅ |
| Symlink mode | ✅ | ✅ |
| `.gitignore` management | ✅ | ✅ |
| Allowed-packages + delegation | ✅ | ✅ |
| `autoload.php` generation | ✅ (Drupal-specific) | ❌ (out of scope) |

## Development

```bash
composer install
composer test              # full suite (unit + integration)
composer test:unit         # fast unit tests only
composer test:integration  # real `composer install` against fixture packages
```

The **integration test** (`tests/integration/`) builds a throwaway project that
requires this plugin plus the `tests/fixtures/provider` package via local path
repositories, runs an actual `composer install`, and asserts the scaffolded
files land correctly — the regression guard for the plugin's event wiring,
allowed-packages resolution, and operation dispatch. It self-skips if the
`composer` binary isn't on `PATH`.

## License

MIT
