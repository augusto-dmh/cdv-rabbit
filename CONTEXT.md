# cdv-rabbit

AI code review service: ingests pull requests from a source-control host, runs them through an LLM, and posts inline review comments. Multi-tenant via a Workspace boundary; SCM and LLM are pluggable per Workspace.

## Language

**Workspace**:
The multi-tenant boundary inside cdv-rabbit. Every business row carries `workspace_id`. Owns SCM Provider config, LLM Provider config, and the set of repositories enabled for review.
_Avoid_: account, org, tenant.

**SCM Provider**:
The external source-control host (Bitbucket Cloud, GitHub Cloud, future: GitHub Enterprise, Bitbucket Data Center) that owns repositories, dispatches pull-request webhooks, and accepts comment posts. Selected per Workspace via `workspaces.scm_provider` enum, with values granular enough to distinguish products of the same brand (e.g. `bitbucket_cloud` vs. future `bitbucket_dc`).
_Avoid_: git host, code host, VCS provider, forge.

**SCM Owner**:
The provider-side container that owns a set of repositories — Bitbucket calls it a "workspace", GitHub calls it an "organization" (or a user account, when the repo lives under a personal account). Identified by `workspaces.scm_owner_slug` (unique, nullable until connect wizard completes). One SCM Owner ↔ one **Workspace** (strict 1:1).
_Avoid_: bitbucket workspace, github org, account, tenant.

**LLM Provider**:
The external large-language-model service (Anthropic Claude, OpenAI GPT) that performs the review. Selected per Workspace via `workspaces.llm_provider` enum. Orthogonal to SCM Provider.
_Avoid_: model provider, AI vendor, LLM backend.

## Relationships

- A **Workspace** has exactly one **SCM Provider** at a time.
- A **Workspace** has exactly one **SCM Owner** at a time (1:1, enforced by `unique` on `workspaces.scm_owner_slug`).
- A **Workspace** has exactly one **LLM Provider** at a time.
- **SCM Provider** and **LLM Provider** are orthogonal — any combination is valid.
- Switching a Workspace's **SCM Provider** is destructive: it disconnects the existing enabled **Repositories** and resets the SCM Owner binding. Customers migrating between providers create a new Workspace; historical reviews stay in the prior Workspace.

## Flagged ambiguities

- "workspace" (lowercase) is overloaded: cdv-rabbit's tenant boundary vs. Bitbucket's own concept of a "workspace". Resolved: capitalized **Workspace** means the cdv-rabbit tenant; Bitbucket's notion is now subsumed under the generic **SCM Owner**. Coupled column rename: `bitbucket_workspace_slug` → `scm_owner_slug`.
- "owner" on GitHub means both organizations and user accounts; cdv-rabbit treats both as a single **SCM Owner** — the distinction is internal to the SCM driver, not user-visible.
