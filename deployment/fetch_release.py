"""Public or authenticated GitHub downloads; never forwards credentials to CDN."""
import argparse
import hashlib
import json
import os
import pathlib
import re
import stat
import urllib.parse
import urllib.request


class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlsplit(newurl).scheme != 'https':
            raise ValueError('Only HTTPS redirects are allowed')
        redirected = super().redirect_request(req, fp, code, msg, headers, newurl)
        if urllib.parse.urlsplit(req.full_url).netloc != urllib.parse.urlsplit(newurl).netloc:
            redirected.remove_header('Authorization')
        return redirected


def token_from_file(path):
    p = pathlib.Path(path)
    if not p.is_file() or p.is_symlink():
        raise ValueError('A regular token file is required')
    if os.name != 'nt' and (p.stat().st_uid != os.geteuid() or stat.S_IMODE(p.stat().st_mode) & 0o077):
        raise ValueError('Token file must be owned by current user and chmod 600')
    token = p.read_text().strip()
    if not token or '\n' in token or '\r' in token:
        raise ValueError('Invalid token file')
    return token


def fetch_release(repository, tag, role, token_file, destination):
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise ValueError('Expected owner/repository')
    if not re.fullmatch(r'v[0-9][A-Za-z0-9_.-]*', tag):
        raise ValueError('An explicit version tag is required')
    if role not in ('main', 'node'):
        raise ValueError('Invalid role')
    token = token_from_file(token_file) if token_file else None
    opener = urllib.request.build_opener(SafeRedirect())
    base = 'https://api.github.com/repos/' + repository

    def get(url, binary=False):
        if not url.startswith(base + '/'):
            raise ValueError('Unexpected API asset URL')
        headers = {
            'Accept': 'application/octet-stream' if binary else 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'XCVM-Independent-Installer',
        }
        if token:
            headers['Authorization'] = 'Bearer ' + token
        req = urllib.request.Request(url, headers=headers)
        return opener.open(req, timeout=90)

    with get(base + '/releases/tags/' + urllib.parse.quote(tag, safe='')) as r:
        release = json.load(r)
    if release.get('draft') or release.get('tag_name') != tag:
        raise ValueError('Expected a published pinned release')
    assets = {a['name']: a['url'] for a in release['assets']}
    filename = f'xcvm-independent-{role}-linux-amd64.tar.gz'
    with get(assets['SHA256SUMS'], True) as r:
        lines = r.read(65537)
    if len(lines) > 65536:
        raise ValueError('Oversized checksum manifest')
    sums = {}
    for line in lines.decode('ascii').splitlines():
        digest, name = line.split(None, 1)
        name = name.lstrip('* ')
        if not re.fullmatch(r'[a-f0-9]{64}', digest) or name in sums:
            raise ValueError('Invalid checksum manifest')
        sums[name] = digest
    expected = sums[filename]
    dest = pathlib.Path(destination)
    dest.mkdir(parents=True, exist_ok=True)
    target = dest / filename
    partial = dest / (filename + '.part')
    h = hashlib.sha256()
    try:
        with get(assets[filename], True) as r, partial.open('xb') as out:
            while chunk := r.read(1024 * 1024):
                h.update(chunk)
                out.write(chunk)
        if h.hexdigest() != expected:
            raise ValueError('Release checksum mismatch')
        partial.replace(target)
    finally:
        partial.unlink(missing_ok=True)
    return target


if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--repository', default='mena50056/xcvm-independent')
    p.add_argument('--tag', required=True)
    p.add_argument('--role', choices=['main', 'node'], required=True)
    p.add_argument('--token-file', help='Optional: private repositories only')
    p.add_argument('--destination', required=True)
    a = p.parse_args()
    try:
        print(fetch_release(a.repository, a.tag, a.role, a.token_file, a.destination))
    except Exception as e:
        # Do not include request headers or secret-bearing exceptions in logs.
        raise SystemExit('Release download failed: ' + type(e).__name__)
