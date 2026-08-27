<?php

$parametersModalFacebookRows = [
    ['Campaign name', 'campaignname={{campaign.name}}', '{c.campaignname}'],
    ['Campaign ID', 'campaignid={{campaign.id}}', '{c.campaignid}'],
    ['Ad set name', 'adsetname={{adset.name}}', '{c.adsetname}'],
    ['Ad set ID', 'adsetid={{adset.id}}', '{c.adsetid}'],
    ['Ad name', 'adname={{ad.name}}', '{c.adname}'],
    ['Ad ID', 'adid={{ad.id}}', '{c.adid}'],
    ['Placement', 'placement={{placement}}', '{c.placement}'],
    ['Source platform', 'site_source_name={{site_source_name}}', '{c.site_source_name}'],
];

$parametersModalInternalRows = [
    ['{clickid}', 'Unique AmareloTDS click ID. Use this for Network attribution and postbacks.'],
    ['{userid}', 'Persistent visitor ID assigned by AmareloTDS.'],
    ['{domain}', 'Host that received the campaign request.'],
    ['{time}', 'Current Unix timestamp.'],
    ['{ip}', 'Visitor IP detected for the click.'],
    ['{country}', 'Detected two-letter country code.'],
    ['{lang}', 'Detected visitor language.'],
    ['{os}', 'Detected operating system.'],
    ['{osver}', 'Detected operating-system version.'],
    ['{client}', 'Detected browser or client name.'],
    ['{clientver}', 'Detected browser or client version.'],
    ['{device}', 'Detected device type.'],
    ['{brand}', 'Detected device brand.'],
    ['{model}', 'Detected device model.'],
    ['{isp}', 'Detected internet service provider.'],
    ['{ua}', 'Visitor user-agent string.'],
    ['{c.NAME}', 'Value of the incoming query parameter named NAME, for example {c.utm_campaign}.'],
    ['{hash:MACRO}', 'MD5 hash of another supported macro value, for example {hash:clickid}.'],
    ['{random:MIN-MAX}', 'Random integer in the inclusive range, for example {random:1-10}.'],
];
?>
<div id="parametersModal" class="ywbmodal parameters-modal" aria-labelledby="parametersModalTitle">
    <div class="modal-content">
        <div class="modal-header parameters-modal-header">
            <div>
                <h5 id="parametersModalTitle"><i class="bi bi-braces"></i> Parameters</h5>
                <p>Reference for traffic-source parameters and AmareloTDS macros.</p>
            </div>
            <button type="button" class="settings-close" id="closeParameters" aria-label="Close">&times;</button>
        </div>

        <div class="parameters-modal-body">
            <section class="parameters-section">
                <div class="parameters-section-heading">
                    <div>
                        <span class="parameters-source-badge">Facebook / Meta</span>
                        <h6>Dynamic URL parameters</h6>
                    </div>
                    <p>
                        Add the middle column to the ad URL parameters. Meta expands the double-brace value
                        before the visitor reaches AmareloTDS; the last column reads that captured value later.
                    </p>
                </div>
                <div class="parameters-table-wrap">
                    <table class="parameters-table">
                        <thead>
                            <tr>
                                <th>Value</th>
                                <th>Configure in Meta ad URL</th>
                                <th>Use in AmareloTDS</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($parametersModalFacebookRows as [$label, $sourceParameter, $amareloMacro]): ?>
                            <tr>
                                <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                                <td><code><?= htmlspecialchars($sourceParameter, ENT_QUOTES) ?></code></td>
                                <td><code><?= htmlspecialchars($amareloMacro, ENT_QUOTES) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="parameters-section">
                <div class="parameters-section-heading">
                    <div>
                        <span class="parameters-source-badge parameters-source-badge-internal">AmareloTDS</span>
                        <h6>URL and Network macros</h6>
                    </div>
                    <p>
                        In Network and redirect URLs, a macro resolves only when it is the whole query-parameter value,
                        such as <code>subid={clickid}</code>. Text such as <code>prefix-{clickid}</code> stays literal.
                    </p>
                </div>
                <div class="parameters-table-wrap">
                    <table class="parameters-table parameters-table-internal">
                        <thead>
                            <tr>
                                <th>Macro</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($parametersModalInternalRows as [$macro, $description]): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($macro, ENT_QUOTES) ?></code></td>
                                <td><?= htmlspecialchars($description, ENT_QUOTES) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="parameters-section parameters-html-note">
                <div class="parameters-section-heading">
                    <div>
                        <span class="parameters-source-badge parameters-source-badge-html">Landing HTML</span>
                        <h6>HTML-only replacements</h6>
                    </div>
                    <p>
                        Raw landing HTML supports <code>{clickid}</code>, <code>{userid}</code>, and <code>{px}</code>.
                        The <code>{px}</code> macro is the visitor cookie value and is not a Network URL macro.
                    </p>
                </div>
            </section>
        </div>

        <div class="modal-footer parameters-modal-footer">
            <span>More traffic-source references can be added here later.</span>
            <button type="button" class="btn btn-primary" id="dismissParameters">Close</button>
        </div>
    </div>
</div>
