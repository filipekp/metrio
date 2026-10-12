#!/usr/bin/env bash
# Release notes vytvářené výhradně z commit zpráv od předchozího stabilního tagu.
# Použití: generate-release-notes.sh vX.Y.Z <commit-ref> <výstupní-md>
set -euo pipefail

if [[ $# -ne 3 ]]; then
  echo 'Použití: generate-release-notes.sh vX.Y.Z <commit-ref> <výstupní-md>' >&2
  exit 64
fi

TAG="$1"
TARGET_REF="$2"
OUTPUT="$3"
REPOSITORY="${GITHUB_REPOSITORY:-}"

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo 'Neplatné označení vydání.' >&2
  exit 1
fi
if [[ ! "$REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
  echo 'Proměnná GITHUB_REPOSITORY musí mít tvar vlastník/repozitář.' >&2
  exit 1
fi

# Pro anotované tagy i obyčejné SHA získáme commit, nikoli objekt tagu.
TARGET_COMMIT="$(git rev-parse --verify "${TARGET_REF}^{commit}")"

# Bereme jen stabilní tagy, které jsou předky nasazovaného commitu.
# Současný TAG u ručního tag push eventu nesmí být vybrán jako předchozí.
PREVIOUS_TAG=''
while IFS= read -r candidate; do
  [[ -n "$candidate" && "$candidate" != "$TAG" ]] || continue
  # U manuálně zadaného staršího tagu nepoužijeme novější verzi.
  if [[ "$(printf '%s\n' "$candidate" "$TAG" | sort -V | head -n 1)" != "$candidate" ]]; then
    continue
  fi
  PREVIOUS_TAG="$candidate"
done < <(git tag --merged "$TARGET_COMMIT" --list 'v*' \
  | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -V || true)

if [[ -n "$PREVIOUS_TAG" ]]; then
  RANGE="${PREVIOUS_TAG}..${TARGET_COMMIT}"
  HEADING="Změny od ${PREVIOUS_TAG}"
else
  RANGE="$TARGET_COMMIT"
  HEADING='Změny od začátku projektu'
fi

mkdir -p "$(dirname "$OUTPUT")"
{
  printf '# Metrio %s\n\n' "$TAG"
  printf '## %s\n\n' "$HEADING"

  COUNT=0
  # git rev-list určuje skutečné commity v intervalu. Každý commit čteme
  # samostatně, abychom zachovali vícerádková těla zpráv beze změn.
  while IFS= read -r COMMIT; do
    [[ -n "$COMMIT" ]] || continue
    SUBJECT="$(git log -1 --format=%s "$COMMIT")"
    BODY="$(git log -1 --format=%b "$COMMIT")"
    SHORT_SHA="${COMMIT:0:7}"
    printf -- '- **%s** ([`%s`](https://github.com/%s/commit/%s))\n' \
      "$SUBJECT" "$SHORT_SHA" "$REPOSITORY" "$COMMIT"
    if [[ -n "${BODY//[[:space:]]/}" ]]; then
      printf '\n'
      while IFS= read -r line || [[ -n "$line" ]]; do
        printf '  %s\n' "$line"
      done <<< "$BODY"
    fi
    printf '\n'
    COUNT=$((COUNT + 1))
  done < <(git rev-list --reverse --no-merges "$RANGE")

  if [[ "$COUNT" -eq 0 ]]; then
    printf 'Od předchozího vydání nejsou v historii žádné nové commity.\n\n'
  fi
  if [[ -n "$PREVIOUS_TAG" ]]; then
    printf '**Kompletní porovnání:** [ %s...%s ](https://github.com/%s/compare/%s...%s)\n' \
      "$PREVIOUS_TAG" "$TAG" "$REPOSITORY" "$PREVIOUS_TAG" "$TAG"
  else
    printf '**Historie commitů:** https://github.com/%s/commits/%s\n' \
      "$REPOSITORY" "$TAG"
  fi
} > "$OUTPUT"

printf 'Vygenerován popis %s (předchozí: %s)\n' "$TAG" "${PREVIOUS_TAG:-žádný}"
