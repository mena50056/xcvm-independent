"""Exercise real signed S3 HTTP delivery, streaming read-back and failed-copy retention."""
import hashlib,hmac,json,pathlib,re,subprocess,tempfile,threading,unittest,urllib.parse,xml.sax.saxutils
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
ROOT=pathlib.Path(__file__).resolve().parents[1]
class S3(BaseHTTPRequestHandler):
    objects={};corrupt=False;fail=False;unsigned=0;deletes=0;page_size=0
    def log_message(self,*args):pass
    def answer(self,code,body=b''):
        self.send_response(code);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def work(self):
        body=self.rfile.read(int(self.headers.get('Content-Length',0)));url=urllib.parse.urlsplit(self.path)
        auth=self.headers.get('Authorization','');match=re.fullmatch(r'AWS4-HMAC-SHA256 Credential=AK/([^,]+), SignedHeaders=([^,]+), Signature=([a-f0-9]+)',auth)
        if not match:self.__class__.unsigned+=1;return self.answer(403)
        scope,names,sig=match.groups();date,region,service,last=scope.split('/')
        headers=''.join(n+':'+self.headers[n].strip()+'\n' for n in names.split(';'))
        payload=self.headers['x-amz-content-sha256'];canonical='\n'.join([self.command,url.path,'&'.join(sorted(url.query.split('&'))),headers,names,payload]);tosign='\n'.join(['AWS4-HMAC-SHA256',self.headers['x-amz-date'],scope,hashlib.sha256(canonical.encode()).hexdigest()])
        key=b'AWS4SK'
        for part in [date,region,service,last]:key=hmac.new(key,part.encode(),hashlib.sha256).digest()
        if not hmac.compare_digest(sig,hmac.new(key,tosign.encode(),hashlib.sha256).hexdigest()) or payload!=hashlib.sha256(body).hexdigest():self.__class__.unsigned+=1;return self.answer(403)
        key=urllib.parse.unquote(url.path.removeprefix('/b-1/'));query=urllib.parse.parse_qs(url.query)
        if self.command=='PUT':
            if self.fail:return self.answer(503)
            self.objects[key]=body;return self.answer(200)
        if self.command=='DELETE':self.__class__.deletes+=1;self.objects.pop(key,None);return self.answer(204)
        if 'list-type' in query:
            prefix=query.get('prefix',[''])[0];keys=[k for k in sorted(self.objects) if k.startswith(prefix)];offset=int(query.get('continuation-token',['0'])[0]);end=offset+self.page_size if self.page_size else len(keys);token='<NextContinuationToken>'+str(end)+'</NextContinuationToken>' if end<len(keys) else '';listing='<ListBucketResult>'+''.join('<Contents><Key>'+xml.sax.saxutils.escape(k)+'</Key></Contents>' for k in keys[offset:end])+'<IsTruncated>'+('true' if token else 'false')+'</IsTruncated>'+token+'</ListBucketResult>'
            return self.answer(200,listing.encode())
        if key not in self.objects:return self.answer(404)
        return self.answer(200,self.objects[key]+(b'tamper' if self.corrupt else b''))
    do_GET=do_PUT=do_DELETE=work
