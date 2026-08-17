<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/domains.php';

final class DomainsTest extends TestCase
{
    private const VPS_IP = '203.0.113.10';

    // ── Nome do domínio ───────────────────────────────────────────────────────────

    #[DataProvider('normalisationProvider')]
    public function testDomainInputIsNormalised(string $input, string $expected): void
    {
        self::assertSame($expected, DomainName::normalize($input));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function normalisationProvider(): array
    {
        return [
            'plain' => ['lifeisgoodhere.online', 'lifeisgoodhere.online'],
            'uppercase' => ['LifeIsGoodHere.Online', 'lifeisgoodhere.online'],
            'with scheme' => ['https://lifeisgoodhere.online', 'lifeisgoodhere.online'],
            'with path' => ['https://lifeisgoodhere.online/promo', 'lifeisgoodhere.online'],
            'with query' => ['lifeisgoodhere.online?a=1', 'lifeisgoodhere.online'],
            'trailing dot' => ['lifeisgoodhere.online.', 'lifeisgoodhere.online'],
            'padded' => ['  lifeisgoodhere.online  ', 'lifeisgoodhere.online'],
        ];
    }

    #[DataProvider('validityProvider')]
    public function testDomainValidity(string $domain, bool $valid): void
    {
        self::assertSame($valid, DomainName::isValid($domain));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function validityProvider(): array
    {
        return [
            'simple' => ['lifeisgoodhere.online', true],
            'hyphenated' => ['life-is-good.com', true],
            'subdomain' => ['ytds.life.com', true],
            'no tld' => ['lifeisgoodhere', false],
            'empty' => ['', false],
            'leading hyphen' => ['-bad.com', false],
            'space inside' => ['bad domain.com', false],
            'numeric tld' => ['bad.123', false],
        ];
    }

    public function testSplitMatchesWhatNamecheapExpects(): void
    {
        self::assertSame(['lifeisgoodhere', 'online'], DomainName::split('lifeisgoodhere.online'));
        self::assertSame(['example', 'co.uk'], DomainName::split('example.co.uk'));
    }

    public function testHostnameAlwaysCarriesTheYtdsPrefix(): void
    {
        self::assertSame('ytds.lifeisgoodhere.online', DomainName::hostname('lifeisgoodhere.online'));
    }

    // ── Verificação de DNS (o caminho manual) ─────────────────────────────────────

    public function testManualCheckAcceptsARecordPointingHere(): void
    {
        $step = DomainVerifier::judge([self::VPS_IP], self::VPS_IP, 'ytds.exemplo.com');

        self::assertTrue($step->ok);
        self::assertStringContainsString('points here', $step->message);
    }

    /**
     * The failure an operator is most likely to hit: the record is right but the orange
     * cloud is on, so the TDS would see Cloudflare's IP instead of the visitor's.
     */
    public function testManualCheckExplainsTheProxyBeingOn(): void
    {
        $step = DomainVerifier::judge(['104.21.5.9'], self::VPS_IP, 'ytds.exemplo.com');

        self::assertFalse($step->ok);
        self::assertStringContainsString('proxy', $step->message);
        self::assertStringContainsString('orange cloud', $step->message);
    }

    public function testManualCheckReportsAnUnrelatedAddress(): void
    {
        $step = DomainVerifier::judge(['203.0.113.7'], self::VPS_IP, 'ytds.exemplo.com');

        self::assertFalse($step->ok);
        self::assertStringContainsString('203.0.113.7', $step->message);
        self::assertStringContainsString(self::VPS_IP, $step->message);
    }

    public function testManualCheckDistinguishesNotResolvingYet(): void
    {
        $step = DomainVerifier::judge([], self::VPS_IP, 'ytds.exemplo.com');

        self::assertFalse($step->ok);
        self::assertStringContainsString('does not resolve', $step->message);
    }

    public function testManualCheckAcceptsWhenOneOfSeveralAddressesMatches(): void
    {
        $step = DomainVerifier::judge(['203.0.113.7', self::VPS_IP], self::VPS_IP, 'ytds.exemplo.com');

        self::assertTrue($step->ok);
    }

    // ── Perfil de registrante ─────────────────────────────────────────────────────

    public function testRegistrationIsBlockedUntilTheProfileIsComplete(): void
    {
        $missing = NamecheapDomains::missingProfileFields(['FirstName' => 'Lucas', 'LastName' => 'Vaz']);

        self::assertContains('Address1', $missing);
        self::assertContains('Country', $missing);
        self::assertContains('EmailAddress', $missing);
        self::assertNotContains('FirstName', $missing);
    }

    public function testCompleteProfileLeavesNothingMissing(): void
    {
        self::assertSame([], NamecheapDomains::missingProfileFields($this->profile()));
    }

    /** Money is only spent after this passes, so a blank-but-present field must not slip by. */
    public function testWhitespaceOnlyFieldsCountAsMissing(): void
    {
        $profile = $this->profile();
        $profile['City'] = '   ';

        self::assertSame(['City'], NamecheapDomains::missingProfileFields($profile));
    }

    // ── Catálogo de endereços da conta Namecheap ──────────────────────────────────

    /** The address book calls the postcode Zip; registration wants PostalCode. */
    public function testAccountAddressIsMappedOntoContactFields(): void
    {
        $profile = NamecheapDomains::mapAddressToContact([
            'FirstName' => 'John', 'LastName' => 'Zoidberg',
            'Address1' => 'Planet Express', 'City' => 'New New York',
            'StateProvince' => 'NY', 'Zip' => '10019', 'Country' => 'US',
            'Phone' => '+1.5417543010', 'EmailAddress' => 'zoidberg@futurama.bz',
        ]);

        self::assertSame('10019', $profile['PostalCode']);
        self::assertSame('John', $profile['FirstName']);
        self::assertSame([], NamecheapDomains::missingProfileFields($profile));
    }

    public function testAccountAddressAcceptsEitherNameForThePostcodeAndEmail(): void
    {
        $withAlternates = NamecheapDomains::mapAddressToContact([
            'FirstName' => 'A', 'LastName' => 'B', 'Address1' => 'C', 'City' => 'D',
            'StateProvince' => 'E', 'PostalCode' => '99999', 'Country' => 'BR',
            'Phone' => '+55.11999999999', 'Email' => 'a@b.com',
        ]);

        self::assertSame('99999', $withAlternates['PostalCode']);
        self::assertSame('a@b.com', $withAlternates['EmailAddress']);
    }

    public function testIncompleteAccountAddressIsReportedRatherThanSentAsIs(): void
    {
        $profile = NamecheapDomains::mapAddressToContact(['FirstName' => 'John', 'LastName' => 'Zoidberg']);

        self::assertNotSame([], NamecheapDomains::missingProfileFields($profile));
        self::assertContains('Country', NamecheapDomains::missingProfileFields($profile));
    }

    /**
     * Captured from the live API on 06/08/2026. The container is AddressGetListResult,
     * not AddressList: guessing that name made every lookup report an empty address book
     * and pushed the operator towards typing contacts by hand.
     */
    public function testAddressListIsReadFromTheContainerNamecheapActuallySends(): void
    {
        $rows = NamecheapDomains::rows(NamecheapDomains::parse($this->realAddressListXml())['xml'], 'AddressGetListResult');

        self::assertCount(1, $rows);
        self::assertSame('0', $rows[0]['AddressId']);
        self::assertSame('Primary Address', $rows[0]['AddressName']);
        self::assertSame('true', $rows[0]['IsDefault']);
    }

    /** AddressId zero is a real id; treating it as empty would drop the default entry. */
    public function testAddressIdZeroIsAcceptedAsAnIdentifier(): void
    {
        $rows = NamecheapDomains::rows(NamecheapDomains::parse($this->realAddressListXml())['xml'], 'AddressGetListResult');

        self::assertNotSame('', $rows[0]['AddressId']);
        self::assertSame('0', $rows[0]['AddressId']);
    }

    private function realAddressListXml(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<ApiResponse Status="OK" xmlns="' . NamecheapIntegration::XML_NAMESPACE . '">'
            . '<Errors /><Warnings />'
            . '<RequestedCommand>namecheap.users.address.getlist</RequestedCommand>'
            . '<CommandResponse Type="namecheap.users.address.getList">'
            . '<AddressGetListResult>'
            . '<List AddressId="0" AddressName="Primary Address" IsDefault="true" />'
            . '</AddressGetListResult>'
            . '</CommandResponse></ApiResponse>';
    }

    public function testAddressInfoIsReadFromChildElements(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<ApiResponse Status="OK" xmlns="' . NamecheapIntegration::XML_NAMESPACE . '">'
            . '<CommandResponse Type="namecheap.users.address.getInfo"><GetAddressInfoResult>'
            . '<AddressId>0</AddressId><AddressName>Primary Address</AddressName>'
            . '<Default_YN>true</Default_YN>'
            . '<FirstName>John</FirstName><LastName>Zoidberg</LastName>'
            . '<Address1>Planet Express</Address1><City>New New York</City>'
            . '<StateProvince>NY</StateProvince><StateProvinceChoice>P</StateProvinceChoice>'
            . '<Zip>10019</Zip><Country>US</Country><Phone>+1.5417543010</Phone>'
            . '<PhoneExt /><EmailAddress>zoidberg@futurama.bz</EmailAddress>'
            . '</GetAddressInfoResult></CommandResponse></ApiResponse>';

        $fields = NamecheapDomains::fields(NamecheapDomains::parse($xml)['xml'], 'GetAddressInfoResult');

        self::assertSame('John', $fields['FirstName']);
        self::assertSame('10019', $fields['Zip']);
        self::assertSame('0', $fields['AddressId']);

        // The whole point of reading it: it must fill a registration with nothing missing.
        self::assertSame([], NamecheapDomains::missingProfileFields(NamecheapDomains::mapAddressToContact($fields)));
    }

    // ── Respostas das APIs ────────────────────────────────────────────────────────

    public function testNamecheapErrorsAreReadDespiteTheHttp200(): void
    {
        $result = NamecheapDomains::parse(
            '<?xml version="1.0"?><ApiResponse Status="ERROR" xmlns="' . NamecheapIntegration::XML_NAMESPACE . '">'
            . '<Errors><Error Number="2019166">Domain not found</Error></Errors></ApiResponse>'
        );

        self::assertFalse($result['ok']);
        self::assertSame('nc_2019166', $result['code']);
        self::assertSame('Domain not found', $result['message']);
    }

    public function testCloudflareFailureCarriesItsOwnMessage(): void
    {
        $result = CloudflareDomains::parse(json_encode([
            'success' => false,
            'errors' => [['code' => 1061, 'message' => 'Zone already exists']],
        ]));

        self::assertFalse($result['ok']);
        self::assertSame('Zone already exists', $result['message']);
    }

    public function testCloudflareSuccessExposesTheResult(): void
    {
        $result = CloudflareDomains::parse(json_encode([
            'success' => true,
            'errors' => [],
            'result' => ['id' => 'zone123', 'status' => 'pending', 'name_servers' => ['a.ns.cloudflare.com', 'b.ns.cloudflare.com']],
        ]));

        self::assertTrue($result['ok']);
        self::assertSame('zone123', $result['result']['id']);
        self::assertCount(2, $result['result']['name_servers']);
    }

    // ── Lista de domínios ─────────────────────────────────────────────────────────

    public function testAddingADomainStoresItsHostname(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'registered', 'zone1', 1786000000);

        self::assertCount(1, $list);
        self::assertSame('exemplo.com', $list[0]['name']);
        self::assertSame('ytds.exemplo.com', $list[0]['hostname']);
        self::assertSame('registered', $list[0]['source']);
    }

    /** Re-running a check must not duplicate the entry nor reset when it was added. */
    public function testReAddingUpdatesInPlaceAndKeepsTheOriginalDate(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'manual', '', 1786000000);
        $list = DomainRegistry::put($list, 'EXEMPLO.com', 'cloudflare', 'zone9', 1786999999);

        self::assertCount(1, $list);
        self::assertSame('cloudflare', $list[0]['source']);
        self::assertSame('zone9', $list[0]['zone_id']);
        self::assertSame(1786000000, $list[0]['added']);
    }

