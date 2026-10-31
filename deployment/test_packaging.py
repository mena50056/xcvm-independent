import io
import json
import pathlib
import sys
import tarfile
import tempfile
import unittest
import urllib.request
import hashlib
from unittest.mock import patch

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from fetch_release import SafeRedirect, token_from_file, fetch_release
from install import validate_archive


class PackagingTests(unittest.TestCase):
    def test_public_download_without_credentials(self):
        payload=b'test release payload'
        filename='xcvm-independent-main-linux-amd64.tar.gz'
        base='https://api.github.com/repos/owner/repo'
        manifest=hashlib.sha256(payload).hexdigest()+'  '+filename+'\n'
        requests=[]
        class PublicOpener:
            def open(self, req, timeout):
                requests.append(req)
                if '/releases/tags/' in req.full_url:
                    return io.BytesIO(json.dumps({'tag_name':'v1.0','draft':False,'assets':[
                        {'name':'SHA256SUMS','url':base+'/releases/assets/1'},
                        {'name':filename,'url':base+'/releases/assets/2'}]}).encode())
                return io.BytesIO(manifest.encode() if req.full_url.endswith('/1') else payload)
        with tempfile.TemporaryDirectory() as temp, patch('fetch_release.urllib.request.build_opener',return_value=PublicOpener()), patch('fetch_release.token_from_file',side_effect=AssertionError('must not read credentials')):
            result=fetch_release('owner/repo','v1.0','main',None,temp)
            self.assertEqual(result.read_bytes(),payload)
        self.assertEqual(len(requests),3)
        self.assertTrue(all(req.get_header('Authorization') is None for req in requests))

    def archive(self, name, kind=tarfile.REGTYPE, link=''):
        memory = io.BytesIO()
        with tarfile.open(fileobj=memory, mode='w') as a:
            m = tarfile.TarInfo(name)
            m.type = kind
            m.linkname = link
            a.addfile(m, io.BytesIO())
        memory.seek(0)
        return tarfile.open(fileobj=memory)

    def test_regular_source_allowed(self):
        with self.archive('panel/src/bootstrap.php') as a:
            validate_archive(a)

    def test_path_traversal_and_absolute_rejected(self):
        for name in ('../etc/passwd', '/etc/passwd', 'panel/../../etc/shadow', 'panel\\src\\evil'):
            with self.subTest(name=name), self.archive(name) as a:
                with self.assertRaises(ValueError):
                    validate_archive(a)

    def test_links_and_devices_rejected(self):
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE):
            with self.subTest(kind=kind), self.archive('panel/escape', kind, '/etc') as a:
                with self.assertRaises(ValueError):
                    validate_archive(a)

    def test_original_extension_rejected(self):
        for name in ('panel/src/bin/php/xcvm_core.so', 'panel/src/bin/php/ioncube_loader.so'):
            with self.archive(name) as a:
                with self.assertRaises(ValueError):
                    validate_archive(a)

    def test_credentials_removed_on_cross_host_redirect(self):
        req = urllib.request.Request('https://api.github.com/repos/owner/repo/releases/assets/1', headers={'Authorization': 'Bearer test-only'})
        result = SafeRedirect().redirect_request(req, None, 302, 'Found', {}, 'https://release-assets.githubusercontent.com/example')
        self.assertIsNone(result.get_header('Authorization'))

    def test_non_tls_redirect_rejected(self):
        req = urllib.request.Request('https://api.github.com/repos/owner/repo')
        with self.assertRaises(ValueError):
            SafeRedirect().redirect_request(req, None, 302, 'Found', {}, 'http://example.com/')

    def test_empty_token_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            path = pathlib.Path(temp) / 'token'
            path.write_text('')
            path.chmod(0o600)
            with self.assertRaises(ValueError):
                token_from_file(path)


if __name__ == '__main__':
    unittest.main()
