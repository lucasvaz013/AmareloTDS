<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../code/capi.php';

/**
 * Exercises the real HTTP path against the local router instead of Meta, so the request
 * shape is verified without a network call or a live pixel.
 */
final class CapiSendTest extends TestCase
{
    private static $process;
    private static array $pipes = [];
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            self::markTestSkipped("Cannot allocate local port: $error");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int)substr(strrchr($address, ':'), 1);
        self::$baseUrl = "http://127.0.0.1:$port";

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        self::$process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', "127.0.0.1:$port", __DIR__ . '/http_router.php'],
            $descriptors,
            self::$pipes,
            __DIR__
        );
        if (!is_resource(self::$process)) {
            self::markTestSkipped('Cannot start local PHP server');
        }
        fclose(self::$pipes[0]);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        self::tearDownAfterClass();
        self::markTestSkipped('Local PHP server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
        }
    }

    public function testRequestIsPostedAsJsonWithBearerToken(): void
    {
        $payload = $this->post($this->settings());

        self::assertSame('POST', $payload['method']);
        self::assertSame('application/json', $payload['contentType']);
        self::assertSame('Bearer EAAG-secret-token', $payload['authorization']);
    }

    public function testTokenNeverReachesTheUrl(): void
    {
        $payload = $this->post($this->settings());

        // The token lives in a header precisely so the URL stays safe to log.
        self::assertStringNotContainsString('EAAG-secret-token', $payload['path'] . '?' . $payload['query']);
        self::assertStringNotContainsString('access_token', $payload['query']);
    }

    public function testBodyCarriesTheEventInsideADataArray(): void
    {
        $payload = $this->post($this->settings());
        $body = json_decode($payload['body'], true);

        self::assertArrayHasKey('data', $body);
        self::assertCount(1, $body['data']);
        self::assertSame('Purchase', $body['data'][0]['event_name']);
        self::assertSame('website', $body['data'][0]['action_source']);
        self::assertSame('197.00', $body['data'][0]['custom_data']['value']);
        self::assertSame('BRL', $body['data'][0]['custom_data']['currency']);
    }

    public function testFbcSurvivesSerialisationIntact(): void
    {
        $payload = $this->post($this->settings());
        $body = json_decode($payload['body'], true);

        self::assertSame('fb.1.1785966596000.IwAR0PROVA456', $body['data'][0]['user_data']['fbc']);
    }

    public function testTestEventCodeIsSentOnlyWhenConfigured(): void
    {
        $withCode = json_decode($this->post($this->settings('TEST12345'))['body'], true);
        $withoutCode = json_decode($this->post($this->settings())['body'], true);

        self::assertSame('TEST12345', $withCode['test_event_code']);
        self::assertArrayNotHasKey('test_event_code', $withoutCode);
    }

    public function testUnreachableEndpointIsReportedRatherThanThrowing(): void
    {
        $settings = $this->settings();
        $response = $this->sendTo('http://127.0.0.1:1/events', $settings);

        self::assertFalse($response->isOk());
        self::assertNotSame('', $response->error);
    }

    /** @return array<string, mixed> */
    private function post(CapiSettings $settings): array
    {
        $response = $this->sendTo(self::$baseUrl . '/events', $settings);
        self::assertTrue($response->isOk(), 'local router should answer');

        return json_decode((string)$response->content, true);
    }

    private function sendTo(string $url, CapiSettings $settings): HttpResponse
    {
        $payload = ['data' => [$this->event()]];
        if ($settings->testEventCode !== '') {
            $payload['test_event_code'] = $settings->testEventCode;
        }

        return HttpClient::send(new HttpRequest(
            id: 'capi-test',
            url: $url,
            method: 'POST',
            body: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            headers: [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings->accessToken,
            ],
            timeout: 2,
            connectTimeout: 2,
        ));
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        return CapiEventBuilder::build(
            [
                'clickid' => 'CLICK-ABC',
                'userid' => 'user-42',
                'time' => 1785966596,
                'ip' => '203.0.113.9',
                'ua' => 'Mozilla/5.0 (iPhone)',
                'params' => ['fbclid' => 'IwAR0PROVA456'],
            ],
            'Purchase',
            1785970000,
            'https://tds.example/',
            197.0,
            'BRL',
            'ORDER-1'
        );
    }

    private function settings(string $testEventCode = ''): CapiSettings
    {
        return CapiSettings::fromArray([
            'enabled' => true,
            'pixel_id' => '1234567890',
            'access_token' => 'EAAG-secret-token',
            'test_event_code' => $testEventCode,
            'map' => [['status' => 'Purchase', 'event_name' => 'Purchase']],
        ]);
    }
}
