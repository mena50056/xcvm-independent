"""PHP bytecode and static compression profile for the independent MAIN."""
import pathlib
import re

BEGIN = '; BEGIN HORUS OPCACHE'
END = '; END HORUS OPCACHE'


def php_ini(text, extension):
    extension = str(pathlib.Path(extension))
    if any(c in extension for c in ['"', '\n', '\r']):
        raise ValueError('Invalid OPcache extension path')
    text = re.sub(re.escape(BEGIN) + r'.*?' + re.escape(END) + r'\n?', '', text, flags=re.S)
    # A Zend extension must be loaded only once, even when upgrading an old profile.
    text = re.sub(r'^\s*zend_extension\s*=\s*[^\n]*opcache[^\n]*\n?', '', text, flags=re.M | re.I)
    return text.rstrip() + f'''

{BEGIN}
[opcache]
zend_extension="{extension}"
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.jit=off
opcache.jit_buffer_size=0
{END}
'''


def nginx_static_location():
    # Only public assets: never cache/compress subscriber streams or admin responses here.
    return '''location ^~ /horus_xc/assets/ {
    proxy_pass http://127.0.0.1:18080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Real-IP $remote_addr;
    gzip on;
    gzip_vary on;
    gzip_comp_level 5;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json image/svg+xml;
}
'''
