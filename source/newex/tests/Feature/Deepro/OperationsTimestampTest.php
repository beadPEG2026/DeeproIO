<?php
namespace Tests\Feature\Deepro;

use App\Services\Operations\TimestampPresentation;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

final class OperationsTimestampTest extends TestCase
{
    public function test_database_local_times_use_the_application_timezone_and_dst(): void
    {
        config(['app.timezone'=>'Europe/Madrid']);
        $result=TimestampPresentation::normalize([
            'summer'=>['last_success_at'=>'2026-09-29 16:04:32'],
            'winter'=>['last_success_at'=>'2026-01-29 16:04:32'],
            'checked_at'=>'2026-09-29T14:04:32Z',
            'due_at'=>'2026-09-29 16:04:32+02',
            'label'=>'2026-09-29 16:04:32','invalid_at'=>'not a date','empty_at'=>null,
        ]);
        $this->assertSame('2026-09-29T14:04:32.000000Z',$result['summer']['last_success_at']);
        $this->assertSame('2026-01-29T15:04:32.000000Z',$result['winter']['last_success_at']);
        $this->assertSame($result['checked_at'],$result['due_at']);
        $this->assertSame('2026-09-29 16:04:32',$result['label']);
        $this->assertSame('not a date',$result['invalid_at']);$this->assertNull($result['empty_at']);
    }

    public function test_pagination_and_source_records_remain_unchanged(): void
    {
        config(['app.timezone'=>'Europe/Madrid']);
        $row=(object)['id'=>1,'created_at'=>'2026-09-29 16:04:32'];
        $page=new LengthAwarePaginator(collect([$row]),41,15,2,['path'=>'/operations/assets']);
        $result=TimestampPresentation::normalize(['items'=>$page]);
        $this->assertSame(41,$result['items']->total());$this->assertSame(2,$result['items']->currentPage());
        $this->assertSame('/operations/assets?page=1',$result['items']->previousPageUrl());
        $this->assertSame('2026-09-29T14:04:32.000000Z',$result['items']->items()[0]->created_at);
        $this->assertSame('2026-09-29 16:04:32',$row->created_at);
        $this->assertSame($row,$page->items()[0]);
    }
}
