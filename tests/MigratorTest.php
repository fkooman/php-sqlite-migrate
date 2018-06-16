<?php

/*
 * Copyright (c) 2018 François Kooman <fkooman@tuxed.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace fkooman\SqliteMigrate\Tests;

use fkooman\SqliteMigrate\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class MigratorTest extends TestCase
{
    public function testInit()
    {
        $migrator = new Migrator(new PDO('sqlite::memory:'), '2018010101');
        $migrator->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migrator->getCurrentVersion());
    }

    public function testSimpleMigration()
    {
        $dbh = new PDO('sqlite::memory:');
        $migrator = new Migrator($dbh, '2018010101');
        $migrator->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migrator->getCurrentVersion());
        $dbh->exec('INSERT INTO foo (a) VALUES(3)');

        $migrator = new Migrator($dbh, '2018010102');
        $this->assertTrue($migrator->isUpdateRequired());
        $migrator->addUpdate(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $migrator->update();
        $this->assertSame('2018010102', $migrator->getCurrentVersion());
        $this->assertFalse($migrator->isUpdateRequired());
        $sth = $dbh->query('SELECT * FROM foo');
        $this->assertSame(
            [
                [
                    'a' => '3',
                    'b' => '0',
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testMultiMigration()
    {
        $dbh = new PDO('sqlite::memory:');
        $migrator = new Migrator($dbh, '2018010101');
        $migrator->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migrator->getCurrentVersion());
        $dbh->exec('INSERT INTO foo (a) VALUES(3)');

        $migrator = new Migrator($dbh, '2018010103');
        $migrator->addUpdate(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );

        $migrator->addUpdate(
            '2018010102',
            '2018010103',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0, c TEXT DEFAULT NULL)',
                'INSERT INTO foo (a, b) SELECT a, b FROM _foo',
                'DROP TABLE _foo',
            ]
        );

        $migrator->update();
        $this->assertSame('2018010103', $migrator->getCurrentVersion());
        $sth = $dbh->query('SELECT * FROM foo');
        $this->assertSame(
            [
                [
                    'a' => '3',
                    'b' => '0',
                    'c' => null,
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testNoVersion()
    {
        // we have a database without versioning, but we want to bring it
        // under version control, we can't run init as that would install the
        // version table...
        $dbh = new PDO('sqlite::memory:');
        $dbh->exec('CREATE TABLE foo (a INTEGER NOT NULL)');
        $dbh->exec('INSERT INTO foo (a) VALUES(3)');
        $migrator = new Migrator($dbh, '2018010101');

        $migrator->addUpdate(
            Migrator::NO_VERSION,
            '2018010101',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $this->assertSame('0000000000', $migrator->getCurrentVersion());
        $migrator->update();
        $this->assertSame('2018010101', $migrator->getCurrentVersion());
        $sth = $dbh->query('SELECT * FROM foo');
        $this->assertSame(
            [
                [
                    'a' => '3',
                    'b' => '0',
                ],
            ],
            $sth->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function testFailingUpdate()
    {
        $dbh = new PDO('sqlite::memory:');
        $migrator = new Migrator($dbh, '2018010101');
        $migrator->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migrator->getCurrentVersion());
        $migrator = new Migrator($dbh, '2018010102');
        $this->assertTrue($migrator->isUpdateRequired());
        $migrator->addUpdate(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE bar RENAME TO _bar',
            ]
        );
        try {
            $migrator->update();
            $this->fail();
        } catch (PDOException $e) {
            $this->assertSame('2018010101', $migrator->getCurrentVersion());
        }
    }
}