    public function testRemovingIsCaseInsensitive(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'manual', '', 1786000000);

        self::assertSame([], DomainRegistry::remove($list, 'EXEMPLO.COM'));
    }

    public function testTheStoredListSurvivesBeingReadBack(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'manual', '', 1786000000);

        self::assertSame($list, DomainRegistry::all(['managedDomains' => $list]));
        self::assertSame([], DomainRegistry::all([]));
        self::assertSame([], DomainRegistry::all(['managedDomains' => 'not a list']));
    }

    // ── Status e retomada ─────────────────────────────────────────────────────────

    public function testANewEntryStartsAsChecking(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'registered', '', 1786000000);

        self::assertSame(DomainStatus::CHECKING, $list[0]['status']);
    }

    public function testStatusAndDetailAreStoredForTheBadge(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'registered', 'z1', 1786000000, DomainStatus::ERROR, 'token lacks zone.create');

        self::assertSame(DomainStatus::ERROR, $list[0]['status']);
        self::assertSame('token lacks zone.create', $list[0]['detail']);
    }

    /**
     * A later step that does not know the zone id must not erase the one already found,
     * or the next resume would create a second zone.
     */
    public function testKnownZoneIdSurvivesAnUpdateThatLacksIt(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'registered', 'zone-abc', 1786000000);
        $list = DomainRegistry::put($list, 'exemplo.com', 'registered', '', 1786000100, DomainStatus::CHECKING);

        self::assertSame('zone-abc', $list[0]['zone_id']);
    }

    public function testAFailedOutcomeStillCarriesThePurchase(): void
    {
        $outcome = new DomainOutcome();
        $outcome->registered = true;
        $outcome->fail('Cloudflare refused');

        // What the endpoint keys off when deciding to store the domain.
        self::assertTrue($outcome->registered);
        self::assertFalse($outcome->ok);
        self::assertSame(DomainStatus::ERROR, $outcome->status);
    }

    /** Waiting on Cloudflare is not an error; it must keep the spinner, not go red. */
    public function testWaitingForActivationStaysInChecking(): void
    {
        $outcome = new DomainOutcome();
        $outcome->fail('zone not active yet', DomainStatus::CHECKING);

        self::assertSame(DomainStatus::CHECKING, $outcome->status);
        self::assertFalse($outcome->ok);
    }

    public function testSuccessMarksTheDomainReady(): void
    {
        $outcome = new DomainOutcome();
        $outcome->succeed('ytds.exemplo.com', 'ready');

        self::assertSame(DomainStatus::READY, $outcome->status);
        self::assertTrue($outcome->ok);
    }

    public function testOutcomeIsSerialisedWithEverythingThePageNeeds(): void
    {
        $outcome = new DomainOutcome();
        $outcome->registered = true;
        $outcome->zoneId = 'z9';
        $outcome->succeed('ytds.exemplo.com', 'done');

        $encoded = $outcome->jsonSerialize();
        foreach (['ok', 'hostname', 'message', 'status', 'registered', 'zone_id', 'steps'] as $key) {
            self::assertArrayHasKey($key, $encoded);
        }
        self::assertSame('z9', $encoded['zone_id']);
    }

    // ── Publicação no nginx ───────────────────────────────────────────────────────

    /** DNS resolving here is not the same as the host being served. */
    public function testAHostWithNoProvisioningRecordIsNotReady(): void
    {
        $step = DomainProvisioner::statusFor([], 'ytds.exemplo.com');

        self::assertFalse($step->ok);
        self::assertStringContainsString('Waiting for the server', $step->message);
    }

    public function testAPublishedHostIsReported(): void
    {
        $step = DomainProvisioner::statusFor(
            ['ytds.exemplo.com' => ['ok' => true, 'checked' => 1786000000]],
            'ytds.exemplo.com'
        );

        self::assertTrue($step->ok);
        self::assertStringContainsString('HTTPS', $step->message);
    }

    public function testATemporaryFailureSaysItWillRetry(): void
    {
        $step = DomainProvisioner::statusFor(
            ['ytds.exemplo.com' => ['ok' => false, 'attempts' => 1, 'message' => 'certbot failed.']],
            'ytds.exemplo.com'
        );

        self::assertFalse($step->ok);
        self::assertStringContainsString('Retrying', $step->message);
        self::assertArrayNotHasKey('exhausted', $step->details);
    }

    /**
     * Let's Encrypt caps issuance per week, so retries stop and the row turns red rather
     * than spinning forever against a limit.
     */
    public function testRepeatedFailuresStopRetrying(): void
    {
        $step = DomainProvisioner::statusFor(
            ['ytds.exemplo.com' => ['ok' => false, 'attempts' => DomainProvisioner::MAX_ATTEMPTS, 'message' => 'certbot failed.']],
            'ytds.exemplo.com'
        );

        self::assertFalse($step->ok);
        self::assertTrue($step->details['exhausted']);
        self::assertStringContainsString('Giving up', $step->message);
    }

    public function testProvisioningStateSurvivesAWriteAndRead(): void
    {
        $root = sys_get_temp_dir() . '/ytds_prov_' . bin2hex(random_bytes(4));
        mkdir($root . '/tmp', 0777, true);

        DomainProvisioner::write($root, ['ytds.exemplo.com' => ['ok' => true, 'checked' => 1786000000]]);
        $state = DomainProvisioner::read($root);

        self::assertTrue($state['ytds.exemplo.com']['ok']);

        @unlink(DomainProvisioner::statePath($root));
        @rmdir($root . '/tmp');
        @rmdir($root);
    }

    public function testUnreadableProvisioningStateIsTreatedAsEmpty(): void
    {
        self::assertSame([], DomainProvisioner::read('/nao/existe/em/lugar/nenhum'));
    }

    // ── A régua única de "ready" ───────────────────────────────────────────────────

    /** Not resolving yet is a matter of waiting, so the row keeps spinning. */
    public function testFinalizeKeepsCheckingWhileDnsHasNotPropagated(): void
    {
        $outcome = domains_finalize(new DomainOutcome(), 'naoexiste-ytds-teste.invalid', self::VPS_IP, sys_get_temp_dir());

        self::assertFalse($outcome->ok);
        self::assertSame(DomainStatus::CHECKING, $outcome->status);
    }

    /**
     * Every path now ends here. Before this, importing from Cloudflare and pointing by
     * hand called a domain ready on DNS alone, while the host was still landing on the
     * default nginx site with no certificate.
     */
    public function testFinalizeWithoutPublishingIsNotReady(): void
    {
        $root = sys_get_temp_dir() . '/ytds_fin_' . bin2hex(random_bytes(4));
        mkdir($root . '/tmp', 0777, true);
        DomainProvisioner::write($root, []);

        $step = DomainProvisioner::statusFor(DomainProvisioner::read($root), 'ytds.exemplo.com');

        self::assertFalse($step->ok);

        @unlink(DomainProvisioner::statePath($root));
        @rmdir($root . '/tmp');
        @rmdir($root);
    }

    /** The campaign a domain routes to must outlive every status refresh. */
    public function testCampaignLinkSurvivesAStatusUpdate(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'registered', 'z1', 1786000000);
        $list[0]['campaign_id'] = 7;

        $list = DomainRegistry::put($list, 'exemplo.com', 'registered', 'z1', 1786000100, DomainStatus::READY, 'ok');

        self::assertSame(7, $list[0]['campaign_id']);
        self::assertSame(DomainStatus::READY, $list[0]['status']);
    }

    public function testANewEntryStartsUnattached(): void
    {
        $list = DomainRegistry::put([], 'exemplo.com', 'registered', '', 1786000000);

        self::assertSame(0, $list[0]['campaign_id']);
    }

    /** @return array<string, string> */
    private function profile(): array
    {
        return [
            'FirstName' => 'Lucas',
            'LastName' => 'Vaz',
            'Address1' => 'Rua Um, 100',
            'City' => 'Sao Paulo',
            'StateProvince' => 'SP',
            'PostalCode' => '01000-000',
            'Country' => 'BR',
            'Phone' => '+55.11999999999',
            'EmailAddress' => 'lucas@example.com',
        ];
    }
}
