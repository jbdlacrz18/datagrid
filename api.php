<?php
/**
 * RPC + file-download endpoint.
 *   POST (JSON { fn, args })  -> dispatch to a whitelisted backend function
 *                                 -> { ok:true, result } | { ok:false, error }
 *   GET  ?dl=<fileId>         -> stream a stored attachment (auth required)
 * Auth is the signed cookie (see auth.php); there are no server sessions.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/functions.php';

/* ---- Attachment download (GET ?dl=id) ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['dl'])) {
    try {
        init_db();
        if (current_user_email() === '') { http_response_code(403); exit('Sign in to view this file.'); }
        $f = get_file((string) $_GET['dl']);
        if (!$f) { http_response_code(404); exit('File not found.'); }
        $bytes = base64_decode((string) ($f['data_b64'] ?? ''));
        header('Content-Type: ' . (($f['mime'] ?? '') ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) $f['name']) . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'Error';
    }
    exit;
}

/* ---- JSON RPC ---- */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Functions the client is allowed to call. */
const RPC_ALLOWED = [
    'getIdentity',
    'getInitialData', 'apiListTables', 'apiCreateTable', 'apiRenameTable', 'apiDeleteTable', 'apiSetTableAccessMode',
    'apiSetScheduleNote', 'apiSetCurrency',
    'apiAddColumn', 'apiUpdateColumn', 'apiDeleteColumn', 'apiReorderColumns', 'apiSetColumnWidth', 'apiLinkOptions',
    'apiAddRow', 'apiUpdateRow', 'apiUpdateCell', 'apiImportRows', 'apiUploadFile',
    'apiBulkSetField', 'apiBulkDeleteRows', 'apiDeleteRow',
    'apiListTrash', 'apiRestoreRow', 'apiPurgeRow', 'apiEmptyTrash',
    'apiListViews', 'apiSaveView', 'apiDeleteView',
    'apiListRoles', 'apiSetRole', 'apiRemoveRole', 'apiGetAuditLog',
];

function fail(string $msg): void
{
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Server error.']);
    }
});

try {
    init_db();
    $req = json_decode(file_get_contents('php://input'), true);
    if (!is_array($req)) fail('Malformed request.');

    $fn = (string) ($req['fn'] ?? '');
    $args = $req['args'] ?? [];
    if (!is_array($args)) $args = [];

    if ($fn === '' || !in_array($fn, RPC_ALLOWED, true) || !function_exists($fn)) {
        fail('Unknown action: ' . $fn);
    }

    echo json_encode(['ok' => true, 'result' => call_user_func_array($fn, array_values($args))]);
} catch (\Throwable $e) {
    fail($e->getMessage());
}
