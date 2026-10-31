#!/bin/bash
# Unattended fresh-host MAIN install. Never removes or replaces an existing panel.
set -euo pipefail
umask 077
SERVER_IP=''
ALLOW_ORIGINAL=0
for arg in "$@"; do
  case "$arg" in
    --server-ip=*) SERVER_IP=${arg#*=} ;;
    --allow-original-test-host) ALLOW_ORIGINAL=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done
stop() { echo "STOP: $*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || stop 'Run as root'
[[ $(uname -m) == x86_64 ]] || stop 'Ubuntu 22.04 amd64 is required'
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 22.04 ]] || stop 'Ubuntu 22.04 is required'
[[ "$SERVER_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || stop 'Provide --server-ip=IPv4'
for octet in ${SERVER_IP//./ }; do
  (( 10#$octet <= 255 )) || stop 'Invalid IPv4 address'
done
[[ "$SERVER_IP" != 45.32.223.254 || "$ALLOW_ORIGINAL" == 1 ]] || stop 'Former production host needs explicit test authorization'
ip -o -4 addr show | awk '{split($4,a,"/"); print a[1]}' | grep -Fxq "$SERVER_IP" || stop 'Requested IP is not assigned to this server'
if command -v cloud-init >/dev/null 2>&1; then cloud-init status --wait; fi

# All read-only checks run before package installation, service or DB changes.
for path in /home/xc_vm /opt/xcvm-independent-lab /home/xtreamcodes /home/xui /opt/xui /home/streamcreed; do
  [[ ! -e "$path" && ! -L "$path" ]] || stop "Existing panel path found: $path"
done
for path in /var/lib/mysql/xc_vm /var/lib/mysql/xcvm_lab_main /var/lib/mysql/xcvm_lab_migrate /var/lib/mysql/xtream_iptvpro /var/lib/mysql/xui; do
  [[ ! -e "$path" && ! -L "$path" ]] || stop "Existing panel database found: $path"
done
if id xcvm_lab >/dev/null 2>&1 || id xc_vm >/dev/null 2>&1; then
  stop 'Existing panel service account found'
fi
listeners=$(ss -H -lnt '( sport = :80 or sport = :443 or sport = :18080 or sport = :8080 )')
[[ -z "$listeners" ]] || stop 'A web/panel port is occupied (80, 443, 8080 or 18080)'
echo 'Fresh-host checks passed. No existing panel was found.'

work=$(mktemp -d /root/xcvm-bootstrap.XXXXXXXX)
trap 'rm -rf -- "$work"' EXIT
log="/root/xcvm-install-$(date -u +%Y%m%dT%H%M%SZ).log"
touch "$log"
chmod 600 "$log"
exec > >(tee -a "$log") 2>&1
echo "Install log: $log"
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a APT_LISTCHANGES_FRONTEND=none
export GIT_TERMINAL_PROMPT=0
cat > "$work/apt.conf" <<'APT'
DPkg::Lock::Timeout "180";
DPkg::Options { "--force-confdef"; "--force-confold"; };
APT
export APT_CONFIG="$work/apt.conf"
apt-get update
apt-get install -y git python3 ca-certificates
git -c credential.helper= -c core.askPass=/bin/false clone --depth 1 --branch installer-public-v2 \
  https://github.com/mena50056/xcvm-independent.git "$work/source"
args=(--role main --repository mena50056/xcvm-independent --tag v0.1.0-lab.3 --server-ip "$SERVER_IP" --admin-user admin)
if [[ "$ALLOW_ORIGINAL" == 1 ]]; then args+=(--allow-original-test-host); fi
python3 "$work/source/deployment/install.py" "${args[@]}"
# Allow nginx's supplemental xcvm_lab group to reach the fanout socket only.
# The separate .runtime-state directory and local keys remain private.
for path in /opt/xcvm-independent-lab/panel /opt/xcvm-independent-lab/panel/src /opt/xcvm-independent-lab/panel/src/bin /opt/xcvm-independent-lab/panel/src/bin/xc_fanout /opt/xcvm-independent-lab/panel/src/bin/xc_fanout/sockets; do
  chmod g+x "$path"
done
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
  ufw allow 443/tcp
fi
systemctl is-active --quiet xcvm-independent-lab xcvm-independent-fanout xcvm-independent-monitor xcvm-independent-fanout-sync nginx mariadb redis-server
python3 - <<'PY'
import time,urllib.request
for attempt in range(20):
    try:
        with urllib.request.urlopen('http://127.0.0.1:18080/lab/login',timeout=5) as r:
            if r.status!=200:raise RuntimeError('Login did not return HTTP 200')
        print('PASS local MAIN login endpoint: HTTP 200')
        break
    except Exception:
        if attempt==19:raise SystemExit('MAIN login health check failed; inspect the install log')
        time.sleep(1)
PY
echo 'MAIN installation finished. Login details:'
cat /opt/xcvm-independent-lab/.runtime-state/main/admin-login.json
printf '\nPanel URL: https://%s/lab/login\n' "$SERVER_IP"
