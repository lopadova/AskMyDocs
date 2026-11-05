<?php

declare(strict_types=1);
namespace Tests\Unit\ApiTools;

use App\ApiTools\{BindingResolver,DeterministicOperations};
use PHPUnit\Framework\TestCase;

final class DeterministicOperationsTest extends TestCase
{
    public function test_sort_limit_filter_and_selection_preserve_explicit_data_contracts(): void
    {
        $op=new DeterministicOperations(new BindingResolver);
        $items=[['id'=>3,'score'=>1],['id'=>1,'score'=>2],['id'=>2,'score'=>2]];
        $sorted=$op->execute('sort',['items'=>$items],['path'=>'score','direction'=>'desc']);
        $this->assertSame([1,2,3],array_column($sorted,'id'));
        $this->assertSame([1,2],array_column($op->execute('limit',['items'=>$sorted],['count'=>2]),'id'));
        $this->assertSame([['id'=>3,'score'=>1]],$op->execute('filter',['items'=>$items],['path'=>'score','equals'=>1]));
        $this->assertSame([],$op->execute('select',['value'=>['nested'=>['items'=>[]]]],['path'=>'nested.items']));
        $this->assertSame(['false'=>false,'zero'=>0],$op->execute('compose',['false'=>false,'zero'=>0],[]));
    }
    public function test_key_join_preserves_all_matches_and_missing_matches_as_an_empty_list(): void
    {
        $op=new DeterministicOperations(new BindingResolver);
        $result=$op->execute('join',['left'=>[['ref'=>2],['ref'=>3]],'right'=>[['key'=>2,'a'=>1],['key'=>2,'a'=>2]]],['left_key'=>'ref','right_key'=>'key']);
        $this->assertCount(2,$result[0]['matches']);
        $this->assertSame([],$result[1]['matches']);
        $this->expectException(\DomainException::class);
        $op->execute('limit',['items'=>['not'=>'a list']],[]);
    }
}
