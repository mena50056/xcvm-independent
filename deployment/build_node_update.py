"""Create a native-only LB update from a previously verified node bundle."""
import argparse
import hashlib
import io
import json
import pathlib
import tarfile
import node_update as node
import update_worker as common


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--source-payload',type=pathlib.Path,required=True)
    parser.add_argument('--output',type=pathlib.Path,required=True)
    parser.add_argument('--version',required=True)
    parser.add_argument('--from-version',action='append',required=True)
    parser.add_argument('--vendor-isolation',action='store_true')
    args=parser.parse_args()
    if any(common.version(args.version)<=common.version(value) for value in args.from_version):
        raise ValueError('Native updates must advance the distribution version')
    args.output.mkdir(parents=True,exist_ok=True)
    files={}
    with tarfile.open(args.source_payload) as source:
        for name in node.FILES:
            member=source.getmember(name)
            if not member.isfile():raise ValueError('Expected native regular binary')
            content=source.extractfile(member).read()
            if content[:4]!=b'\x7fELF':raise ValueError('Expected Linux native binary')
            files[name]=content
    payload=args.output/(node.POLICY_PAYLOAD if args.vendor_isolation else node.PAYLOAD)
    with tarfile.open(payload,'w:gz') as archive:
        for name,content in files.items():
            member=tarfile.TarInfo(name);member.mode=0o755;member.size=len(content)
            archive.addfile(member,io.BytesIO(content))
    data={'schema':1,'repository':common.REPOSITORY,'role':'node','kind':'native-only','version':args.version,
          'from_versions':args.from_version,'database_migrations':[],
          'files':{name:hashlib.sha256(content).hexdigest() for name,content in files.items()},
          'sha256':hashlib.sha256(payload.read_bytes()).hexdigest()}
    if args.vendor_isolation:
        data.update(schema=2,kind='native-with-vendor-isolation',vendor_isolation=True)
    node.validate(data)
    (args.output/(node.POLICY_MANIFEST if args.vendor_isolation else node.MANIFEST)).write_text(json.dumps(data,indent=2)+'\n')
    print('Built native-only LB update',args.version,payload.stat().st_size,'bytes')


if __name__=='__main__':main()
