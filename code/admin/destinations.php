<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../destinations.php';

global $db;
$common = $db->get_common_settings();
$networks = $common['networks'] ?? [];
$destinations = $common['destinations'] ?? [];
$networksById = DestinationLibrary::indexNetworks($networks);

/** Renders the <option>s of the network <select>, marking $selectedId. */
function dest_network_options(array $networks, string $selectedId): string
{
    $html = '<option value="">— no network (base URL only) —</option>';
    foreach ($networks as $n) {
        if (!is_array($n)) {
            continue;
        }
        $id = (string)($n['id'] ?? '');
        $name = (string)($n['name'] ?? '');
        if ($id === '') {
            continue;
        }
        $sel = $id === $selectedId ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($id, ENT_QUOTES) . '"' . $sel . '>'
            . htmlspecialchars($name, ENT_QUOTES) . '</option>';
    }
    return $html;
}
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php'; ?>
<link rel="stylesheet" href="<?=get_admin_base_url()?>css/destinations.css?v=<?=filemtime(__DIR__ . '/css/destinations.css')?>">
<body class="destinations-page">
<?php include __DIR__ . '/header.php'; ?>

<main class="all-content-wrapper destinations-content">
    <div class="destinations-intro">
        <h1>Destinations</h1>
        <p>
            Register your affiliate links once. Give a link its network and it inherits that
            network's parameters automatically — when you build a campaign you just pick the
            destination for a <code>{link:N}</code> slot and the ready-made URL is dropped in.
        </p>
        <p class="destinations-hint">
            Manage networks on the <a href="<?=get_admin_base_url()?>networks.php">Networks</a> page.
            Base URLs without a scheme get <code>https://</code> added.
        </p>
    </div>

    <div id="destinationsAlert" class="destinations-alert" hidden></div>

    <div class="destinations-table-head">
        <span>Name</span>
        <span>Affiliate base URL</span>
        <span>Network</span>
        <span></span>
    </div>
    <div id="destinationsRows" class="destinations-rows">
        <?php foreach ($destinations as $d) {
            if (!is_array($d)) continue;
            $dest = Destination::fromArray($d);
            $effective = DestinationLibrary::effectiveUrl($dest, $networksById);
            $dangling = $dest->networkId !== '' && !isset($networksById[$dest->networkId]);
        ?>
            <div class="destination-row" data-id="<?= htmlspecialchars($dest->id, ENT_QUOTES) ?>">
                <div class="destination-fields">
                    <input type="text" class="form-control destination-name" maxlength="64"
                           value="<?= htmlspecialchars($dest->name, ENT_QUOTES) ?>" placeholder="BuyGoods — 1 pote" />
                    <input type="text" class="form-control destination-base" maxlength="1024"
                           value="<?= htmlspecialchars($dest->baseUrl, ENT_QUOTES) ?>" placeholder="afflink.com/order" />
                    <select class="form-control destination-network"><?= dest_network_options($networks, $dest->networkId) ?></select>
                    <button type="button" class="btn btn-danger campaign-icon-btn destination-remove" title="Remove"><i class="bi bi-trash"></i></button>
                </div>
                <div class="destination-preview<?= $dangling ? ' is-dangling' : '' ?>">
                    <span class="destination-preview-label"><?= $dangling ? 'network missing — base only:' : 'effective:' ?></span>
                    <code class="destination-preview-url"><?= htmlspecialchars($effective, ENT_QUOTES) ?></code>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="destinations-actions">
        <button type="button" id="destinationsAdd" class="btn btn-outline-info"><i class="bi bi-plus-lg"></i> Add destination</button>
        <button type="button" id="destinationsSave" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
    </div>
</main>

<template id="tpl-destination-row">
    <div class="destination-row" data-id="">
        <div class="destination-fields">
            <input type="text" class="form-control destination-name" maxlength="64" placeholder="BuyGoods — 1 pote" />
            <input type="text" class="form-control destination-base" maxlength="1024" placeholder="afflink.com/order" />
            <select class="form-control destination-network"><?= dest_network_options($networks, '') ?></select>
            <button type="button" class="btn btn-danger campaign-icon-btn destination-remove" title="Remove"><i class="bi bi-trash"></i></button>
        </div>
        <div class="destination-preview">
            <span class="destination-preview-label">effective:</span>
            <code class="destination-preview-url"></code>
        </div>
    </div>
</template>

<script id="destinationsConfig" type="application/json"><?= json_encode([
    'endpoint' => get_admin_base_url() . 'destinationseditor.php',
    'networks' => array_values(array_map(
        fn(Network $n): array => ['id' => $n->id, 'params' => $n->params],
        $networksById
    )),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?=get_admin_base_url()?>js/destinations.js?v=<?=filemtime(__DIR__ . '/js/destinations.js')?>"></script>
</body>
</html>
