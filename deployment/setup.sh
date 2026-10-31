#!/bin/bash
set -euo pipefail
umask 077
ROOT=/opt/horus_xc
ROLE=${1:?main or node required}
[[ "$ROLE" == main || "$ROLE" == node ]] || exit 2
[[ $(id -u) == 0 && $(uname -m) == x86_64 ]] || { echo 'Root and x86_64 required'; exit 1; }
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 22.04 ]] || { echo 'Only Ubuntu 22.04 amd64 has a runtime bundle'; exit 1; }
[[ ! -e /home/horus_xc && ! -L /home/horus_xc && ! -e /home/xc_vm && ! -L /home/xc_vm ]] || { echo 'Existing panel refused'; exit 1; }
if ss -H -lnt '( sport = :443 or sport = :18080 )' | grep -q .; then
  echo 'Required panel ports are occupied; refusing to alter existing services'; exit 1
fi
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates ffmpeg libssl3 libonig5 libzip4 libsodium23 libmaxminddb0 libssh2-1 libxml2 libcurl3-gnutls libsqlite3-0 libpng16-16 libjpeg-turbo8 libfreetype6 libxslt1.1 libicu70 sudo python3 openssl
id xcvm_lab >/dev/null 2>&1 || useradd --system --home-dir "$ROOT" --shell /usr/sbin/nologin xcvm_lab
SRC="$ROOT/panel/src"
mkdir -p "$SRC"/{config/cluster,tmp/{cache,crons,logs,opened_cons,player,flood,gateway},signals,content/{streams,archive,vod,playlists},storage/images,bin/xc_fanout/sockets,bin/nginx/conf/codes}
chmod 755 "$ROOT"
python3 "$ROOT/deployment/ensure_horus_home.py"
chmod 755 "$SRC/bin/xc_agent/xc_agent" "$SRC/bin/xc_fanout/xc_fanout"
python3 "$ROOT/deployment/update_worker.py" isolate-vendor
python3 "$ROOT/deployment/configure.py" "$ROLE"
if [[ "$ROLE" == main ]]; then
  apt-get install -y nginx mariadb-server redis-server
  systemctl enable --now mariadb redis-server
  chmod 755 "$SRC/bin/php/bin/php"
  "$SRC/bin/php/bin/php" -c "$ROOT/php.ini" "$ROOT/deployment/provision-main.php"
fi
chown -R xcvm_lab:xcvm_lab "$ROOT"
chmod 700 "$ROOT/.runtime-state" 2>/dev/null || true
if [[ "$ROLE" == main ]]; then
  # nginx workers use the supplemental xcvm_lab group for the fanout socket.
  for path in "$ROOT/panel" "$SRC" "$SRC/bin" "$SRC/bin/xc_fanout" "$SRC/bin/xc_fanout/sockets"; do
    chmod g+x "$path"
  done
fi
if [[ "$ROLE" == node ]]; then
  install -o xcvm_lab -g xcvm_lab -m600 "$ROOT/.node-enrollment.json" "$SRC/config/cluster/enrollment.json"
  runuser -u xcvm_lab -- "$SRC/bin/xc_agent/xc_agent" install -state "$SRC/config/cluster/agent.json" < "$SRC/config/cluster/enrollment.json"
  rm -f -- "$ROOT/.node-enrollment.json" "$SRC/config/cluster/enrollment.json"
fi
python3 "$ROOT/deployment/configure.py" services
systemctl daemon-reload
if [[ "$ROLE" == main ]]; then
  nginx -t
  systemctl enable --now nginx
  systemctl reload nginx
  systemctl enable --now xcvm-independent-lab xcvm-independent-fanout xcvm-independent-monitor xcvm-independent-fanout-sync xcvm-independent-signals xcvm-independent-maintenance.timer
  echo "MAIN installed. Private login details: $ROOT/.runtime-state/main/admin-login.json"
else
  systemctl enable --now xcvm-independent-fanout xcvm-independent-agent
  echo 'Node installed. Its enrollment identity belongs to this node only.'
fi
echo 'No original activation key or original xcvm_core extension was installed.'
