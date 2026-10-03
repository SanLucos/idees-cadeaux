#!/bin/sh
# One-shot setup of the dev Garage: node layout, bucket, and the access
# key the backend uses (STORAGE_KEY / STORAGE_SECRET). Idempotent.
set -eu

until garage -c /etc/garage.toml status > /dev/null 2>&1; do sleep 1; done

NODE=$(garage -c /etc/garage.toml node id -q | cut -d@ -f1)
if garage -c /etc/garage.toml layout show | grep -q "No nodes currently have a role"; then
  garage -c /etc/garage.toml layout assign -z dc1 -c 1G "$NODE"
  garage -c /etc/garage.toml layout apply --version 1
fi

garage -c /etc/garage.toml bucket info "$STORAGE_BUCKET" > /dev/null 2>&1 \
  || garage -c /etc/garage.toml bucket create "$STORAGE_BUCKET"
garage -c /etc/garage.toml key info "$STORAGE_KEY" > /dev/null 2>&1 \
  || garage -c /etc/garage.toml key import --yes -n idees-cadeaux "$STORAGE_KEY" "$STORAGE_SECRET"
garage -c /etc/garage.toml bucket allow --read --write --owner "$STORAGE_BUCKET" --key "$STORAGE_KEY"

echo "Garage ready: bucket $STORAGE_BUCKET"
