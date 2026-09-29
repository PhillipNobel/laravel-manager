# Test Context

## RUN 11 installer seam

Installer behavior is tested only through the public `scripts/install.sh --dry-run` command. It validates the configured HTTPS GitHub repository and prints the planned Ubuntu setup without requiring root or changing the host. Feature tests must not run apt, download software, clone repositories, create databases, write system configuration, or start/reload services.

Use `bash -n` for shell syntax. Before marking RUN 11 complete, verify full package and service installation on a disposable Ubuntu 24.04 VPS; this cannot be established by local tests.
