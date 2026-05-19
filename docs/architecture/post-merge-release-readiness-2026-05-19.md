# Post-merge release readiness - 2026-05-19

## Scope

This record captures the post-merge readiness state after integrating the Sprint 1 quote-first POS workspace and release gates into `main`.

| Item | Value |
| --- | --- |
| Branch used for this evidence | `codex/release-readiness-post-merge` |
| Base branch | `main` |
| Merge commit validated | `7cb77330 Sprint 1: quote-first POS workspace and release gates` |
| Environment | Local/UAT validation on Windows workspace |
| Human decision owner | Luis Retuerto |
| Decision ticket | `uat://frontend/2026-05-19/luis-retuerto` |

## Validation Results

| Gate | Command | Result |
| --- | --- | --- |
| Composer contract | `composer validate --no-check-publish` | Passed |
| TypeScript contract | `npm run typecheck` | Passed |
| Frontend lint | `npm run lint` | Passed |
| Frontend build | `npm run build` | Passed |
| Frontend unit/contract tests | `npm run test` | 18 files / 50 tests passed |
| Backend feature/unit suite | `composer run velmix:test` | 385 tests / 3528 assertions passed |
| Frontend UAT release readiness | `php artisan frontend:uat-release-readiness --freshness-hours=24 --json` | `ready_for_release` |
| Frontend UAT release closure | `php artisan frontend:uat-release-closure-pack --freshness-hours=24 --decision-owner="Luis Retuerto" --decision-ticket="uat://frontend/2026-05-19/luis-retuerto" --decision-notes="Post-merge human UAT approved by Luis Retuerto." --json` | `ready_for_release_closure` |

## Closure Evidence

The latest closure command generated a `go` decision without bypass flags.

| Artifact | Path |
| --- | --- |
| Closure JSON | `storage/app/frontend-uat/closure/frontend-uat-release-closure-20260519-201712.json` |
| Closure Markdown | `storage/app/frontend-uat/closure/frontend-uat-release-closure-20260519-201712.md` |
| Latest closure JSON | `storage/app/frontend-uat/closure/frontend-uat-release-closure-latest.json` |
| Latest closure Markdown | `storage/app/frontend-uat/closure/frontend-uat-release-closure-latest.md` |

Observed closure highlights:

- `go_no_go.status=go`
- `go_no_go.production_go_allowed=true`
- `go_no_go.uat_dry_run_allowed=true`
- `blocked_items=[]`
- `decision.status=approved_for_cutover`
- `decision.owner=Luis Retuerto`

## Residual Production Preconditions

This validation is sufficient for local/UAT release-candidate confidence. Before production cutover, repeat the same closure in the target environment.

Required production controls:

1. Persist `VELMIX_FRONTEND_UAT_RELEASE_GATE_ENABLED=true` in the target environment.
2. Use a production-like logging stack with JSON output, for example `stderr_json` or `daily_json`.
3. Re-run the closure command in the target environment without `--allow-gate-disabled`.
4. Re-run the closure command in the target environment without `--allow-observability-critical`.
5. Preserve the generated closure JSON and Markdown as release evidence.

## Recommended Next Step

Prepare the release candidate from the validated `main` state, then run the deployment runbook against UAT/staging before any production cutover.

Execution order:

1. Create the release candidate tag or release branch from `main@7cb77330`.
2. Deploy to UAT/staging using the standard release promotion runbook.
3. Run `php artisan frontend:seed-pos-smoke --json`.
4. Run `php artisan frontend:pos-quote-first-uat-smoke --json`.
5. Run `php artisan frontend:uat-signoff-pack --json`.
6. Complete human visual evidence and run `php artisan frontend:uat-visual-evidence-verify --json`.
7. Run `php artisan frontend:uat-release-readiness --freshness-hours=24 --json`.
8. Run `php artisan system:preflight --json --fail-on-critical`.
9. Run `php artisan system:observability-report --json`.
10. Run `php artisan frontend:uat-release-closure-pack --freshness-hours=24 --decision-owner="Luis Retuerto" --decision-ticket="<target-ticket>" --decision-notes="<target UAT approval note>" --json`.

## Rollback Boundary

Frontend rollback must remain non-destructive:

- Do not run destructive database actions.
- Revert only the deployed frontend asset/release symlink to the previous known-good release.
- Preserve all UAT closure evidence for audit.
- Re-run `php artisan system:preflight --json --fail-on-critical` after rollback.
