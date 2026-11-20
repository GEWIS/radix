#!/bin/sh

# This script allows selecting an alias
# If you put this directly in the makefile, replace $ with $$
set -e

read -rp "Enter EM_ALIAS (database or web): " alias
([ "$alias" = "database" ] || [ "$alias" = "web" ]) || (echo "Not a valid alias, expected database or web, exiting..."; exit 1)

# Each set names its own connection, so the configuration is the only thing that distinguishes the two.
if [ "$alias" = "web" ]; then
    configuration="--configuration=config/packages/migrations/web.yaml"
else
    configuration="--configuration=config/packages/migrations/database.yaml"
fi

export alias=$alias
export configuration=$configuration
