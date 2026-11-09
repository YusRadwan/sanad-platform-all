# Sanad — Development & Deployment Workflow

This document defines the agreed workflow for making and deploying changes to the Sanad platform. Follow it for future feature work and bug fixes unless the project owner explicitly changes the process.

## 1. Request and understand the change

- The project owner describes the requested feature or issue in the ChatGPT conversation.
- Review the current code in the GitHub repository before proposing or implementing a change.
- Identify the affected files and check frontend/backend/database dependencies.
- Do not introduce unrelated changes or unnecessary steps.

## 2. Implement through GitHub

- Make the change on a dedicated feature branch.
- Do not commit directly to `main`.
- Keep the change focused and preserve existing project conventions.
- Push the implementation to GitHub so it can be reviewed and deployed through the agreed workflow.

## 3. Review and validate

Before deployment, review the changes and run the checks available through the repository/GitHub workflow.

Verify, as applicable:
- Frontend build and type/lint checks.
- Backend syntax, tests, and configuration compatibility.
- Frontend/backend API contract compatibility.
- Database migration correctness and deployment impact.

Do not claim a check passed unless it was actually run and its result was confirmed.

## 4. Deploy to Hostinger using GitHub Actions

- The project owner selects the intended branch in GitHub Actions.
- The project owner starts the workflow using **Run workflow / Deploy**.
- The workflow should build the frontend, deploy the required frontend and backend files to Hostinger, and run database migrations only when the change requires them.
- Never assume a deployment or migration has run until the workflow result confirms it.

### Workflow capability note

The currently agreed target is a deployment workflow that supports the required feature branch and deploys **Frontend + Backend + required Database Migrations** together.

If the existing GitHub Actions workflow only deploys the frontend or does not yet support the selected branch, update and verify the workflow before relying on it for a full-stack deployment. Do not describe backend deployment or migrations as completed until that support exists and has been tested.

## 5. Verify the live deployment

After GitHub Actions reports success, verify the deployed result on Hostinger:

- Main site and frontend routes load correctly.
- Backend/API endpoints respond as expected.
- The changed feature works end to end.
- Database changes are present when a migration was required.
- Check logs and report any deployment/runtime errors.

The existing live site should remain available; avoid unnecessary disruption.

## Working principles

- **No routine local-terminal workflow for the project owner.** Prefer GitHub-based implementation and GitHub Actions deployment. Ask for local commands only if a task genuinely cannot be completed or verified through the available GitHub/project tools, and explain why first.
- **No direct changes to `main`.**
- **No unnecessary steps or repeated setup.** Reuse the existing repository, branches, and deployment workflow.
- **Work from the actual repository state.** Do not guess file contents, deployment configuration, secrets, or infrastructure settings.
- **Protect production data.** Review migrations and potentially destructive operations before deployment; never run a migration merely because a deployment was requested.
- **Be transparent about status.** Distinguish clearly between implemented, pushed, tested, deployed, migrated, and verified.

## Short version

**Owner requests a change → implement and push it on a feature branch → review and test → owner runs GitHub Actions Deploy → verify the live site on Hostinger.**

---

## Current project status (as of 2026-11-09)

The following status was provided for the active work at the time this workflow was documented:

| Item | Status |
|---|---|
| Branch `feature/video-watch-session-analytics` | Pushed to GitHub |
| Frontend changes | Not deployed yet |
| Backend changes | Not deployed yet |
| Database migration | Not executed yet |
| Existing Hostinger site | Continues serving the current live version |

The next deployment-related task is to ensure the GitHub Actions workflow supports the required branch and full-stack deployment (frontend, backend, and only the necessary migrations).
