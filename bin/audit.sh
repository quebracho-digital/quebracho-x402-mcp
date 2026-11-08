#!/usr/bin/env bash
# Pre-push audit: every check must pass before a push.
# As a git hook: ln -s ../../bin/audit.sh .git/hooks/pre-push
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
app=/var/www/html/app
step() { echo "== $*"; }

step tests
ddev exec --dir $app vendor/bin/phpunit
step phpstan
ddev exec --dir $app vendor/bin/phpstan --no-progress
step php-cs-fixer
ddev exec --dir $app vendor/bin/php-cs-fixer fix --config=/var/www/html/.php-cs-fixer.dist.php --dry-run --diff
step composer
ddev exec --dir $app composer validate --strict
ddev exec --dir $app composer audit
step gitleaks
docker run --rm -v "$PWD:/repo" zricethezav/gitleaks:v8.28.0 git /repo --no-banner
step semgrep
docker run --rm -v "$PWD:/src" semgrep/semgrep:1.140.0 semgrep scan --config p/php --error --quiet --exclude vendor
step smoke
bash bin/smoke.sh
echo "audit OK"