class Delivery(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server=ThreadingHTTPServer(('127.0.0.1',0),S3);cls.thread=threading.Thread(target=cls.server.serve_forever,daemon=True);cls.thread.start()
    @classmethod
    def tearDownClass(cls):cls.server.shutdown();cls.server.server_close();cls.thread.join()
    def run_php(self,script):
        with tempfile.TemporaryDirectory(prefix='horus-s3-') as folder:
            f=pathlib.Path(folder);target={'id':1,'type':'s3','keep':1,'config':{'endpoint':'http://127.0.0.1:'+str(self.server.server_port),'region':'us-east-1','bucket':'b-1','prefix':'horus','access_key':'AK','secret_key':'SK','path_style':'1'}}
            (f/'target.json').write_text(json.dumps(target));(f/'run.php').write_text('<?php require '+json.dumps(str(ROOT/'panel/src/vendor/autoload.php'))+';use XcVm\\Domain\\Backup\\{BackupTargets,BackupArchive,OffsiteBackup};$t=json_decode(file_get_contents(__DIR__."/target.json"),true);$file=__DIR__."/source.sql";file_put_contents($file,str_repeat("-- SQL content\\n",10000));'+script)
            result=subprocess.run(['php',str(f/'run.php')],capture_output=True,text=True,timeout=90)
            self.assertEqual(result.returncode,0,result.stdout+result.stderr);return result.stdout
    def setUp(self):S3.objects={};S3.corrupt=False;S3.fail=False;S3.deletes=0;S3.unsigned=0;S3.page_size=0
    def test_encrypted_round_trip_and_remote_retention(self):
        output=self.run_php('''$pass="test-only-long-recovery-passphrase";BackupArchive::encrypt($file,$file.".horus",$pass);
foreach(["backup_2026-01-01.sql.horus","backup_2026-01-02.sql.horus"] as $name){if(BackupTargets::upload($t,$file.".horus",$name)!==null||BackupTargets::verify($t,$file.".horus",$name)!==null)throw new Exception("upload/read back");}
if(BackupTargets::download($t,"backup_2026-01-02.sql.horus",__DIR__."/remote.horus")!==null)throw new Exception("download");BackupArchive::decrypt(__DIR__."/remote.horus",__DIR__."/restored.sql",$pass);if(hash_file("sha256",$file)!==hash_file("sha256",__DIR__."/restored.sql"))throw new Exception("restore");
if(BackupTargets::prune($t)!==1||count(BackupTargets::listing($t))!==1)throw new Exception("retention");if(BackupTargets::test($t)!==null)throw new Exception("target test");echo "verified";''')
        self.assertEqual(output,'verified');self.assertEqual(S3.unsigned,0);self.assertEqual(len(S3.objects),1);self.assertTrue(all(b'-- SQL content' not in v for v in S3.objects.values()))
    def test_corrupt_read_back_never_prunes(self):
        S3.corrupt=True
        output=self.run_php('''$rows=OffsiteBackup::deliver(["backup_2026-01-03.sql.horus"=>$file],[$t],[BackupTargets::class,"upload"],[BackupTargets::class,"verify"],[BackupTargets::class,"prune"]);echo $rows[0]["state"];''')
        self.assertEqual(output,'failed');self.assertEqual(S3.deletes,0);self.assertEqual(S3.unsigned,0)
    def test_upload_failure_never_prunes(self):
        S3.fail=True
        output=self.run_php('''$rows=OffsiteBackup::deliver(["backup_2026-01-03.sql.horus"=>$file],[$t],[BackupTargets::class,"upload"],[BackupTargets::class,"verify"],[BackupTargets::class,"prune"]);echo $rows[0]["state"];''')
        self.assertEqual(output,'failed');self.assertEqual(S3.deletes,0);self.assertEqual(S3.objects,{})
    def test_current_export_survives_retention_with_future_dated_objects(self):
        S3.objects={'horus/backup_2099-01-01.sql.horus':b'future-dated copy'}
        output=self.run_php('''if(BackupTargets::upload($t,$file,"backup_2026-01-01.sql.horus")!==null)throw new Exception("upload");BackupTargets::prune($t,["backup_2026-01-01.sql.horus"]);echo implode(",",BackupTargets::listing($t));''')
        self.assertIn('backup_2026-01-01.sql.horus',output);self.assertEqual(S3.deletes,0)
    def test_paginated_list_uses_sorted_signature_query(self):
        S3.objects={'horus/backup_'+str(i)+'.sql.horus':b'copy' for i in range(3)};S3.page_size=1
        output=self.run_php('echo count(BackupTargets::listing($t));');self.assertEqual(output,'3');self.assertEqual(S3.unsigned,0)
if __name__=='__main__':unittest.main()
