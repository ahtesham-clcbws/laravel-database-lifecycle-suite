# Integration App Test Fixtures and Harness

This directory contains the reproducible test harness code for validating the `clcbws/laravel-database-lifecycle-suite` package inside a fresh, real Laravel application.

## Directory Structure

- `fixtures/`: Database schema definitions and seed data representing complex real-world schemas (Users, Roles, Permissions, Employees, Projects, Products, Orders, JSON columns, composite keys).
- `scripts/`: Automated orchestration scripts to spin up `.workbench/lifecycle-test-app`, install the local package via Composer path repository, run migrations, execute all package commands, verify reverse-engineering, and validate production safety.
- `smoke-tests/`: Verification scripts and assertions testing every documented Artisan command and failure recovery mode.
