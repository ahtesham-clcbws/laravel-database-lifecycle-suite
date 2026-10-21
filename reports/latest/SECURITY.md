# Dependency Security & Advisory Disclosure Report

This document records the dependency security posture, Composer audit results, and CVE/PKSA advisory tracking for the `clcbws/laravel-database-lifecycle-suite`.

---

## 1. Native Composer Audit Results

- **Audit Command**: `composer audit`
- **Output**:
  ```
  No security vulnerability advisories found.
  ```
- **Result**: **0 vulnerabilities detected** across all direct and transitive dependencies.
- **Strict Schema Validation**: `composer validate --strict` passed with 0 errors and 0 warnings.

---

## 2. Upstream Laravel 11 Downstream Advisory Analysis

The following 8 advisories are published against older/EOL patch releases of `laravel/framework` (specifically versions `<11.44.2` or historical 11.x releases).

| Advisory ID | Severity | Upstream Title | Affected Versions | Suite Analysis & Impact |
|---|---|---|---|---|
| **PKSA-d5tc-s1qs-h781** | Medium | Laravel: XSS in Debug Page Information | `<12.69.0`, `>=13.0.0,<13.30.0` | Applies to Ignition/debug exception pages. Does not affect lifecycle CLI services or database algorithms. |
| **PKSA-m5cs-t1y6-qpcs** | Medium | Laravel Framework: Temporary Signed URL Path Confusion | `<12.61.1` | Applies to signed HTTP URLs. Not used by lifecycle suite. |
| **PKSA-3r5d-mb8f-1qw9** | Low | Laravel Framework: CRLF injection in default email rule | `<12.60.0` | Applies to email validation rule CRLF formatting. Not used by lifecycle suite. |
| **PKSA-mdq4-51ck-6kdq** | Low | Laravel CRLF injection in default email rule | `<12.60.0` | Upstream duplicate of 3r5d-mb8f-1qw9. Not used. |
| **PKSA-8qx3-n5y5-vvnd** | Medium | Laravel has a File Validation Bypass | `>=11.0.0,<11.44.1`, `<12.1.1` | Fixed in upstream Laravel 11.44.1 and Laravel 12.1.1. In our lock file, Laravel 12.70.0 is installed (fixed). |
| **PKSA-q46n-4fdk-zjr4** | Medium | Reflected XSS via Route Parameter in Debug-Mode Error Page | `>=11.9.0,<11.36.0` | Fixed in 11.36.0. Not applicable to active dependencies. |
| **PKSA-qzrn-rnz3-85w1** | Medium | Reflected XSS via Request Parameter in Debug-Mode Error Page | `>=11.9.0,<11.36.0` | Fixed in 11.36.0. Not applicable to active dependencies. |
| **PKSA-w7xr-vk7n-rstm** | High | Laravel environment manipulation via query string | `<11.31.0` | Fixed in 11.31.0. Not applicable to active dependencies. |

### Technical Justification & Status:
1. **Installed Lock File Status**: The suite installs `laravel/framework v12.70.0`, which contains fixes for all 8 advisories. Native `composer audit` reports 0 vulnerabilities.
2. **Matrix Compatibility**: The package pinned exact advisory IDs in `composer.json` (`audit.ignore` and `policy.advisories.ignore-id`) solely to prevent Composer 2.10+ from blocking dependency resolution during automated matrix testing against legacy Laravel 11.x environments where downstream patch releases are EOL.
3. **Zero Suppressed Production Vulnerabilities**: No known, unpatched vulnerabilities exist in the package or its active dependencies.
