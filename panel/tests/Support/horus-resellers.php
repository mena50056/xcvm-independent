<?php
require __DIR__ . '/../../src/vendor/autoload.php';
use XcVm\Domain\User\UserCredits;
function resellerCheck($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$res = UserCredits::packageOverrides(['override_3' => '1.75', 'override_4' => '0', 'override_5' => '', 'username' => 'unchanged']);
resellerCheck($res[3]['official_credits'] === 1.75, 'Fractional override survives');
resellerCheck($res[4]['official_credits'] === 0.0, 'Zero is an explicit override');
resellerCheck(!isset($res[5]), 'Blank removes the override');
foreach (['-1', 'NaN', 'not-a-price', [], ['2'], INF] as $invalid) resellerCheck(UserCredits::packageOverrides(['override_3' => $invalid]) === null, 'Invalid prices are rejected');
resellerCheck(UserCredits::packageOverrides(['override_3' => '2.12345'])[3]['official_credits'] === 2.1235, 'Database precision');
echo "PASS: fractional overrides, zero, blank removal, invalid prices and decimal precision\n";
