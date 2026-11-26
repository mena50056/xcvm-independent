"""Exercise real HTTP validators and the public-asset boundary without a database."""
import http.client
import json
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import unittest


class StaticAssetTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='horus-assets-')
        cls.root = pathlib.Path(cls.tmp.name)
        cls.assets = cls.root / 'assets'
        cls.assets.mkdir()
        cls.css = cls.assets / 'theme.css'
        cls.css.write_text('body{color:red}')
        (cls.assets / 'private.php').write_text('<?php echo "secret";')
        (cls.assets / 'secret.ini').write_text('secret=hidden')
        (cls.root / 'outside.css').write_text('private')
        try:
            (cls.assets / 'linked.css').symlink_to(cls.root / 'outside.css')
        except OSError:
            pass
        handler = pathlib.Path(__file__).resolve().parents[1] / 'runtime/StaticAssets.php'
        (cls.root / 'router.php').write_text('<?php require ' + json.dumps(str(handler)) + '; '
            "HorusStaticAssets::serve(__DIR__.'/assets', (string)($_GET['path'] ?? 'theme.css'));")
        sock = socket.socket()
        sock.bind(('127.0.0.1', 0))
        cls.port = sock.getsockname()[1]
        sock.close()
        cls.server = subprocess.Popen([os.environ.get('PHP_BIN', 'php'), '-n', '-S',
            '127.0.0.1:' + str(cls.port), str(cls.root / 'router.php')],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', cls.port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            cls.tearDownClass()
            raise RuntimeError('Test PHP server did not start')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(timeout=10)
        cls.tmp.cleanup()

    def request(self, query='', headers=None, method='GET'):
        connection = http.client.HTTPConnection('127.0.0.1', self.port, timeout=5)
        connection.request(method, '/?' + query, headers=headers or {})
        response = connection.getresponse()
        result = response.status, dict(response.getheaders()), response.read()
        connection.close()
        return result

    def test_conditional_get_and_head(self):
        status, h, body = self.request()
        self.assertEqual(status, 200)
        self.assertIn(b'color:red', body)
        self.assertEqual(h['Cache-Control'], 'public, no-cache')
        for headers in [{'If-None-Match': h['ETag']}, {'If-None-Match': 'W/' + h['ETag']},
                        {'If-None-Match': '"other", ' + h['ETag']}, {'If-Modified-Since': h['Last-Modified']}]:
            status, cached, body = self.request(headers=headers)
            self.assertEqual((status, body), (304, b''))
            self.assertEqual(cached['ETag'], h['ETag'])
        status, head, body = self.request(method='HEAD')
        self.assertEqual((status, body), (200, b''))
        self.assertEqual(head['Content-Length'], h['Content-Length'])

    def test_etag_precedence_and_changes_with_same_size_and_timestamp(self):
        _, h, _ = self.request()
        mtime = self.css.stat().st_mtime
        self.css.write_text('body{color:tan}')
        os.utime(self.css, (mtime, mtime))
        try:
            status, changed, body = self.request(headers={'If-None-Match': h['ETag'],
                'If-Modified-Since': h['Last-Modified']})
            self.assertEqual(status, 200)
            self.assertIn(b'color:tan', body)
            self.assertNotEqual(changed['ETag'], h['ETag'])
        finally:
            self.css.write_text('body{color:red}')
            os.utime(self.css, (mtime, mtime))

    def test_only_matching_versions_are_immutable(self):
        version = str(int(self.css.stat().st_mtime))
        self.assertIn('immutable', self.request('v=' + version)[1]['Cache-Control'])
        for query in ['v=old', 'v[]=123']:
            self.assertEqual(self.request(query)[1]['Cache-Control'], 'public, no-cache')

    def test_source_traversal_and_symlinks_cannot_be_read(self):
        for path in ['private.php', 'secret.ini', '../outside.css', '%2e%2e%2foutside.css', '%00.css', 'missing.css']:
            status, h, body = self.request('path=' + path)
            self.assertEqual(status, 404, path)
            self.assertEqual(h['Cache-Control'], 'no-store')
            self.assertNotIn(b'secret', body)
        if (self.assets / 'linked.css').is_symlink():
            self.assertEqual(self.request('path=linked.css')[0], 404)

    def test_post_never_serves_or_caches_assets(self):
        status, h, body = self.request(method='POST')
        self.assertEqual((status, body), (405, b''))
        self.assertEqual(h['Cache-Control'], 'no-store')
        self.assertEqual(h['Allow'], 'GET, HEAD')


if __name__ == '__main__':
    unittest.main()
