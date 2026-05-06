# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-05-06
### Added
- Initial release of Laravel Database Lifecycle Suite.
- **Index Naming Standardizer**: Detect and fix non-standard index names.
- **Data Drift Deep Dive**: Compare data across connections with new `--table` filtering.
- **Legacy Bridge**: Reverse-engineer existing databases into Migrations, Models, and Seeders.
- **Index Health Check**: Added **Potential Foreign Key** detection (unindexed columns following `_id` convention).
- **Schema Snapshots**: Save and compare schema states via JSON.
- **ERD Graph Generation**: Mermaid.js diagram output with file saving support.
- **Lifecycle Scorecard**: 360-degree database health report.
- **GitHub Actions**: Automated testing workflow.

### Fixed
- **Schema Isolation**: Fixed cross-database table leakage in multi-database environments.
- **Data Drift**: Added missing `--table` option to the command line interface.
