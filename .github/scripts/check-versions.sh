#!/usr/bin/env bash
# Fails unless composer.json and etc/module.xml state the same module version and
# CHANGELOG.md has an entry for it. With an argument (a release tag), the tag must
# match as well.
set -euo pipefail

cd "$(dirname "$0")/../.."

composer_version=$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' composer.json | head -n1)
module_version=$(sed -n 's/.*setup_version="\([^"]*\)".*/\1/p' etc/module.xml | head -n1)

echo "composer.json version:     ${composer_version}"
echo "etc/module.xml version:    ${module_version}"

status=0
if [ -z "$composer_version" ] || [ "$composer_version" != "$module_version" ]; then
	echo "::error::composer.json and etc/module.xml do not state the same version."
	status=1
fi

if ! grep -qx "## ${composer_version}" CHANGELOG.md; then
	echo "::error file=CHANGELOG.md::No entry '## ${composer_version}' in CHANGELOG.md."
	status=1
fi

if [ "$#" -gt 0 ]; then
	echo "Release tag:               $1"
	if [ "$1" != "$composer_version" ]; then
		echo "::error::Tag $1 does not match module version ${composer_version}."
		status=1
	fi
fi

exit "$status"
