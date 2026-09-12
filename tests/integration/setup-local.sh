#!/usr/bin/env bash
# Disposable WSL/Linux harness. Requires PHP extensions, MariaDB and unzip.
set -euo pipefail
root=/tmp/iwac-integration
mkdir -p "$root/typesense/data"
if [ ! -f "$root/omeka-s/vendor/autoload.php" ]; then
  curl -fL https://github.com/omeka/omeka-s/releases/download/v4.2.1/omeka-s-4.2.1.zip -o "$root/omeka.zip"
  unzip -qo "$root/omeka.zip" -d "$root"
fi
if ! curl -fsS http://127.0.0.1:18108/health; then
  if [ ! -x "$root/typesense/typesense-server" ]; then
    arch=$(uname -m)
    case "$arch" in aarch64) arch=arm64;; x86_64) arch=amd64;; *) exit 2;; esac
    curl -fL "https://dl.typesense.org/releases/30.2/typesense-server-30.2-linux-$arch.tar.gz" -o "$root/typesense/server.tar.gz"
    tar -xzf "$root/typesense/server.tar.gz" -C "$root/typesense"
  fi
  nohup "$root/typesense/typesense-server" --data-dir="$root/typesense/data" --api-key=iwac-disposable-test-key --api-address=127.0.0.1 --api-port=18108 --peering-port=18107 > "$root/typesense.log" 2>&1 < /dev/null &
fi
for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:18108/health; then break; fi
  sleep 1
done
service mariadb start
mariadb -e 'CREATE DATABASE IF NOT EXISTS iwac_search_test;'
echo 'Disposable services ready; run tests/integration/contracts.php as the MariaDB socket user.'
