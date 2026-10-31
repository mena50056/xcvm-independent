#!/bin/bash
# Unattended fresh-host MAIN install. Never removes or replaces an existing panel.
set -euo pipefail
umask 077
SERVER_IP=''
CHECK_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --server-ip=*) SERVER_IP=${arg#*=} ;;
    --allow-original-test-host) : ;; # Legacy argument, no longer required
    --check-only) CHECK_ONLY=1 ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done
stop() { echo "STOP: $*" >&2; exit 1; }
[[ $(id -u) == 0 ]] || stop 'Run as root'
[[ $(uname -m) == x86_64 ]] || stop 'Ubuntu 22.04 amd64 is required'
source /etc/os-release
[[ "$ID" == ubuntu && "$VERSION_ID" == 22.04 ]] || stop 'Ubuntu 22.04 is required'
# All read-only checks run before package installation, service or DB changes.
for path in /home/horus_xc /home/xc_vm /opt/horus_xc /opt/xcvm-independent-lab /home/xtreamcodes /home/xui /opt/xui /home/streamcreed; do
  [[ ! -e "$path" && ! -L "$path" ]] || stop "Existing panel path found: $path"
done
for path in /var/lib/mysql/xc_vm /var/lib/mysql/xcvm_lab_main /var/lib/mysql/xcvm_lab_migrate /var/lib/mysql/xtream_iptvpro /var/lib/mysql/xui; do
  [[ ! -e "$path" && ! -L "$path" ]] || stop "Existing panel database found: $path"
done
if id xcvm_lab >/dev/null 2>&1 || id xc_vm >/dev/null 2>&1; then
  stop 'Existing panel service account found'
fi
for path in /etc/systemd/system/xcvm-independent-lab.service /etc/systemd/system/xcvm-independent-agent.service /etc/systemd/system/xc_vm.service /etc/systemd/system/xui.service /etc/init.d/xc_vm /etc/init.d/xtreamcodes; do
  [[ ! -e "$path" && ! -L "$path" ]] || stop "Existing panel service found: $path"
done
listeners=$(ss -H -lnt '( sport = :80 or sport = :443 or sport = :18080 or sport = :8080 )')
[[ -z "$listeners" ]] || stop 'A web/panel port is occupied (80, 443, 8080 or 18080)'
# Resolve only public addresses actually assigned to this host; no lookup service.
if [[ -z "$SERVER_IP" ]]; then
  SERVER_IP=$(python3 - <<'PYIP'
import ipaddress,json,subprocess
interfaces=json.loads(subprocess.check_output(['ip','-j','-4','address'],text=True))
public=[]
for interface in interfaces:
    for item in interface.get('addr_info',[]):
        if item.get('family')=='inet' and item.get('scope')=='global':
            address=ipaddress.IPv4Address(item['local'])
            if address.is_global:public.append(str(address))
public=sorted(set(public))
try:
    route=json.loads(subprocess.check_output(['ip','-j','-4','route','get','1.1.1.1'],text=True))
    preferred=route[0].get('prefsrc') if route else None
except (subprocess.CalledProcessError,IndexError):preferred=None
if preferred in public:print(preferred)
elif len(public)==1:print(public[0])
else:raise SystemExit('No unambiguous assigned public IPv4; use --server-ip=IPv4')
PYIP
  ) || stop 'Could not determine this server public IPv4'
fi
[[ "$SERVER_IP" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || stop 'Provide --server-ip=IPv4'
for octet in ${SERVER_IP//./ }; do
  (( 10#$octet <= 255 )) || stop 'Invalid IPv4 address'
done
ip -o -4 addr show | awk '{split($4,a,"/"); print a[1]}' | grep -Fxq "$SERVER_IP" || stop 'Requested IP is not assigned to this server'
echo "Selected MAIN IPv4: $SERVER_IP"
if [[ "$CHECK_ONLY" == 1 ]]; then echo 'PASS: read-only fresh-host checks'; exit 0; fi
if command -v cloud-init >/dev/null 2>&1; then
  cloud_status=0
  cloud-init status --wait || cloud_status=$?
  [[ "$cloud_status" == 0 || "$cloud_status" == 2 ]] || stop 'cloud-init has not completed successfully'
fi

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
git -c credential.helper= -c core.askPass=/bin/false clone --depth 1 --branch installer-public-v12 \
  https://github.com/mena50056/xcvm-independent.git "$work/source"
args=(--role main --repository mena50056/xcvm-independent --tag v0.1.0-lab.11 --server-ip "$SERVER_IP" --admin-user admin)
python3 "$work/source/deployment/install.py" "${args[@]}"
python3 "$work/source/deployment/enable_updates.py"
# Allow nginx's supplemental xcvm_lab group to reach the fanout socket only.
# The separate .runtime-state directory and local keys remain private.
for path in /opt/horus_xc/panel /opt/horus_xc/panel/src /opt/horus_xc/panel/src/bin /opt/horus_xc/panel/src/bin/xc_fanout /opt/horus_xc/panel/src/bin/xc_fanout/sockets; do
  chmod g+x "$path"
done
if command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
  ufw allow 443/tcp
fi
systemctl is-active --quiet xcvm-independent-lab xcvm-independent-fanout xcvm-independent-monitor xcvm-independent-fanout-sync xcvm-independent-signals nginx mariadb redis-server
python3 - "$SERVER_IP" <<'PY'
import time,urllib.request,ssl,pathlib,json,hashlib,sys
for attempt in range(20):
    try:
        with urllib.request.urlopen('http://127.0.0.1:18080/horus_xc/login',timeout=5) as r:
            if r.status!=200:raise RuntimeError('Login did not return HTTP 200')
        print('PASS local MAIN login endpoint: HTTP 200')
        root=pathlib.Path('/opt/horus_xc')
        options=json.loads((root/'installed.json').read_text())
        assert options['version']=='v0.1.0-lab.4'
        ip=sys.argv[1]
        context=ssl.create_default_context(cafile=str(root/'tls/server.crt'))
        with urllib.request.urlopen('https://'+ip+'/horus_xc/login',context=context,timeout=5) as response:
            assert response.status==200
        manifest=json.loads((root/'deployment/native-agent.json').read_text())
        assert hashlib.sha256((root/'panel/src/bin/xc_agent/xc_agent').read_bytes()).hexdigest()==manifest['binary_sha256']
        print('PASS HTTPS IP login, certificate verification and native agent SHA256')
        break
    except Exception:
        if attempt==19:raise SystemExit('MAIN login health check failed; inspect the install log')
        time.sleep(1)
PY
echo 'MAIN installation finished. Login details:'
cat /opt/horus_xc/.runtime-state/main/admin-login.json
printf '\nPanel URL: https://%s/horus_xc/login\n' "$SERVER_IP"
