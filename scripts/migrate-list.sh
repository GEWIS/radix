#!/bin/sh

# This script prints the available migrations for a specific alias
set -e

. ./scripts/migrate-alias.sh

bin/console doctrine:migrations:list --no-interaction $configuration

export alias=$alias
export migrations=$migrations
