#!/usr/bin/env bash

set -euo pipefail

requirements=$(jq -r '.require | to_entries[] | select(.key != "php" and (.key | startswith("ext-") | not)) | "\(.key)\t\(.value)"' composer.json)
fail=0

while IFS=$'\t' read -r name constraint; do
    if [[ ! "$constraint" =~ ^\^([0-9]+)\.([0-9]+)(\.([0-9]+))?$ ]]; then
        echo "::error::${name} has no single caret floor: ${constraint}"; fail=1; continue
    fi
    floor="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[4]:-0}"
    installed=$(composer show "$name" --format=json | jq -r '.versions[0] | ltrimstr("v")')
    if [[ "$installed" != "$floor" ]]; then
        echo "::error::${name} resolved ${installed}, not its declared floor ${floor}"; fail=1
    fi
done <<< "$requirements"

exit "$fail"
