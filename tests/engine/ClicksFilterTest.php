<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/../../code/adminops.php';

/** Advanced clicks filtering (filter rules, param columns, search, sort, pagination) through AdminOps. */
final class ClicksFilterTest extends TestCase
{
    private TestDb $db;
    private string $dbPath;
    private AdminOps $ops;
    private int $now;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/amarelotds_clk_' . uniqid() . '.db';
        $this->db = new TestDb($this->dbPath);
        $this->db->initSchema();
        $this->db->seedCampaign(1, 'C', []);
        $this->now = time();
        $this->db->seedClicks([
            ['campaign_id' => 1, 'time' => $this->now - 10, 'country' => 'US', 'device' => 'desktop', 'clickid' => 'ab1', 'userid' => 'u1', 'payout' => 5, 'params' => '{"subid":"s1","aff":"x"}'],
            ['campaign_id' => 1, 'time' => $this->now - 20, 'country' => 'BR', 'device' => 'mobile', 'clickid' => 'ab2', 'userid' => 'u2', 'payout' => 3, 'params' => '{"subid":"s2"}'],
            ['campaign_id' => 1, 'time' => $this->now - 30, 'country' => 'US', 'device' => 'mobile', 'clickid' => 'cd3', 'userid' => 'u3', 'payout' => 9, 'params' => '{"subid":"s3"}'],
            ['campaign_id' => 1, 'time' => $this->now - 40, 'country' => 'US', 'device' => 'desktop', 'clickid' => 'ef4', 'userid' => 'u4', 'payout' => 1, 'params' => '{"subid":"s4"}'],
        ]);
        $this->ops = new AdminOps($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->cleanup();
    }

    /** @param array<string, mixed> $opts */
    private function clicks(array $opts): array
    {
        return $this->ops->clicks(1, 'allowed', null, null, 50, false, $opts);
    }

    public function testNoFilterReturnsAll(): void
    {
        $this->assertSame(4, $this->clicks([])['count']);
    }

    public function testEqualsFilterOnColumn(): void
    {
        $this->assertSame(3, $this->clicks(['filter' => ['country:=:US']])['count']);
        $this->assertSame(2, $this->clicks(['filter' => ['device:=:mobile']])['count']);
    }

    public function testMultipleFiltersAndCondition(): void
    {
        // AND (default): US and mobile => only cd3
        $this->assertSame(1, $this->clicks(['filter' => ['country:=:US', 'device:=:mobile']])['count']);
        // OR: BR or desktop => ab2 (BR), ab1, ef4 (desktop) => 3
        $this->assertSame(3, $this->clicks(['filter' => ['country:=:BR', 'device:=:desktop'], 'filter-cond' => 'OR'])['count']);
    }

    public function testInOperator(): void
    {
        $this->assertSame(4, $this->clicks(['filter' => ['device:in:mobile,desktop']])['count']);
        $this->assertSame(1, $this->clicks(['filter' => ['country:not_in:US']])['count']);
    }

    public function testParamColumnProjectedIntoNarrowRows(): void
    {
        $rows = $this->clicks(['param' => ['subid']])['clicks'];
        $this->assertArrayHasKey('param.subid', $rows[0]);
        $this->assertContains($rows[0]['param.subid'], ['s1', 's2', 's3', 's4']);
    }

    public function testFilterByParamField(): void
    {
        $res = $this->clicks(['filter' => ['param.subid:=:s3']]);
        $this->assertSame(1, $res['count']);
    }

    public function testSearchMatchesClickidSubstring(): void
    {
        // ab1, ab2 contain "ab"
        $this->assertSame(2, $this->clicks(['search' => 'ab'])['count']);
    }

    public function testSortByColumn(): void
    {
        $rows = $this->clicks(['sort' => 'payout', 'dir' => 'asc', 'param' => ['subid']])['clicks'];
        $this->assertSame('s4', $rows[0]['param.subid'], 'lowest payout first');
        $this->assertSame(1.0, (float)$rows[0]['payout']);
    }

    public function testSortByParamField(): void
    {
        $rows = $this->clicks(['sort' => 'param.subid', 'dir' => 'desc', 'param' => ['subid']])['clicks'];
        $this->assertSame('s4', $rows[0]['param.subid']);
    }

    public function testPagination(): void
    {
        $page1 = $this->ops->clicks(1, 'allowed', null, null, 2, false, ['page' => '1']);
        $this->assertSame(2, $page1['count']);
        $this->assertSame(2, $page1['last_page']);
        $page2 = $this->ops->clicks(1, 'allowed', null, null, 2, false, ['page' => '2']);
        $this->assertSame(2, $page2['count']);
    }

    public function testResponseEchoesFilterMeta(): void
    {
        $res = $this->clicks(['sort' => 'payout', 'dir' => 'asc', 'filter' => ['country:=:US'], 'param' => ['subid']]);
        $this->assertSame('payout', $res['sort']);
        $this->assertSame('asc', $res['dir']);
        $this->assertSame(['subid'], $res['param_columns']);
        $this->assertSame([['field' => 'country', 'operator' => '=', 'value' => 'US']], $res['filters']);
    }

    public function testUnsafeParamKeyIsDropped(): void
    {
        $res = $this->clicks(['param' => ['bad key!', 'subid']]);
        $this->assertSame(['subid'], $res['param_columns']);
    }
}
