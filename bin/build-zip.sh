#!/usr/bin/env bash
# build-zip.sh — package a release zip for GitHub Releases / PUC.
#
# Creates smart-image-matcher.zip with the plugin rooted at
# smart-image-matcher/ so WordPress installs into the correct folder.
#
# Usage: ./bin/build-zip.sh
#
# @package SmartImageMatcher
# @since   3.0.8

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="smart-image-matcher"
OUT="${ROOT}/${SLUG}.zip"
STAGE="$(mktemp -d)"

cleanup() {
	rm -rf "${STAGE}"
}
trap cleanup EXIT

echo "Building production autoloader..."
composer install --no-dev --optimize-autoloader --working-dir="${ROOT}" --no-interaction

mkdir -p "${STAGE}/${SLUG}"

rsync -a \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='.legacy/' \
	--exclude='.cursor/' \
	--exclude='.agents/' \
	--exclude='.kiro/' \
	--exclude='.wp-ai/' \
	--exclude='.agent-skills/' \
	--exclude='node_modules/' \
	--exclude='tests/' \
	--exclude='docs/' \
	--exclude='design-samples/' \
	--exclude='bin/' \
	--exclude='.phpunit.cache/' \
	--exclude='*.zip' \
	--exclude='activitylog.txt' \
	--exclude='build-zip.ps1' \
	--exclude='package.json' \
	--exclude='phpcs.xml.dist' \
	--exclude='phpstan.neon.dist' \
	--exclude='phpunit.xml.dist' \
	--exclude='.editorconfig' \
	--exclude='.gitignore' \
	--exclude='.plugincheckignore' \
	--exclude='.phpunit.result.cache' \
	--exclude='IMPLEMENTATION_PLAN.md' \
	--exclude='agents.md' \
	--exclude='AGENTS.md' \
	--exclude='development.md' \
	--exclude='src/Premium/License.php' \
	--exclude='src/UI/PremiumLock.php' \
	"${ROOT}/" "${STAGE}/${SLUG}/"

rm -f "${OUT}"
(
	cd "${STAGE}"
	zip -rq "${OUT}" "${SLUG}"
)

SIZE="$(du -h "${OUT}" | cut -f1)"
echo "Built: ${OUT} (${SIZE})"
