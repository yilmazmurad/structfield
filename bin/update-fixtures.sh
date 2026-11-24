#!/usr/bin/env bash
# Re-downloads the httpwg/structured-field-tests JSON suite into tests/fixtures.
set -euo pipefail
cd "$(dirname "$0")/.."
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
git clone --quiet --depth 1 https://github.com/httpwg/structured-field-tests "$tmp/sft"
rm -rf tests/fixtures/*.json tests/fixtures/serialisation-tests
cp "$tmp"/sft/*.json tests/fixtures/
cp -r "$tmp/sft/serialisation-tests" tests/fixtures/
cp "$tmp/sft/LICENSE.md" tests/fixtures/LICENSE-structured-field-tests.md
echo "Updated fixtures to commit $(git -C "$tmp/sft" rev-parse HEAD)"
