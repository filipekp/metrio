#!/usr/bin/env bash
set -euo pipefail

SCRIPT="$(cd "$(dirname "$0")" && pwd)/generate-release-notes.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cd "$WORK"
git init -q
git config user.name 'Test'
git config user.email 'test@example.com'

git commit --allow-empty -qm 'Initial deployment'
git tag v0.1.5

git commit --allow-empty -qm 'fix: Oprava chyby migrací' -m $'Dříve provedené migrace se přeskočí.\nNové migrace se stále aplikují.'
git commit --allow-empty -qm 'feat: Grafy spotřeby' -m 'Doplněny grafy pro jednotlivé byty a druhy energií.'

GITHUB_REPOSITORY=filipekp/metrio bash "$SCRIPT" v0.1.6 HEAD "$WORK/notes.md"
grep -Fq '## Změny od v0.1.5' "$WORK/notes.md"
grep -Fq 'fix: Oprava chyby migrací' "$WORK/notes.md"
grep -Fq 'Dříve provedené migrace se přeskočí.' "$WORK/notes.md"
grep -Fq 'Nové migrace se stále aplikují.' "$WORK/notes.md"
grep -Fq 'feat: Grafy spotřeby' "$WORK/notes.md"
grep -Fq 'Doplněny grafy pro jednotlivé byty' "$WORK/notes.md"
grep -Fq '/compare/v0.1.5...v0.1.6' "$WORK/notes.md"
if grep -Fq 'Initial deployment' "$WORK/notes.md"; then
  echo 'Chyba: release notes zahrnují změny před předchozím releasem.' >&2
  exit 1
fi

# Když je nový tag již založený na HEAD, nesmí být chybně použit jako baseline.
git tag v0.1.6
GITHUB_REPOSITORY=filipekp/metrio bash "$SCRIPT" v0.1.6 HEAD "$WORK/tag-notes.md"
grep -Fq '## Změny od v0.1.5' "$WORK/tag-notes.md"
grep -Fq 'feat: Grafy spotřeby' "$WORK/tag-notes.md"

echo 'OK: release notes obsahují jen commity od předchozího vydání včetně jejich popisů.'
