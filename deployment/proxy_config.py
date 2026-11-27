"""Render a closed set of proxy options into nginx; never accept nginx fragments."""
import hashlib
import ipaddress
import os
import pathlib
import re

LIMITS = {'connection_limit': (1,100000), 'per_ip_connections':(0,5000),
          'requests_per_second':(0,1000), 'request_burst':(1,10000),
          'worker_connections':(512,65536), 'worker_processes':(0,64),
          'connect_timeout':(1,30), 'read_timeout':(15,3600), 'send_timeout':(5,600),
          'keepalive_timeout':(5,120), 'header_timeout':(5,60), 'client_body_mb':(1,32)}


def host(value):
    if not isinstance(value,str) or len(value)>253:
        raise ValueError('INVALID_HOST')
    try:
        return str(ipaddress.ip_address(value))
    except ValueError:
        if not re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*',value):
            raise ValueError('INVALID_HOST')
        return value


def validate(wire):
    if wire.get('schema')!=1 or not re.fullmatch('[0-9a-f]{64}',str(wire.get('revision',''))):
        raise ValueError('INVALID_PROFILE')
    p=wire['profile']
    for name,(low,high) in LIMITS.items():
        if type(p.get(name)) is not int or not low<=p[name]<=high:
            raise ValueError('INVALID_LIMIT: '+name)
    ipaddress.ip_address(p['server_ip'])
    if p.get('outbound_bind_ip'):
        ipaddress.ip_address(p['outbound_bind_ip'])
    ports=[]
    for name in ['http_ports','https_ports']:
        values=p[name]
        if not isinstance(values,list) or len(values)>32 or any(type(v) is not int or not 1<=v<=65535 for v in values):
            raise ValueError('INVALID_PORTS')
        ports+=values
    if not ports or len(ports)!=len(set(ports)):
        raise ValueError('OVERLAPPING_PORTS')
    for name in ['enabled','allow_ip_access','reject_unknown_hosts','allow_admin','http2']:
        if type(p.get(name)) is not bool:raise ValueError('INVALID_BOOLEAN')
    parents=wire['parents']
    if not 1<=len(parents)<=32 or len({n['id'] for n in parents})!=len(parents):
        raise ValueError('INVALID_PARENTS')
    ids={n['id'] for n in parents}
    if p['default_parent_id'] not in ids:
        raise ValueError('INVALID_DEFAULT_PARENT')
    for n in parents:
        if type(n['id']) is not int or n['id']<=0 or type(n['port']) is not int or not 1<=n['port']<=65535:
            raise ValueError('INVALID_PARENT')
        ipaddress.ip_address(n['ip']);host(n['tls_name'])
        if not re.fullmatch('[a-f0-9]{32}',n['route']):
            raise ValueError('INVALID_ROUTE')
    if not isinstance(p['hosts'],list) or len(p['hosts'])>64:
        raise ValueError('INVALID_HOSTS')
    names=set()
    for row in p['hosts']:
        name=host(row['host'])
        if name in names or row['parent_id'] not in ids|{0} or not re.fullmatch('[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}',row['certificate']):
            raise ValueError('INVALID_HOST_ROUTE')
        names.add(name)
    if not p['allow_ip_access'] and not names:
        raise ValueError('HOST_REQUIRED')
    if len(p.get('trusted_proxies',[]))>32:
        raise ValueError('INVALID_TRUSTED_PROXIES')
    for item in p.get('trusted_proxies',[]):
        network=ipaddress.ip_network(item,strict=False)
        if network.prefixlen<(32 if network.version==6 else 8):
            raise ValueError('INVALID_TRUSTED_PROXIES')
    workers=p['worker_processes'] or min(os.cpu_count() or 1,64)
    if p['connection_limit']*2+128>p['worker_connections']*workers:
        raise ValueError('CONNECTION_LIMIT_EXCEEDS_WORKER_BUDGET')
    return p


def certificate_paths(root, name, alias, revision):
    if alias=='auto':
        alias='auto-'+hashlib.sha256((name+'|'+revision).encode()).hexdigest()[:24]
    return root/'tls'/(alias+'.crt'), root/'tls'/(alias+'.key')


