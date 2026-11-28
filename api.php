<?php
// GET: current state of all plugs plus monthly usage, as JSON. GET ?view=snmp: the SNMP tab.
// POST {"name": "pc", "on": false}: switches one plug. The work happens in lib.php.
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
header('Content-Type: application/json');
if (!$loggedIn) { http_response_code(401); exit('{"error":"login"}'); }
session_write_close();

if (($_GET['view'] ?? '') === 'snmp') exit(smarthome_snmp());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A custom header cannot be sent cross-site without a CORS preflight, which this page never allows.
    if (($_SERVER['HTTP_X_SMARTHOME'] ?? '') !== '1') { http_response_code(403); exit('{"error":"forbidden"}'); }
    $in = json_decode((string) file_get_contents('php://input'), true);
    try {
        smarthome_schalten($in['name'] ?? null, $in['on'] ?? null);
    } catch (InvalidArgumentException) {
        http_response_code(400); exit('{"error":"bad request"}');
    } catch (Throwable $e) {
        http_response_code(502); exit(json_encode(['error' => $e->getMessage()]));
    }
    exit('{"ok":true}');
}

echo smarthome_status();
