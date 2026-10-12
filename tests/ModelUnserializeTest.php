<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Model\Model;
use PHPUnit\Framework\TestCase;

/**
 * Model::unserialize 对象注入防线
 *
 * 覆盖：
 *  - 默认（allowed_classes=true）往返保真（BC）
 *  - 类白名单收紧：[static::class] 可解本类，嵌套对象降级为 Incomplete（gadget 失效）
 *  - allowed_classes=false 必然失败（载荷主体即模型自身）且报错可读
 *  - 错类载荷 / 损坏载荷 → InvalidArgumentException 而非 TypeError
 */
final class ModelUnserializeTest extends TestCase
{
    public function testRoundTripWithDefaultAllowedClasses(): void
    {
        $model = new ModelUnserializeFixture(['name' => 'alice', 'age' => 30]);
        $model->extra = (object) ['k' => 'v'];

        $restored = ModelUnserializeFixture::unserialize($model->serialize());

        $this->assertInstanceOf(ModelUnserializeFixture::class, $restored);
        $this->assertSame('alice', $restored['name']);
        $this->assertSame(30, $restored['age']);
        $this->assertInstanceOf(\stdClass::class, $restored->extra, '默认允许类：嵌套对象保真');
    }

    public function testWhitelistOnlyModelClassNeutralizesNestedObjects(): void
    {
        $model = new ModelUnserializeFixture(['name' => 'bob']);
        $model->extra = (object) ['gadget' => 'payload'];

        $restored = ModelUnserializeFixture::unserialize(
            $model->serialize(),
            [ModelUnserializeFixture::class],
        );

        $this->assertInstanceOf(ModelUnserializeFixture::class, $restored);
        $this->assertInstanceOf(
            \__PHP_Incomplete_Class::class,
            $restored->extra,
            '白名单外的对象不得被实例化（析构器/wakeup 不执行）',
        );
    }

    public function testDisallowedFalseAlwaysFailsWithClearHint(): void
    {
        $model = new ModelUnserializeFixture(['name' => 'carol']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('allowed_classes');
        ModelUnserializeFixture::unserialize($model->serialize(), false);
    }

    public function testWrongClassPayloadRejected(): void
    {
        $other = new ModelUnserializeOtherFixture(['x' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        ModelUnserializeFixture::unserialize($other->serialize());
    }

    public function testCorruptPayloadRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModelUnserializeFixture::unserialize('garbage');
    }
}

final class ModelUnserializeFixture extends Model
{
    protected string $table = 'unserialize_fixture';
    public $extra = null;
}

final class ModelUnserializeOtherFixture extends Model
{
    protected string $table = 'other_fixture';
}
