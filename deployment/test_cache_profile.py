import pathlib
import unittest
from cache_profile import php_ini, nginx_static_location


class CacheProfileTests(unittest.TestCase):
    def test_reapplying_preserves_other_settings_and_loads_one_extension(self):
        original = '[PHP]\nmemory_limit=512M\nextension=redis.so\nzend_extension=opcache.so\n'
        configured = php_ini(original, '/opt/horus_xc/opcache.so')
        self.assertEqual(configured, php_ini(configured, '/opt/horus_xc/opcache.so'))
        self.assertEqual(configured.count('zend_extension='), 1)
        self.assertIn('extension=redis.so', configured)
        self.assertIn('opcache.validate_timestamps=1', configured)
        self.assertIn('opcache.enable_cli=1', configured)

    def test_rejects_ini_injection(self):
        with self.assertRaises(ValueError):
            php_ini('', '/bad"\nextension=other.so')

    def test_static_compression_is_scoped_and_installer_uses_profile(self):
        location = nginx_static_location()
        self.assertIn('location ^~ /horus_xc/assets/', location)
        self.assertNotIn('proxy_cache', location)
        installer = pathlib.Path(__file__).with_name('configure.py').read_text()
        self.assertIn("php_ini(ini, extensions / 'opcache.so')", installer)
        self.assertIn('{nginx_static_location()}', installer)


if __name__ == '__main__':
    unittest.main()
