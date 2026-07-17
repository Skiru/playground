# Repository scripts

Root automation is POSIX `sh`, resolves the repository directory itself, and
uses `set -eu`. Destructive data reset requires `--with-data-loss`; user-facing
commands are compatible with fish.
