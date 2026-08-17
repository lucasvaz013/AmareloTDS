<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../networks.php';

global $db;
$networks = $db->get_common_settings()['networks'] ?? [];
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php'; ?>
<link rel="stylesheet" href="<?=get_admin_base_url()?>css/networks.css?v=<?=filemtime(__DIR__ . '/css/networks.css')?>">
<body class="networks-page">
<?php include __DIR__ . '/header.php'; ?>

<main class="all-content-wrapper networks-content">
    <div class="networks-intro">
        <h1>Networks</h1>
        <p>
            Register the query parameters each traffic or affiliate network expects, once. A
            destination then just picks its network and inherits these parameters — you never look
            up the network's docs again while building a campaign.
        </p>
        <p class="networks-hint">
            Write each parameter as <code>name={macro}</code>, joined with <code>&amp;</code> — e.g.
            <code>subid={clickid}&amp;subid2={c.campaignname}</code>. Only a bare macro as the whole
            value resolves; a parameter filled by the traffic source is <code>{c.NAME}</code>.
        </p>
    </div>

    <div id="networksAlert" class="networks-alert" hidden></div>

    <div class="networks-table-head">
        <span>Name</span>
        <span>Parameters</span>
        <span></span>
    </div>
    <div id="networksRows" class="networks-rows">
        <?php foreach ($networks as $n) { if (!is_array($n)) continue; ?>
            <div class="network-row" data-id="<?= htmlspecialchars((string)($n['id'] ?? ''), ENT_QUOTES) ?>">
                <input type="text" class="form-control network-name" maxlength="64"
                       value="<?= htmlspecialchars((string)($n['name'] ?? ''), ENT_QUOTES) ?>"
                       placeholder="BuyGoods" />
                <input type="text" class="form-control network-params" maxlength="1024"
                       value="<?= htmlspecialchars((string)($n['params'] ?? ''), ENT_QUOTES) ?>"
                       placeholder="subid={clickid}&subid2={c.campaignname}" />
                <button type="button" class="btn btn-danger campaign-icon-btn network-remove" title="Remove"><i class="bi bi-trash"></i></button>
            </div>
        <?php } ?>
    </div>

    <div class="networks-actions">
        <button type="button" id="networksAdd" class="btn btn-outline-info"><i class="bi bi-plus-lg"></i> Add network</button>
        <button type="button" id="networksSave" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
    </div>
</main>

<template id="tpl-network-row">
    <div class="network-row" data-id="">
        <input type="text" class="form-control network-name" maxlength="64" placeholder="BuyGoods" />
        <input type="text" class="form-control network-params" maxlength="1024" placeholder="subid={clickid}&subid2={c.campaignname}" />
        <button type="button" class="btn btn-danger campaign-icon-btn network-remove" title="Remove"><i class="bi bi-trash"></i></button>
    </div>
</template>

<script id="networksConfig" type="application/json"><?= json_encode([
    'endpoint' => get_admin_base_url() . 'networkseditor.php',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?=get_admin_base_url()?>js/networks.js?v=<?=filemtime(__DIR__ . '/js/networks.js')?>"></script>
</body>
</html>
