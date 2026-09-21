<?php

declare(strict_types=1);

namespace Kode\Database\Tests;

use Kode\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private function builder(?FakeExecutor $executor = null): array
    {
        $executor ??= new FakeExecutor();
        $qb = new QueryBuilder($executor);
        $qb->table('users');
        return [$qb, $executor];
    }

    public function testOrderByDescendingIsEmitted(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY id DESC',
            $qb->orderBy('id', 'desc')->toSql()
        );
    }

    public function testOrderByAscendingHasNoSuffix(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY created_at',
            $qb->orderBy('created_at')->toSql()
        );
    }

    public function testOrderByRawIsEmittedVerbatim(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY RAND()',
            $qb->orderByRaw('RAND()')->toSql()
        );
    }

    public function testOrderByAfterOrderByRawRestoresDirection(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY id DESC',
            $qb->orderByRaw('RAND()')->orderBy('id', 'DESC')->toSql()
        );
    }

    public function testOrderArrayKeepsAllColumns(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY last_login DESC, username ASC',
            $qb->order(['last_login' => 'desc', 'username' => 'asc'])->toSql()
        );
    }

    public function testOrderNumericListUsesSharedDirection(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY a DESC, b DESC',
            $qb->order(['a', 'b'], 'desc')->toSql()
        );
    }

    public function testOrderCommaStringParsesEachSegment(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY a DESC, b ASC',
            $qb->order('a desc, b asc')->toSql()
        );
    }

    public function testOrderSingleStringWithDirection(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users ORDER BY id DESC',
            $qb->order('id desc')->toSql()
        );
    }

    public function testClearOrderByResetsRawState(): void
    {
        [$qb] = $this->builder();
        $qb->orderByRaw('RAND()')->clearOrderBy();
        self::assertSame(
            'SELECT * FROM users ORDER BY id DESC',
            $qb->orderBy('id', 'desc')->toSql()
        );
    }

    public function testOrWhereDoesNotProduceAndOr(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users WHERE a = ? OR b = ?',
            $qb->where('a', 1)->orWhere('b', 2)->toSql()
        );
    }

    public function testOrWhereNotJoinsWithoutAnd(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users WHERE a = ? OR NOT b != ?',
            $qb->where('a', 1)->orWhereNot('b', 2)->toSql()
        );
    }

    public function testWhereInAndOrMixed(): void
    {
        [$qb] = $this->builder();
        self::assertSame(
            'SELECT * FROM users WHERE status = ? AND role IN (?, ?) OR last_login IS NULL',
            $qb->where('status', 1)->whereIn('role', ['a', 'b'])->orWhereNull('last_login')->toSql()
        );
    }

    public function testClearWhereResetsBindingsWithoutTypeError(): void
    {
        [$qb] = $this->builder();
        $qb->where('a', 1)->whereIn('b', [2, 3]);
        $qb->clearWhere();
        self::assertSame([], $qb->toInfo()['bindings']);
        self::assertSame('SELECT * FROM users', $qb->toSql());
    }

    public function testUpdateMergesWhereBindingsAfterSetValues(): void
    {
        [$qb, $executor] = $this->builder();
        $affected = $qb->where('id', 5)->update(['name' => 'x']);

        self::assertSame(7, $affected);
        self::assertSame('update', $executor->lastMethod);
        self::assertSame('UPDATE users SET name = ? WHERE id = ?', $executor->lastSql);
        self::assertSame(['x', 5], $executor->lastBindings);
    }

    public function testUpdateBatchIncludesWhereAndConditionBindings(): void
    {
        [$qb, $executor] = $this->builder();
        $affected = $qb->where('status', 1)->updateBatch(['a' => 2], ['id' => 3]);

        self::assertSame(7, $affected);
        self::assertSame('UPDATE users SET a = ? WHERE status = ? AND id = ?', $executor->lastSql);
        self::assertSame([2, 1, 3], $executor->lastBindings);
    }

    public function testInsertAllAlignsValuesToFirstRecordColumns(): void
    {
        [$qb, $executor] = $this->builder();
        $qb->insertAll([
            ['id' => 1, 'name' => 'a'],
            ['name' => 'b', 'id' => 2],
        ]);

        self::assertSame(
            'INSERT INTO users (id, name) VALUES (?, ?), (?, ?)',
            $executor->lastSql
        );
        self::assertSame([1, 'a', 2, 'b'], $executor->lastBindings);
    }

    public function testUpsertBatchPgsqlUsesOnConflictExcluded(): void
    {
        [$qb, $executor] = $this->builder(new FakeExecutor('pgsql'));
        $affected = $qb->upsertBatch(
            [['email' => 'a@b.c', 'name' => 'x'], ['email' => 'd@e.f', 'name' => 'y']],
            ['email']
        );

        self::assertSame(7, $affected);
        self::assertSame('update', $executor->lastMethod);
        self::assertStringContainsString(
            'ON CONFLICT (email) DO UPDATE SET name = excluded.name',
            $executor->lastSql
        );
        self::assertStringNotContainsString('ON DUPLICATE', $executor->lastSql);
        self::assertSame(['a@b.c', 'x', 'd@e.f', 'y'], $executor->lastBindings);
    }

    public function testUpsertBatchPgsqlWithoutUpdatableFieldsDoesNothing(): void
    {
        [$qb, $executor] = $this->builder(new FakeExecutor('pgsql'));
        $qb->upsertBatch([['email' => 'a@b.c']], ['email'], []);

        self::assertStringContainsString('ON CONFLICT (email) DO NOTHING', $executor->lastSql);
    }

    public function testUpsertBatchMysqlKeepsOnDuplicateKey(): void
    {
        [$qb, $executor] = $this->builder(new FakeExecutor('mysql'));
        $qb->upsertBatch([['email' => 'a@b.c', 'name' => 'x']], ['email']);

        self::assertStringContainsString(
            'ON DUPLICATE KEY UPDATE name = VALUES(name)',
            $executor->lastSql
        );
    }

    public function testDeleteBatchReturnsRowCount(): void
    {
        [$qb, $executor] = $this->builder();
        $affected = $qb->deleteBatch([1, 2, 3]);

        self::assertSame(7, $affected);
        self::assertSame('delete', $executor->lastMethod);
        self::assertSame('DELETE FROM users WHERE id IN (?, ?, ?)', $executor->lastSql);
        self::assertSame([1, 2, 3], $executor->lastBindings);
    }

    public function testInsertOrIgnorePgsqlUsesOnConflictDoNothing(): void
    {
        [$qb, $executor] = $this->builder(new FakeExecutor('pgsql'));
        $inserted = $qb->insertOrIgnore(['email' => 'a@b.c', 'name' => 'x']);

        self::assertTrue($inserted);
        self::assertStringContainsString('ON CONFLICT DO NOTHING', $executor->lastSql);
        self::assertStringNotContainsString('INSERT IGNORE', $executor->lastSql);
        self::assertSame(['a@b.c', 'x'], $executor->lastBindings);
    }

    public function testInsertOrIgnoreMysqlKeepsInsertIgnore(): void
    {
        [$qb, $executor] = $this->builder(new FakeExecutor('mysql'));
        $inserted = $qb->insertOrIgnore(['email' => 'a@b.c']);

        self::assertTrue($inserted);
        self::assertStringStartsWith('INSERT IGNORE INTO users', $executor->lastSql);
    }

    public function testToInfoRendersRawAndDirectionalOrderBy(): void
    {
        [$rawQb] = $this->builder();
        self::assertSame('RAND()', $rawQb->orderByRaw('RAND()')->toInfo()['orderBy']);

        [$dirQb] = $this->builder();
        self::assertSame('id DESC', $dirQb->orderBy('id', 'desc')->toInfo()['orderBy']);
    }
}
