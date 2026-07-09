# Commit versioning policy

This web repo uses a sequential version number for every commit.

- Baseline: `V1`
- Next commit: `V2`
- Then: `V3`, `V4`, and so on

## How it works

- The tracked `WEB_VERSION` file stores the latest committed version number.
- The git hooks in `.githooks/` automatically:
  - bump `WEB_VERSION` before each commit
  - prefix the commit message with the matching `V<n>` label

## Rule

Every commit that affects the web should keep this numbering sequence in order.