def render(wire,root,worker_user='www-data',effective_revision=None,certificate_generation=None):
    p=validate(wire);root=pathlib.Path(root)
    if not root.is_absolute() or not re.fullmatch(r'/[a-zA-Z0-9_./-]+',str(root)) or '..' in root.parts or not re.fullmatch('[a-z_][a-z0-9_-]*',worker_user):
        raise ValueError('INVALID_RUNTIME_PATH')
    rev=wire['revision'];workers=p['worker_processes'] or min(os.cpu_count() or 1,64)
    effective=effective_revision or rev;generation=certificate_generation or rev
    if not re.fullmatch('[0-9a-f]{64}',effective) or not re.fullmatch('[0-9a-f]{64}',generation):raise ValueError('INVALID_REVISION')
    lines=[f'user {worker_user};',f'worker_processes {workers};',f'worker_rlimit_nofile {p["worker_connections"]*2+1024};',
           f'pid {root}/run/nginx.pid;',f'error_log {root}/logs/error.log warn;',
           f'events {{ worker_connections {p["worker_connections"]}; }}','http {',
           'server_tokens off; access_log off; sendfile on; tcp_nodelay on; gzip off;',
           'server_names_hash_bucket_size 256; server_names_hash_max_size 2048;',
           f'client_body_temp_path {root}/run/body;',f'proxy_temp_path {root}/run/proxy;',
           f'fastcgi_temp_path {root}/run/fastcgi;',f'uwsgi_temp_path {root}/run/uwsgi;',f'scgi_temp_path {root}/run/scgi;',
           f'client_max_body_size {p["client_body_mb"]}m;',f'client_header_timeout {p["header_timeout"]}s;',
           f'client_body_timeout {p["header_timeout"]}s;',f'send_timeout {p["send_timeout"]}s;',
           f'keepalive_timeout {p["keepalive_timeout"]}s; keepalive_requests 1000;',
           'large_client_header_buffers 4 16k; reset_timedout_connection on;',
           'limit_conn_zone $hostname zone=horus_total:1m; limit_conn_zone $binary_remote_addr zone=horus_ip:10m;',
           'limit_conn_status 429; limit_req_status 429;']
    if p['requests_per_second']:
        lines.append(f'limit_req_zone $binary_remote_addr zone=horus_rate:10m rate={p["requests_per_second"]}r/s;')
    for n in wire['parents']:
        address=f'[{n["ip"]}]' if ':' in n['ip'] else n['ip']
        lines.append(f'upstream horus_parent_{n["id"]} {{ server {address}:{n["port"]}; keepalive 64; }}')
        # request_uri preserves escaped credentials and the original query string.
        lines.append(f'map $request_uri $horus_uri_{n["id"]} {{ ~^/{n["route"]}(/.*)$ $1; default $request_uri; }}')
    prefixes='|'.join(n['route'] for n in wire['parents'])
    lines.append('map $uri $horus_deny_admin { default 0; ~^/(?:(?:'+prefixes+')/)?(?:horus_xc|admin|config|bin|horus-proxy)(?:/|$) 1; }')
    lines.append(f'server {{ listen unix:{root}/run/status.sock; location = /health {{ default_type text/plain; return 200 "{effective}"; }} location = /nginx_status {{ stub_status; }} location / {{ return 404; }} }}')

    def listeners(default=False):
        result=[];suffix=' default_server' if default else ''
        for port in p['http_ports']:result.append(f'listen 0.0.0.0:{port}{suffix};')
        for port in p['https_ports']:result.append(f'listen 0.0.0.0:{port} ssl'+(' http2' if p['http2'] else '')+suffix+';')
        if ':' in p['server_ip']:
            for port in p['http_ports']:result.append(f'listen [::]:{port} ipv6only=on{suffix};')
            for port in p['https_ports']:result.append(f'listen [::]:{port} ipv6only=on ssl'+(' http2' if p['http2'] else '')+suffix+';')
        return result

    def certificate(name,alias):
        if not p['https_ports']:return []
        cert,key=certificate_paths(root,name,alias,generation)
        return [f'ssl_certificate {cert};',f'ssl_certificate_key {key};','ssl_protocols TLSv1.2 TLSv1.3; ssl_session_cache shared:HorusTLS:10m; ssl_session_timeout 10m;']

    def location(n,prefix=False):
        uri=f'$horus_uri_{n["id"]}' if prefix else '$request_uri'
        result=['location '+(f'^~ /{n["route"]}/' if prefix else '/')+' {',
                f'proxy_pass https://horus_parent_{n["id"]}{uri};',
                'proxy_http_version 1.1; proxy_set_header Connection "";',
                'proxy_buffering off; proxy_request_buffering on; proxy_cache off; proxy_max_temp_file_size 0;',
                f'proxy_connect_timeout {p["connect_timeout"]}s; proxy_read_timeout {p["read_timeout"]}s; proxy_send_timeout {p["send_timeout"]}s;',
                'proxy_socket_keepalive on; proxy_next_upstream off; proxy_ignore_client_abort off;',
                'proxy_set_header Host $http_host; proxy_set_header X-Real-IP $remote_addr; proxy_set_header X-Forwarded-For $remote_addr;',
                f'proxy_set_header X-IP {p["server_ip"]}; proxy_set_header X-Forwarded-Proto $scheme;',
                'proxy_set_header Accept-Encoding "";',
                f'proxy_ssl_server_name on; proxy_ssl_name {n["tls_name"]}; proxy_ssl_verify on; proxy_ssl_verify_depth 8;',
                f'proxy_ssl_trusted_certificate {root}/tls/upstreams-{rev}.pem;']
        if p.get('outbound_bind_ip'):result.append('proxy_bind '+p['outbound_bind_ip']+';')
        # Rewrite only known parents and configured proxy authorities, never external redirects.
        authorities=set()
        for parent in wire['parents']:
            address=f'[{parent["ip"]}]' if ':' in parent['ip'] else parent['ip']
            authorities.add(address+':'+str(parent['port']))
            if parent['port']==443:authorities.add(address)
            authorities.add(parent['tls_name']+':'+str(parent['port']))
            if parent['port']==443:authorities.add(parent['tls_name'])
        for name in {p['server_ip']}|{row['host'] for row in p['hosts']}:
            authority=f'[{name}]' if ':' in name else name
            for port in p['http_ports']+p['https_ports']:authorities.add(authority+':'+str(port))
            if 80 in p['http_ports'] or 443 in p['https_ports']:authorities.add(authority)
        for authority in sorted(authorities):
            expression=re.escape(authority).replace('\\-','-')
            result.append(f'proxy_redirect ~^https?://{expression}(/.*)$ $scheme://$http_host$1;')
        return result+['}']

    def policies():
        result=[f'limit_conn horus_total {p["connection_limit"]};']
        if p['per_ip_connections']:result.append(f'limit_conn horus_ip {p["per_ip_connections"]};')
        if p['requests_per_second']:result.append(f'limit_req zone=horus_rate burst={p["request_burst"]} nodelay;')
        if p.get('trusted_proxies'):
            result+=['real_ip_header X-Forwarded-For; real_ip_recursive on;']
            result+=['set_real_ip_from '+str(ipaddress.ip_network(value,strict=False))+';' for value in p['trusted_proxies']]
        if not p['allow_admin']:result.append('if ($horus_deny_admin) { return 404; }')
        return result

    def acme():
        # Serve only HTTP-01 challenge files locally; never send them to an upstream.
        return [f'location ^~ /.well-known/acme-challenge/ {{ root {root}/acme/www; default_type text/plain; limit_except GET HEAD {{ deny all; }} try_files $uri =404; }}']

    default_parent=next(n for n in wire['parents'] if n['id']==p['default_parent_id'])
    ip_certificate=next((row['certificate'] for row in p['hosts'] if row['host']==p['server_ip']),'auto')
    # IP clients normally send no TLS SNI: their certificate must also be the default.
    lines+=['server {',*listeners(True),'server_name _;',*certificate(p['server_ip'],ip_certificate)]
    if p['reject_unknown_hosts'] or not p['enabled']:lines+=['return 444;']
    else:
        lines+=policies()+acme()
        for n in wire['parents']:lines+=location(n,True)
        lines+=location(default_parent)
    lines+=['}']
    rows=list(p['hosts'])
    if p['allow_ip_access'] and p['server_ip'] not in {row['host'] for row in rows}:
        rows.append({'host':p['server_ip'],'parent_id':0,'certificate':'auto'})
    for row in rows:
        target=next(n for n in wire['parents'] if n['id']==(row['parent_id'] or p['default_parent_id']))
        lines+=['server {',*listeners(),f'server_name {row["host"]};',*certificate(row['host'],row['certificate'])]
        if not p['enabled']:
            lines+=['return 503;','}'];continue
        lines+=policies()+acme()
        for n in wire['parents']:lines+=location(n,True)
        lines+=location(target)+['}']
    return '\n'.join(lines+['}'])+'\n'
