#!/usr/bin/env bash
# ==============================================================================
# Automated Evidence-Only Diff Validator
# ==============================================================================
# Ensures that any commit or diff between SOURCE_SHA and EVIDENCE_SHA contains
# exclusively approved documentation, release reports, and evidence files.
# Rejects any modifications to executable source code, tests, configuration,
# workflows, or dependency manifests.
# ==============================================================================

set -euo pipefail

SOURCE_SHA="${1:-}"
EVIDENCE_SHA="${2:-HEAD}"

if [[ -z "${SOURCE_SHA}" ]]; then
    echo "Usage: $0 <SOURCE_SHA> [EVIDENCE_SHA]" >&2
    exit 1
fi

echo "Validating evidence-only diff: ${SOURCE_SHA}..${EVIDENCE_SHA}"

# Retrieve all changed files between SOURCE_SHA and EVIDENCE_SHA
CHANGED_FILES=$(git diff "${SOURCE_SHA}".."${EVIDENCE_SHA}" --name-only)

if [[ -z "${CHANGED_FILES}" ]]; then
    echo "No files modified between ${SOURCE_SHA} and ${EVIDENCE_SHA}."
    exit 0
fi

# Whitelist allowed pattern:
# - Markdown files (*.md)
# - Report directories and files (reports/*)
# - Agent memory/sync files (.agents/*)
# Disallowed: src/*, tests/*, config/*, .github/*, composer.*, scripts/*, etc.

DISALLOWED_CHANGES=()

while IFS= read -r file; do
    if [[ -z "${file}" ]]; then
        continue
    fi
    
    # Check if file conforms to allowed evidence patterns
    if [[ "${file}" =~ ^reports/ ]] || [[ "${file}" =~ \.md$ ]] || [[ "${file}" =~ ^\.agents/ ]]; then
        continue
    else
        DISALLOWED_CHANGES+=("${file}")
    fi
done <<< "${CHANGED_FILES}"

if [[ ${#DISALLOWED_CHANGES[@]} -gt 0 ]]; then
    echo "ERROR: Disallowed executable or configuration changes detected between SOURCE_SHA (${SOURCE_SHA}) and EVIDENCE_SHA (${EVIDENCE_SHA}):" >&2
    for f in "${DISALLOWED_CHANGES[@]}"; do
        echo "  - ${f}" >&2
    done
    echo "Certification rejected: Commits after SOURCE_SHA must be strictly evidence-only." >&2
    exit 1
fi

echo "SUCCESS: All changes between ${SOURCE_SHA} and ${EVIDENCE_SHA} are verified evidence-only."
exit 0
