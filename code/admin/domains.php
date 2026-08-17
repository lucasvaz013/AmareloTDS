<?php

require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/../domains.php';

$domainsManager = new SettingsManager(dirname(__DIR__));
$domainsSettings = $domainsManager->load();
$registrant = is_array($domainsSettings['registrantProfile'] ?? null) ? $domainsSettings['registrantProfile'] : [];

$registrantFields = [
    'FirstName' => 'First name',
    'LastName' => 'Last name',
    'Address1' => 'Address',
    'City' => 'City',
    'StateProvince' => 'State / province',
    'PostalCode' => 'Postal code',
    'Country' => 'Country (2 letters)',
    'Phone' => 'Phone (+55.11999999999)',
    'EmailAddress' => 'Email',
];
?>
<!doctype html>
<html lang="en">
<?php include __DIR__ . '/head.php'; ?>
<link rel="stylesheet" href="<?=get_admin_base_url()?>css/domains.css?v=<?=filemtime(__DIR__ . '/css/domains.css')?>">
<body class="domains-page">
<?php include __DIR__ . '/header.php'; ?>

<main class="all-content-wrapper domains-content">
    <div class="domains-intro">
        <h1>Domains</h1>
        <p>
            Register a domain or bring one you already own. Either way it ends up as
            <code id="domainsPrefixSample">ytds.yourdomain.com</code> pointing at this server,
            ready to paste into a campaign.
        </p>
        <p class="domains-egress">
            This server answers on <code id="domainsPublicIp">checking…</code>.
        </p>
    </div>

    <div id="domainsAlert" class="domains-alert" hidden></div>

    <section class="domain-card">
        <header class="domain-card-head">
            <div>
                <h2><i class="bi bi-cart-plus" aria-hidden="true"></i> Register a new domain</h2>
                <p>Buys at Namecheap, moves DNS to Cloudflare and creates the record — in one go.</p>
            </div>
            <span class="domain-state" id="registerState">checking…</span>
        </header>

        <div class="domain-blocked" id="registerBlocked" hidden>
            <i class="bi bi-info-circle settings-help-icon" tabindex="0" id="registerBlockedHelp"
               data-tooltip="Registering needs the Namecheap and Cloudflare integrations active, plus a complete registrant profile."></i>
            <span id="registerBlockedText"></span>
            <a id="registerBlockedLink" href="integrations.php">Open Integrations</a>
        </div>

        <div class="domain-row">
            <input type="text" id="registerDomain" class="domain-input" spellcheck="false" autocomplete="off"
                   placeholder="lifeisgoodhere.online">
            <select id="registerYears" class="domain-years">
                <option value="1">1 year</option>
                <option value="2">2 years</option>
                <option value="3">3 years</option>
            </select>
            <button type="button" id="checkAvailability" class="btn btn-outline-secondary">Check availability</button>
            <button type="button" id="registerDomainBtn" class="btn btn-primary" disabled>Register</button>
        </div>

        <p class="domain-warning">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            Registering charges your Namecheap balance. You will be asked to confirm.
        </p>
        <p class="domain-source" id="registrantSource"></p>
        <p class="domain-result" id="registerResult"></p>
        <ol class="domain-steps" id="registerSteps" hidden></ol>
    </section>

    <section class="domain-card">
        <header class="domain-card-head">
            <div>
                <h2><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Import from Cloudflare</h2>
                <p>For a domain already sitting in the Cloudflare account you connected.</p>
            </div>
            <span class="domain-state" id="syncState">checking…</span>
        </header>

        <div class="domain-row">
            <input type="text" id="syncDomain" class="domain-input" spellcheck="false" autocomplete="off"
                   placeholder="yourdomain.com">
            <span id="syncWrap" class="domain-button-wrap">
                <button type="button" id="syncBtn" class="btn btn-primary" disabled>Sync</button>
            </span>
        </div>

        <p class="domain-result" id="syncResult"></p>
        <ol class="domain-steps" id="syncSteps" hidden></ol>
    </section>

    <section class="domain-card">
        <header class="domain-card-head">
            <div>
                <h2><i class="bi bi-pencil-square" aria-hidden="true"></i> Import manually</h2>
                <p>For a domain whose DNS lives somewhere else.</p>
            </div>
            <button type="button" id="manualToggle" class="btn btn-outline-secondary btn-sm">Manual sync</button>
        </header>

        <div id="manualPanel" hidden>
            <ol class="domain-instructions">
                <li>Open the DNS panel of whoever hosts your domain.</li>
                <li>Create a record of type <strong>A</strong>.</li>
                <li>Name it <code id="manualNameSample">ytds</code> — the full host becomes
                    <code id="manualHostSample">ytds.yourdomain.com</code>.</li>
                <li>Point it at <code id="manualIpSample">this server's address</code>.</li>
                <li>If the provider is Cloudflare, keep the proxy <strong>off</strong> (grey cloud).
                    A proxied record hides the visitor's real IP, which the filters depend on.</li>
                <li>Save, wait a minute, then check below.</li>
            </ol>

            <div class="domain-row">
                <input type="text" id="manualDomain" class="domain-input" spellcheck="false" autocomplete="off"
                       placeholder="yourdomain.com">
                <button type="button" id="manualCheckBtn" class="btn btn-primary">Check</button>
            </div>

            <p class="domain-result" id="manualResult"></p>
        </div>
    </section>

    <section class="domain-card">
        <header class="domain-card-head">
            <div>
                <h2><i class="bi bi-list-ul" aria-hidden="true"></i> Your domains</h2>
                <p>Once a domain is ready, pick it inside a campaign to start routing traffic to it.</p>
            </div>
        </header>
        <div id="domainsEmpty" class="domains-empty">No domains yet.</div>
        <table class="domains-table" id="domainsTable" hidden>
            <thead><tr><th>Hostname</th><th>Domain</th><th>Origin</th><th>Status</th><th></th></tr></thead>
            <tbody id="domainsTableBody"></tbody>
        </table>
    </section>

    <section class="domain-card domain-card-muted" id="registrantCard">
        <header class="domain-card-head">
            <div>
                <h2><i class="bi bi-person-vcard" aria-hidden="true"></i> Registrant profile (fallback)</h2>
                <p>
                    Normally unnecessary: registrations use the contact details already stored
                    in your Namecheap account. Fill this only if that account has none.
                </p>
            </div>
            <button type="button" id="registrantToggle" class="btn btn-outline-secondary btn-sm">Edit</button>
        </header>

        <div id="registrantPanel" hidden>
            <div class="registrant-grid">
                <?php foreach ($registrantFields as $field => $label) { ?>
                <div class="registrant-field">
                    <label for="reg<?= $field ?>"><?= $label ?></label>
                    <input type="text" id="reg<?= $field ?>" data-registrant="<?= $field ?>" autocomplete="off"
                           value="<?= htmlspecialchars((string)($registrant[$field] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
                <?php } ?>
            </div>
            <div class="domain-row domain-row-end">
                <button type="button" id="registrantSave" class="btn btn-primary">Save profile</button>
            </div>
        </div>
    </section>
</main>

<script id="domainsConfig" type="application/json"><?= json_encode([
    'endpoint' => get_admin_base_url() . 'domainseditor.php',
    'prefix' => YTDS_HOSTNAME_PREFIX,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?=get_admin_base_url()?>js/domains.js?v=<?=filemtime(__DIR__ . '/js/domains.js')?>"></script>
</body>
</html>
