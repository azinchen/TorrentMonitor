#!/bin/sh
# Empties the data directory of the application under test (TM_DATA_DIR), so the
# next request starts from a fresh installation.
set -e
mkdir -p "$TM_DATA_DIR"
chmod 777 "$TM_DATA_DIR"
find "$TM_DATA_DIR" -mindepth 1 -maxdepth 1 -type f -delete
