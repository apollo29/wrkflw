#!/usr/bin/env bash
#
# Hilfsmittel rund um Versionen und CHANGELOG.md. Wird von den Workflows
# `auto-release.yml` und `release.yml` benutzt — und laesst sich von Hand
# aufrufen, um zu sehen, was die CI tun wuerde.
#
#   version.sh notes <version>    Der CHANGELOG-Abschnitt dieser Version
#   version.sh aus-changelog      Oberste benannte Version im CHANGELOG (ohne v)
#   version.sh naechste <bump>    Letzter v-Tag + bump (major|minor|patch)
#   version.sh unreleased-leer    Exit 0, wenn [Unreleased] keinen Inhalt hat
#
# Warum ein Skript und nicht alles im YAML: so laesst es sich ausprobieren,
# ohne einen Lauf anzustossen, und beide Workflows lesen denselben Abschnitt
# auf dieselbe Weise. Zwei awk-Ausdruecke an zwei Stellen waeren irgendwann
# zwei verschiedene.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHANGELOG="$ROOT/CHANGELOG.md"

# Der Abschnitt einer Version: alles zwischen ihrer Ueberschrift und der
# naechsten `## [`-Ueberschrift.
notes() {
  awk -v ver="$1" '
    $0 ~ "^## \\[" ver "\\]" { flag = 1; next }
    /^## \[/ { if (flag) exit }
    flag { print }
  ' "$CHANGELOG"
}

# Die oberste Ueberschrift, die eine echte Version traegt — [Unreleased]
# zaehlt nicht.
aus_changelog() {
  grep -m1 -oP '^## \[\K[0-9]+\.[0-9]+\.[0-9]+(?=\])' "$CHANGELOG"
}

# Hat [Unreleased] Inhalt? Leerzeilen zaehlen nicht.
unreleased_leer() {
  local inhalt
  inhalt="$(notes 'Unreleased' | tr -d '[:space:]')"
  [ -z "$inhalt" ]
}

naechste() {
  local bump="$1" letzter major minor patch
  letzter="$(git -C "$ROOT" tag --list 'v[0-9]*' --sort=-v:refname | head -n1)"
  letzter="${letzter#v}"
  if [ -z "$letzter" ]; then
    # Noch nie getaggt: bei 0.1.0 anfangen statt bei 0.0.1 — ein Paket, das
    # es schon gibt, ist mehr als ein Patch auf nichts.
    echo "0.1.0"
    return
  fi

  IFS='.' read -r major minor patch <<<"$letzter"
  case "$bump" in
    major) echo "$((major + 1)).0.0" ;;
    minor) echo "${major}.$((minor + 1)).0" ;;
    patch) echo "${major}.${minor}.$((patch + 1))" ;;
    *) echo "Unbekannter Bump: $bump (major|minor|patch)" >&2; exit 2 ;;
  esac
}

case "${1:-}" in
  notes)          notes "${2:?Version fehlt}" ;;
  aus-changelog)  aus_changelog ;;
  naechste)       naechste "${2:?Bump fehlt}" ;;
  unreleased-leer) unreleased_leer ;;
  *) sed -n '3,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
