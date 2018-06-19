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

use fkooman\SqliteMigrate\Migration;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class MigrationTest extends TestCase
{
    public function testInit()
    {
        $migration = new Migration(new PDO('sqlite::memory:'), '2018010101');
        $migration->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migration->getCurrentVersion());
    }

    public function testSimpleMigration()
    {
        $dbh = new PDO('sqlite::memory:');
        $migration = new Migration($dbh, '2018010101');
        $migration->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migration->getCurrentVersion());
        $dbh->exec('INSERT INTO foo (a) VALUES(3)');

        $migration = new Migration($dbh, '2018010102');
        $this->assertTrue($migration->isRequired());
        $migration->addMigration(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $migration->run();
        $this->assertSame('2018010102', $migration->getCurrentVersion());
        $this->assertFalse($migration->isRequired());
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
        $migration = new Migration($dbh, '2018010101');
        $migration->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migration->getCurrentVersion());
        $dbh->exec('INSERT INTO foo (a) VALUES(3)');

        $migration = new Migration($dbh, '2018010103');
        $migration->addMigration(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );

        $migration->addMigration(
            '2018010102',
            '2018010103',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0, c TEXT DEFAULT NULL)',
                'INSERT INTO foo (a, b) SELECT a, b FROM _foo',
                'DROP TABLE _foo',
            ]
        );

        $migration->run();
        $this->assertSame('2018010103', $migration->getCurrentVersion());
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
        $migration = new Migration($dbh, '2018010101');

        $migration->addMigration(
            Migration::NO_VERSION,
            '2018010101',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $this->assertSame('0000000000', $migration->getCurrentVersion());
        $migration->run();
        $this->assertSame('2018010101', $migration->getCurrentVersion());
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
        $migration = new Migration($dbh, '2018010101');
        $migration->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migration->getCurrentVersion());
        $migration = new Migration($dbh, '2018010102');
        $this->assertTrue($migration->isRequired());
        $migration->addMigration(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE bar RENAME TO _bar',
            ]
        );
        try {
            $migration->run();
            $this->fail();
        } catch (PDOException $e) {
            $this->assertSame('2018010101', $migration->getCurrentVersion());
        }
    }

    public function testWithForeignKeys()
    {
        $dbh = new PDO('sqlite::memory:');
        $dbh->exec('PRAGMA foreign_keys = ON');
        $migration = new Migration($dbh, '2018010101');
        $migration->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL)',
            ]
        );
        $this->assertSame('2018010101', $migration->getCurrentVersion());
        $dbh->exec('INSERT INTO foo (a) VALUES(3)');

        $migration = new Migration($dbh, '2018010102');
        $this->assertTrue($migration->isRequired());
        $migration->addMigration(
            '2018010101',
            '2018010102',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b BOOLEAN DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $migration->run();
        $this->assertSame('2018010102', $migration->getCurrentVersion());
        $this->assertFalse($migration->isRequired());
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
}
