# Dependency Security & Advisory Disclosure Report

This document records the dependency security posture, unsuppressed Composer audit results, and CVE/PKSA advisory tracking for `clcbws/laravel-database-lifecycle-suite`.

---

## 1. Unsuppressed Composer Audit Results

- **Audit Command**: `composer audit` (executed with an isolated configuration stripping all ignore entries)
- **Output**:
  ```
  No security vulnerability advisories found.
  ```
- **Active Lock File Posture**: `0 vulnerabilities detected` across all direct and transitive dependencies installed in `composer.lock`.
- **Strict Schema Validation**: `composer validate --strict` passed with 0 errors and 0 warnings.
- **Root Package Audit Status**: Advisory-free dependency set.

---

## 2. Upstream Advisory Classification & Categorization

The advisory list was reviewed against installed dependencies and historical matrix configurations. The findings are separated into strict categories below:

### A. Advisory-Free Dependency Set (Active Lockfile)
- Package root `composer.lock` installs `laravel/framework v12.70.0`.
- Native, unsuppressed `composer audit` reports **0 advisories**.

### B. Pruned / Inapplicable Advisories (Removed from composer.json)
The following 4 advisories were previously ignored but are completely inapplicable to supported patch levels:
- **PKSA-8qx3-n5y5-vvnd** (Fixed in 11.44.1 / 12.1.1): Inapplicable.
- **PKSA-q46n-4fdk-zjr4** (Fixed in 11.36.0): Inapplicable.
- **PKSA-qzrn-rnz3-85w1** (Fixed in 11.36.0): Inapplicable.
- **PKSA-w7xr-vk7n-rstm** (Fixed in 11.31.0): Inapplicable.
These 4 entries have been purged from `composer.json`.

### C. Downstream EOL Matrix Advisories (Residual Risk in Legacy Laravel 11.x)
In automated matrix environments running downstream `laravel/framework ^11.0` (which resolves to `v11.57.0`, the final release in the 11.x branch before EOL), 4 advisories exist in upstream Laravel 11.x that will not receive upstream backports:

| Advisory ID | Severity | Title | Affected Range | Impact Analysis on Database Suite |
|---|---|---|---|---|
| **PKSA-d5tc-s1qs-h781** | Low | Laravel: XSS in Debug Page Information | `<12.69.0` | Ignition/debug page exception presentation. Not used in lifecycle suite CLI or database algorithms. |
| **PKSA-m5cs-t1y6-qpcs** | Medium | Temporary Signed URL Path Confusion | `<12.61.1` | Signed HTTP routing URLs. The package is a database lifecycle CLI library and uses no HTTP routing. |
| **PKSA-3r5d-mb8f-1qw9** | High | CRLF injection in default email rule | `<12.60.0` | Default email validator formatting. Not used in database algorithms. |
| **PKSA-mdq4-51ck-6kdq** | Moderate | CRLF injection in default email rule | `<12.60.0` | Duplicate upstream CVE report of PKSA-3r5d-mb8f-1qw9. |

### Technical Policy & Residual Risk Acceptance:
1. **Zero Production Risk**: These advisories do not affect any code or public APIs in `clcbws/laravel-database-lifecycle-suite`.
2. **Matrix Pinning**: Only the 4 downstream EOL advisories are retained in `composer.json` solely to allow Composer 2.10+ matrix testing on Laravel 11.x without stalling test execution.
3. **No Unresolved Vulnerabilities**: All production dependencies on Laravel 12.x are 100% patched and advisory-free.
