"""Real loopback HTTPS handshakes: trusted IP works; wrong trust/IP are refused."""
import importlib.util
import http.server
import pathlib
import shutil
import ssl
import subprocess
import sys
import tempfile
import threading
import unittest
import urllib.error

spec=importlib.util.spec_from_file_location('trusted_node',pathlib.Path(__file__).with_name('trusted-node.py'))
node=importlib.util.module_from_spec(spec);spec.loader.exec_module(node)


class Login(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200 if self.path=='/horus_xc/login' else 404)
        self.end_headers();self.wfile.write(b'fixture login')
    def log_message(self,*args):
        pass


class NodeTLS(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory();cls.root=pathlib.Path(cls.temp.name)
        binary=shutil.which('openssl')
        if not binary and pathlib.Path('C:/Program Files/Git/usr/bin/openssl.exe').exists():
            binary='C:/Program Files/Git/usr/bin/openssl.exe'
        if not binary:raise RuntimeError('openssl is required for TLS integration tests')
        config=cls.root/'openssl.cnf';config.write_text('[req]\ndistinguished_name=dn\n[dn]\nCN=127.0.0.1\n')
        for name,ip in [('good','127.0.0.1'),('other','127.0.0.2')]:
            subprocess.run([binary,'req','-x509','-newkey','rsa:2048','-nodes','-days','1','-config',str(config),'-subj','/CN='+ip,'-addext','subjectAltName=IP:'+ip,'-keyout',str(cls.root/(name+'.key')),'-out',str(cls.root/(name+'.crt'))],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

    @classmethod
    def tearDownClass(cls):cls.temp.cleanup()

    def server(self,name):
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Login)
        context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(str(self.root/(name+'.crt')),str(self.root/(name+'.key')))
        server.socket=context.wrap_socket(server.socket,server_side=True)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        self.addCleanup(server.server_close);self.addCleanup(server.shutdown)
        return {'main_ip':'127.0.0.1','main_port':server.server_port}

    def test_trusted_main_ip(self):
        node.verify_main(self.server('good'),self.root/'good.crt')

    def test_untrusted_certificate_refused(self):
        with self.assertRaises(urllib.error.URLError):
            node.verify_main(self.server('good'),self.root/'other.crt')

    def test_certificate_for_different_ip_refused(self):
        with self.assertRaises(urllib.error.URLError):
            node.verify_main(self.server('other'),self.root/'other.crt')


if __name__=='__main__':unittest.main()
