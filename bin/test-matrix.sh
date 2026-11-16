#!/bin/sh
# Runs the bundle's tests against a specific Symfony version.
# Usage: bin/test-matrix.sh "7.4.*"
#
# Generates a temporary composer.json with the requested constraint instead of using
# Flex (SYMFONY_REQUIRE), which would apply recipes inside the bundle itself.
set -e

constraint="$1"
if [ -z "$constraint" ]; then
    echo "Pass the Symfony version, for example: bin/test-matrix.sh \"7.4.*\"" >&2
    exit 1
fi

cd "$(dirname "$0")/.."
trap 'rm -f composer.matrix.json composer.matrix.lock' EXIT

sed "s/\^6\.4 || \^7\.0 || \^8\.0/$constraint/g" composer.json > composer.matrix.json
COMPOSER=composer.matrix.json composer update --no-interaction --no-progress --quiet

echo "Symfony $(COMPOSER=composer.matrix.json composer show symfony/http-kernel 2>/dev/null | awk '/^versions/ {print $NF}'), Doctrine ORM $(COMPOSER=composer.matrix.json composer show doctrine/orm 2>/dev/null | awk '/^versions/ {print $NF}'), Monolog $(COMPOSER=composer.matrix.json composer show monolog/monolog 2>/dev/null | awk '/^versions/ {print $NF}')"
vendor/bin/phpunit
