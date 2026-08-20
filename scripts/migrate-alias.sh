#!/bin/sh

# This script allows selecting an alias
# If you put this directly in the makefile, replace $ with $$
set -e

read -rp "Enter EM_ALIAS (default or web): " alias
([ "$alias" = "default" ] || [ "$alias" = "web" ]) || (echo "Not a valid alias, expected default or web, exiting..."; exit 1)

# Each set names its own connection, so the configuration is the whole of what tells the two apart.
if [ "$alias" = "web" ]; then
    configuration="--configuration=config/packages/migrations/web.yaml"
else
    configuration="--configuration=config/packages/migrations/default.yaml"
fi

export alias=$alias
export configuration=$configuration
