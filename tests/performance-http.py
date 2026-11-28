"""Opt-in, read-only HTTP load check for an isolated loopback QA panel.

Never point this at a tunnel to production. Credentials and response bodies are
not included in the report. No viewers, streams or configuration are changed.
"""
import argparse
import concurrent.futures
import http.cookiejar
import json
import math
import pathlib
import statistics
import threading
import time
import urllib.parse
import urllib.request


def bounded_integer(low, high):
    def parse(value):
        number = int(value)
        if not low <= number <= high:
            raise argparse.ArgumentTypeError(f"Choose {low}..{high}")
        return number
    return parse


def qa_base(value):
    url = urllib.parse.urlsplit(value)
    if (url.scheme != 'http' or url.hostname not in ('127.0.0.1', 'localhost')
            or url.username or url.password or url.query or url.fragment or not url.port):
        raise argparse.ArgumentTypeError('An explicit HTTP loopback QA port is required')
    return value.rstrip('/') + '/'


class QaRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, base):
        super().__init__()
        self.base = urllib.parse.urlsplit(base)

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        target = urllib.parse.urlsplit(newurl)
        if ((target.scheme, target.hostname, target.port)
                != (self.base.scheme, self.base.hostname, self.base.port)
                or not target.path.startswith(self.base.path)):
            raise RuntimeError('Redirect outside the QA panel refused')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def table(kind, start=0, search=''):
    return 'table?' + urllib.parse.urlencode({
        'id': kind, 'draw': 1, 'start': start, 'length': 25,
        'search[value]': search, 'order[0][column]': 2,
        'order[0][dir]': 'asc', 'columns[0][data]': 'id',
    })


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', type=qa_base, default='http://127.0.0.1:18877/horus_xc/')
    parser.add_argument('--credentials-file', required=True, type=pathlib.Path,
                        help='Private JSON file containing username and password')
    parser.add_argument('--confirm-isolated-qa', required=True, action='store_true')
    parser.add_argument('--seconds', type=bounded_integer(1, 120), default=30)
    parser.add_argument('--concurrency', type=bounded_integer(1, 32), default=8)
    parser.add_argument('--output', required=True, type=pathlib.Path)
    args = parser.parse_args()
    credentials = json.loads(args.credentials_file.read_text(encoding='utf-8'))
    clients = []
    for _ in range(args.concurrency):
        opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), QaRedirect(args.url),
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        opener.open(args.url + 'login', timeout=5).read()
        login = urllib.parse.urlencode({
            'username': credentials['username'], 'password': credentials['password'], 'login': 1,
        }).encode()
        reply = opener.open(args.url + 'login', login, timeout=5)
        reply.read()
        if not reply.geturl().endswith('/dashboard'):
            raise RuntimeError('QA login failed')
        clients.append(opener)
    del credentials
    routes = [('dashboard', 'dashboard'), ('settings', 'settings')]
    routes += [('table_' + kind, table(kind)) for kind in ('lines', 'streams', 'movies', 'series', 'reg_users')]
    routes += [('page2_lines', table('lines', 25)), ('search_movies', table('movies', search='HDEMO'))]
    stop = threading.Event()
    started = time.monotonic()
    deadline = started + args.seconds

    def worker(index):
        samples = []
        step = index
        while time.monotonic() < deadline and not stop.is_set():
            name, route = routes[step % len(routes)]
            begin = time.monotonic()
            sample = {'route': name}
            try:
                request = urllib.request.Request(args.url + route, headers={'X-Requested-With': 'XMLHttpRequest'})
                response = clients[index].open(request, timeout=5)
                body = response.read()
                if response.status != 200 or '/login' in response.geturl():
                    raise RuntimeError('Unexpected status or expired session')
                if route.startswith('table?'):
                    data = json.loads(body)
                    if not isinstance(data.get('data'), list) or len(data['data']) > 25 or 'recordsTotal' not in data:
                        raise RuntimeError('Invalid table response')
                elif b'<html' not in body.lower():
                    raise RuntimeError('Invalid page response')
                sample['ok'] = True
            except Exception as error:
                # Exception messages can contain URLs or response data; record only their type.
                sample.update(ok=False, error=type(error).__name__)
                stop.set()
            sample['ms'] = round((time.monotonic() - begin) * 1000, 2)
            samples.append(sample)
            step += 1
            time.sleep(.05)
        return samples

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.concurrency) as pool:
        rows = [row for group in pool.map(worker, range(args.concurrency)) for row in group]
    times = sorted(row['ms'] for row in rows)
    report = {
        'qa_only': True, 'concurrency': args.concurrency,
        'elapsed_seconds': round(time.monotonic() - started, 2),
        'requests': len(rows), 'errors': sum(not row['ok'] for row in rows),
        'median_ms': statistics.median(times), 'p95_ms': times[math.ceil(len(times) * .95) - 1],
        'max_ms': max(times), 'failed_routes': [row for row in rows if not row['ok']],
    }
    args.output.write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(report))
    return int(report['errors'] > 0)


if __name__ == '__main__':
    raise SystemExit(main())
