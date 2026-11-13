<?php
if (PHP_SAPI !== 'cli' || extension_loaded('curl')) {
    fwrite(STDERR, "Run with php -n -d extension=simplexml; no real SMS transport is allowed.\n");
    exit(1);
}
foreach (['TEXT_MODULE_CLICKSEND_TITLE', 'TEXT_ERROR', 'TEXT_USERNAME', 'TEXT_MODULE_CLICKSEND_USERNAME_INFO', 'TEXT_EXT_API_KEY', 'TEXT_MODULE_CLICKSEND_API_KEY_INFO',
    'CURLOPT_HEADER', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_TIMEOUT'] as $name) define($name, $name);
class modules
{
    static function get_configuration($fields, $id) { return ['username' => 'fixture', 'key' => 'fixture']; }
}
class SmsTestAlerts
{
    public $errors = [];
    function add($message, $type) { $this->errors[] = $message; }
}
$alerts = new SmsTestAlerts();
$response = false;
function curl_init($url) { return 1; }
function curl_setopt($handle, $option, $value) { return true; }
function curl_exec($handle) { global $response; return $response; }
function curl_close($handle) {}
require __DIR__ . '/native-clicksend-workflow.php';
$provider = new clicksend();
$checks = 0;
function smsCheck($condition) {
    global $checks;
    if (!$condition) throw new RuntimeException('SMS transport check failed.');
    $checks++;
}
$response = '<response><messages><message><errortext>Success</errortext><result>0000</result></message></messages></response>';
smsCheck($provider->send(3, ['+351912345678'], 'Fixture') === true);
smsCheck($alerts->errors === []);
$response = '<response><messages><message><errortext>Rejected</errortext><result>1000</result></message></messages></response>';
smsCheck($provider->send(3, ['+351912345678'], 'Fixture') === false);
smsCheck(count($alerts->errors) === 1);
$response = false;
smsCheck($provider->send(3, ['+351912345678'], 'Fixture') === false);
smsCheck(count($alerts->errors) === 2);
$response = '<response/>';
smsCheck($provider->send(3, ['+351912345678'], 'Fixture') === false);
smsCheck($provider->send(3, [], 'Fixture') === false);
echo "$checks SMS acknowledgment checks passed; no network transport loaded.\n";
