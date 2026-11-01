# Project delivery workflow

The user requests that every completed modification be committed and pushed to
the configured GitHub repository after the relevant checks pass. Follow that
workflow for subsequent changes unless the user explicitly says otherwise.

The working source is `panel/src`. The Git checkout is `github-ready`; inspect
its status and remote before syncing only the files changed for the task. Keep
unrelated work intact. Never commit `private`, credentials, database dumps,
runtime state, sessions, test accounts, compiled service executables or deployment secrets.
Report the verified commit link and distinguish a source push from a release
or a production deployment. Do not automatically publish a release.
