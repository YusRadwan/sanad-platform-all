# Sanad project instructions

Before making any change for Sanad, read [SANAD_PROJECT_CONTEXT.md](SANAD_PROJECT_CONTEXT.md) completely.

Use the context file as the handoff for architecture, branches, deployment workflow, safety constraints, and current status. Verify the current GitHub branch, workflow, and deployment state before acting because the status section can become outdated.

## Working rules

- Use the GitHub repository `YusRadwan/sanad-platform-all` as the source of truth for code.
- Work on a focused branch and open a pull request. Do not commit directly to `main`.
- Keep `main` and `test/video-providers` separate unless the user explicitly requests a broader integration; they are materially divergent.
- Do not deploy or run database migrations unless the user asks or clearly authorizes that step.
- Never expose Hostinger credentials, environment values, cookies, tokens, or student personal data in logs or project notes.
- After a material change, merge, or deployment, update the current-status section in `SANAD_PROJECT_CONTEXT.md`.
- Communicate with the user in Arabic unless they request another language.
