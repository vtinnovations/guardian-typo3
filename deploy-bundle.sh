#!/bin/bash
set -euo pipefail

LOCAL_DIR="/Users/administrator/Sites/vtinnovations/projects-typo3/guardian-typo3/"
REMOTE_USER="visshuser"
REMOTE_HOST="5.9.70.253"
REMOTE_PROJECT="/var/www/vhosts/vrisini.com/projects/brickie-typo3"
REMOTE_EXT="$REMOTE_PROJECT/packages/guardian-typo3"

PACKAGE_NAME="$(php -r '$package = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $package["name"] ?? "";' "$LOCAL_DIR/composer.json")"
if [ "$PACKAGE_NAME" != "vtinnovations/guardian-typo3" ]; then
  echo "Refusing deployment: expected vtinnovations/guardian-typo3, found $PACKAGE_NAME" >&2
  exit 1
fi

echo "Deploying Guardian extension..."

rsync -az --delete \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.internal/' \
  --exclude='.build/' \
  --exclude='.phpunit.cache/' \
  --exclude='.DS_Store' \
  --exclude='node_modules/' \
  --exclude='vendor/' \
  --exclude='var/' \
  --exclude='specs/' \
  --exclude='deploy-bundle.sh' \
  "$LOCAL_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_EXT/"

echo "Refreshing TYPO3 on server..."

ssh "$REMOTE_USER@$REMOTE_HOST" "
  cd '$REMOTE_PROJECT' &&
  composer dump-autoload -o &&
  vendor/bin/typo3 cache:flush &&
  rm -rf var/cache/code/* var/cache/data/* typo3temp/var/cache/code/* typo3temp/var/cache/data/*
"

echo "Done."
echo "Test:"
echo "https://brickie-typo3.vrisini.com/typo3/module/system/guardian"
