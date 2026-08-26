<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';

final class CheckoutRoutesPersistenceTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_checkout_' . uniqid('', true) . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign();
        $_SERVER['QUERY_STRING'] = 'campaignname=demo&_ytds_network_id=attacker';
    }

    protected function tearDown(): void
    {
        $_SERVER['QUERY_STRING'] = '';
        $this->db->cleanup();
    }

    public function testRegularAndAtomicInsertsPersistTheSameProtectedSnapshot(): void
    {
        $snapshot = [
            '_ytds_network_id' => 'cp',
            '_ytds_network_name' => 'Cartpanda',
            '_ytds_checkout' => [
                'step' => 0,
                'links' => [[
                    'n' => 1,
                    'destination_id' => 'cp-1',
                    'destination_name' => 'CP 1',
                    'url' => 'https://cp.test/1?cid=CLICK-1',
                ]],
            ],
        ];
        $data = $this->clickData();

        self::assertTrue($this->db->add_black_click('', 'regular', $data, ['landing'], 'Flow', 1, $snapshot));

        $prepared = $this->db->prepare_black_click_for_transaction($data, 1);
        $this->db->immediate_transaction(function (SQLite3 $connection) use ($prepared, $snapshot): void {
            $this->db->insert_black_click_in_transaction(
                $connection,
                '',
                'atomic',
                $prepared,
                ['landing'],
                'Flow',
                1,
                null,
                0,
                time(),
                $snapshot
            );
        });

        $connection = new SQLite3($this->dbPath, SQLITE3_OPEN_READONLY);
        $rows = $connection->query("SELECT clickid, params FROM clicks ORDER BY clickid");
        $seen = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $params = json_decode($row['params'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('cp', $params['_ytds_network_id']);
            self::assertSame('demo', $params['campaignname']);
            self::assertSame($snapshot['_ytds_checkout'], $params['_ytds_checkout']);
            $seen[] = $row['clickid'];
        }
        $connection->close();
        self::assertSame(['atomic', 'regular'], $seen);
    }

    private function clickData(): array
    {
        return [
            'ip' => '127.0.0.1', 'country' => 'US', 'lang' => 'en', 'os' => 'macOS',
            'osver' => '1', 'client' => 'Safari', 'clientver' => '1', 'device' => 'desktop',
            'brand' => '', 'model' => '', 'isp' => 'Example', 'ua' => 'UA', 'cpc' => 0,
        ];
    }
}
