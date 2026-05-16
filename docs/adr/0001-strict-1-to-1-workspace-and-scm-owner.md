# Strict 1:1 between Workspace and SCM Owner

A cdv-rabbit Workspace is bound to exactly one SCM Owner at a time, enforced by a `unique` constraint on `workspaces.scm_owner_slug`. We considered allowing one Workspace to span multiple SCM owners (and potentially multiple providers) simultaneously, but rejected it: it would require per-owner cost tracking, fragment the kill switch semantics, and bloat the dashboard. Relaxing 1:1 → 1:N is reversible; collapsing 1:N → 1:1 is destructive. Customers migrating providers create a new Workspace; reviews from the old Workspace stay where they are.
