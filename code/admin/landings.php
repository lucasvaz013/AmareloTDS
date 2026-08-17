<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php'; ?>
<link rel="stylesheet" href="<?=get_admin_base_url()?>css/fileeditor.css?v=<?=filemtime(__DIR__ . '/css/fileeditor.css')?>">
<link rel="stylesheet" href="<?=get_admin_base_url()?>css/landings.css?v=<?=filemtime(__DIR__ . '/css/landings.css')?>">
<body class="landings-page">
<?php include __DIR__ . '/header.php'; ?>

<main class="all-content-wrapper landings-content">
    <div class="landings-intro">
        <h1>Landings</h1>
        <p>
            Upload and manage the landing folders your campaign steps use. Anything registered
            here shows up in a step's <strong>Add Existing</strong> picker — register a page once
            and reuse it across campaigns, without uploading it again inside each step.
        </p>
    </div>

    <div class="landings-toolbar">
        <button type="button" id="landingsUpload" class="btn btn-primary">
            <i class="bi bi-upload" aria-hidden="true"></i> Upload ZIP
        </button>
        <button type="button" id="landingsRefresh" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh
        </button>
        <span class="landings-count" id="landingsCount"></span>
    </div>

    <div id="landingsAlert" class="landings-alert" hidden></div>

    <div class="landings-table-shell">
        <table class="landings-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Files</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th class="landings-actions-head">Actions</th>
                </tr>
            </thead>
            <tbody id="landingsBody">
                <tr><td colspan="5" class="landings-empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</main>

<script id="landingsConfig" type="application/json"><?= json_encode([
    'endpoint' => get_admin_base_url() . 'landingseditor.php',
    'upload' => get_admin_base_url() . 'zipupload.php',
    'landingFolder' => get_cache_path('landings'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script>window.LANDING_FOLDER = <?= json_encode(get_cache_path('landings')) ?>;window.WHITE_FOLDER = <?= json_encode(get_cache_path('whites')) ?>;</script>
<script src="<?=get_admin_base_url()?>js/cm6/html.min.js"></script>
<script>window.CM6_HTML = cm6;</script>
<script src="<?=get_admin_base_url()?>js/cm6/css.min.js"></script>
<script>window.CM6_CSS = cm6;</script>
<script src="<?=get_admin_base_url()?>js/cm6/javascript.min.js"></script>
<script>window.CM6_JS = cm6;</script>
<script src="<?=get_admin_base_url()?>js/cm6/php.min.js"></script>
<script>window.CM6_PHP = cm6;</script>
<script type="module" src="<?=get_admin_base_url()?>js/fileeditor.js?v=<?=filemtime(__DIR__ . '/js/fileeditor.js')?>"></script>
<script src="<?=get_admin_base_url()?>js/landings.js?v=<?=filemtime(__DIR__ . '/js/landings.js')?>"></script>
</body>
</html>
