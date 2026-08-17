<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';

$integrationsManager = new SettingsManager(dirname(__DIR__));
$integrationsSettings = $integrationsManager->adminPayload($integrationsManager->load());
$integrationsConfigured = $integrationsSettings['_configured'] ?? [];
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php'; ?>
<link rel="stylesheet" href="<?=get_admin_base_url()?>css/integrations.css?v=<?=filemtime(__DIR__ . '/css/integrations.css')?>">
<body class="integrations-page">
<?php include __DIR__ . '/header.php'; ?>

<main class="all-content-wrapper integrations-content">
    <div class="integrations-intro">
        <h1>Integrations</h1>
        <p>
            Store the API credentials AmareloTDS uses to talk to outside services, and check
            that they still work. Nothing is bought, changed or created from this page.
        </p>
        <p class="integrations-egress">
            This server calls out from
            <code id="integrationsPublicIp">checking…</code>
            — the address an external service sees.
        </p>
    </div>

    <div id="integrationsAlert" class="integrations-alert" hidden></div>

    <section class="integration-card" data-integration="cloudflare">
        <header class="integration-card-head">
            <div>
                <h2><i class="bi bi-cloud" aria-hidden="true"></i> Cloudflare</h2>
                <p>Used later to create DNS records pointing at this server.</p>
            </div>
            <span class="integration-state" data-integration-state>checking…</span>
        </header>

        <div class="integration-field">
            <label for="cloudflareApiToken">API token</label>
            <input type="password" id="cloudflareApiToken" name="cloudflareApiToken" autocomplete="off"
                   spellcheck="false" maxlength="512"
                   placeholder="<?= !empty($integrationsConfigured['cloudflareApiToken']) ? 'Saved — leave empty to keep it' : 'Create a token with Zone:DNS:Edit' ?>">
            <p class="integration-hint">
                Use a scoped API token, not the legacy Global API Key. For the DNS feature it
                needs <strong>Zone &gt; DNS &gt; Edit</strong> plus <strong>Zone &gt; Zone &gt; Read</strong>.
            </p>
        </div>

        <p class="integration-result" data-integration-result></p>
    </section>

    <section class="integration-card" data-integration="namecheap">
        <header class="integration-card-head">
            <div>
                <h2><i class="bi bi-cart" aria-hidden="true"></i> Namecheap</h2>
                <p>Used later to register domains without leaving the panel.</p>
            </div>
            <span class="integration-state" data-integration-state>checking…</span>
        </header>

        <div class="integration-field">
            <label for="namecheapApiUser">API user</label>
            <input type="text" id="namecheapApiUser" name="namecheapApiUser" autocomplete="off" spellcheck="false"
                   maxlength="128" value="<?= htmlspecialchars((string)($integrationsSettings['namecheapApiUser'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>

        <div class="integration-field">
            <label for="namecheapUsername">Username</label>
            <input type="text" id="namecheapUsername" name="namecheapUsername" autocomplete="off" spellcheck="false"
                   maxlength="128" placeholder="Same as API user when left empty"
                   value="<?= htmlspecialchars((string)($integrationsSettings['namecheapUsername'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>

        <div class="integration-field">
            <label for="namecheapApiKey">API key</label>
            <input type="password" id="namecheapApiKey" name="namecheapApiKey" autocomplete="off" spellcheck="false"
                   maxlength="512"
                   placeholder="<?= !empty($integrationsConfigured['namecheapApiKey']) ? 'Saved — leave empty to keep it' : 'From Profile > Tools > Namecheap API Access' ?>">
        </div>

        <div class="integration-field integration-field-inline">
            <label class="ywb-radio-label">
                <input type="checkbox" id="namecheapSandbox" name="namecheapSandbox"
                       <?= !empty($integrationsSettings['namecheapSandbox']) ? 'checked' : '' ?>>
                Use the sandbox environment
            </label>
        </div>

        <p class="integration-hint">
            Namecheap only answers callers whose IPv4 address is whitelisted on the account,
            under <strong>Profile &gt; Tools &gt; Namecheap API Access</strong>. Whitelist the
            address shown at the top of this page. Sandbox and production keep separate
            whitelists.
        </p>

        <p class="integration-result" data-integration-result></p>
    </section>

    <div class="integrations-actions">
        <button type="button" id="integrationsVerify" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Check now
        </button>
        <button type="button" id="integrationsSave" class="btn btn-primary">
            <i class="bi bi-save" aria-hidden="true"></i> Save and check
        </button>
    </div>
</main>

<script id="integrationsConfig" type="application/json"><?= json_encode([
    'endpoint' => get_admin_base_url() . 'integrationseditor.php',
    'configured' => $integrationsConfigured,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?=get_admin_base_url()?>js/integrations.js?v=<?=filemtime(__DIR__ . '/js/integrations.js')?>"></script>
</body>
</html>
