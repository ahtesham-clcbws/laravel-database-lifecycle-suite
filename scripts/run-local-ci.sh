#!/usr/bin/env bash
# ==============================================================================
# Laravel Database Lifecycle Suite - Local CI & Test Matrix Verification Suite
# ==============================================================================
# Executes all quality, safety, static analysis, and multi-database test suites
# locally using the authoritative verification runner.
# ==============================================================================

set -eo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT_DIR}"

REPORT_FILE="VERIFICATION_REPORT.md"
START_TIME=$(date -u +"%Y-%m-%d %H:%M:%S UTC")

echo "======================================================================"
echo " Executing Local CI Suite via Authoritative Verification Engine"
echo " Time: ${START_TIME}"
echo "======================================================================"

# Delegate directly to verify-release.sh to ensure exact parity, safety, locking, and exit code accuracy
if ! bash "${ROOT_DIR}/scripts/verify-release.sh" "$@"; then
    echo "ERROR: Local CI Verification failed. Non-zero exit code returned." >&2
    exit 1
fi

LATEST_MANIFEST="${ROOT_DIR}/reports/latest/manifest.json"
if [ ! -f "${LATEST_MANIFEST}" ]; then
    echo "ERROR: Expected reports/latest/manifest.json was not generated." >&2
    exit 1
fi

# Extract execution metadata and populate VERIFICATION_REPORT.md
php -r '
$manifestPath = $argv[1];
$reportPath = $argv[2];
$data = json_decode(file_get_contents($manifestPath), true);

$rows = "";
foreach ($data["results"] as $gate => $status) {
    $icon = ($status === "PASS") ? "✅ Passed" : (($status === "SKIPPED") ? "⏭️ Skipped" : "❌ Failed");
    $gateName = ucwords(str_replace("_", " ", $gate));
    $rows .= "| **{$gateName}** | {$icon} | Status: {$status} |\n";
}

$commit = $data["git"]["commit"] ?? "unknown";
$branch = $data["git"]["branch"] ?? "unknown";
$php = $data["runtime"]["php"] ?? "unknown";
$composer = $data["runtime"]["composer"] ?? "unknown";
$overall = $data["overall_status"] ?? "FAIL";
$startTime = $data["start_time"] ?? "unknown";
$endTime = $data["end_time"] ?? "unknown";

$report = <<<EOF
# Local CI & Verification Report

Generated automatically from verified release run `{$data["run_id"]}`.

## System & Runtime Environment
- **Tested Commit**: `{$commit}`
- **Branch**: `{$branch}`
- **PHP**: `{$php}`
- **Composer**: `{$composer}`
- **Execution Window**: {$startTime} -> {$endTime}
- **Overall Certification**: **{$overall}**

## Quality & Multi-Engine Verification Matrix

| Suite / Gate | Status | Details |
|---|---|---|
{$rows}
---
*Verified locally via authoritative runner against commit `{$commit}`.*
EOF;

file_put_contents($reportPath, $report);
' "${LATEST_MANIFEST}" "${REPORT_FILE}"

echo "======================================================================"
echo " Full Local CI Verification Completed Successfully!"
echo " Results written to: ${REPORT_FILE}"
echo "======================================================================"
