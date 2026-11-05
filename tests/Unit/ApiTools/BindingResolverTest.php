<?php

declare(strict_types=1);
namespace Tests\Unit\ApiTools;

use App\ApiTools\BindingResolver;
use App\ApiTools\ContentHasher;
use PHPUnit\Framework\TestCase;

final class BindingResolverTest extends TestCase
{
    public function test_nested_fields_preserve_false_zero_null_and_empty_list(): void
    {
        $resolver=new BindingResolver;
        foreach ([false,0,null,[]] as $value) {
            $this->assertSame($value,$resolver->resolve(['source'=>'step','step'=>'order','path'=>'customer.value'],[],['order'=>['customer'=>['value'=>$value]]]));
        }
    }
    public function test_explicit_array_selection_and_projection_have_different_cardinality(): void
    {
        $r=new BindingResolver;
        $binding=['source'=>'constant','value'=>[['id'=>7],['id'=>9]],'project_path'=>'id'];
        $this->assertSame([7,9],$r->resolve([...$binding,'mode'=>'project'],[],[]));
        $this->assertSame(9,$r->resolve([...$binding,'mode'=>'index','index'=>1],[],[]));
        $this->expectException(\DomainException::class);
        $r->resolve(['source'=>'constant','value'=>[['id'=>7]],'path'=>'id'],[],[]);
    }
    public function test_canonical_objects_ignore_key_order_but_lists_preserve_order(): void
    {
        $h=new ContentHasher;
        $this->assertSame($h->hash(['b'=>2,'a'=>1]),$h->hash(['a'=>1,'b'=>2]));
        $this->assertNotSame($h->hash([1,2]),$h->hash([2,1]));
        $this->assertNotSame($h->hash(0),$h->hash(false));
    }
}
