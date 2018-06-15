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

namespace fkooman\SqliteMigrate;

use PDO;
use PDOException;

class Migrator
{
    const NO_VERSION = '0000000000';

    /** @var \PDO */
    private $dbh;

    /** @var string */
    private $schemaVersion;

    /** @var array<string, array> */
    private $updateList = [];

    /**
     * @param \PDO   $dbh
     * @param string $schemaVersion
     */
    public function __construct(PDO $dbh, $schemaVersion)
    {
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $dbh;
        $this->schemaVersion = $schemaVersion;
    }

    /**
     * @param array $queryList
     *
     * @return void
     */
    public function init(array $queryList = [])
    {
        foreach ($queryList as $dbQuery) {
            $this->dbh->exec($dbQuery);
        }
        $this->createVersionTable($this->schemaVersion);
    }

    /**
     * @param string        $fromVersion
     * @param string        $toVersion
     * @param array<string> $queryList
     *
     * @return void
     */
    public function addUpdate($fromVersion, $toVersion, array $queryList)
    {
        $this->updateList[\sprintf('%s:%s', $fromVersion, $toVersion)] = $queryList;
    }

    /**
     * @param array<string,array<string>> $dbQueries
     *
     * @return void
     */
    public function update()
    {
        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion === $this->schemaVersion) {
            // database schema is up to date, no update required
            return;
        }

        $this->dbh->exec('CREATE TABLE _migration_in_progress (dummy INTEGER)');

        // make sure we run through the migrations in order
        \ksort($this->updateList);
        foreach ($this->updateList as $fromTo => $queryList) {
            list($fromVersion, $toVersion) = \explode(':', $fromTo);
            if ($fromVersion === $currentVersion) {
                try {
                    $this->dbh->beginTransaction();
                    $this->dbh->exec(\sprintf("DELETE FROM version WHERE current_version = '%s'", $fromVersion));
                    foreach ($queryList as $dbQuery) {
                        $this->dbh->exec($dbQuery);
                    }
                    $this->dbh->exec(\sprintf("INSERT INTO version (current_version) VALUES('%s')", $toVersion));
                    $this->dbh->commit();
                    $currentVersion = $toVersion;
                } catch (PDOException $e) {
                    $this->dbh->rollback();

                    throw $e;
                }
            }
        }

        $this->dbh->exec('DROP TABLE _migration_in_progress');
    }

    /**
     * @return false|string
     */
    public function getCurrentVersion()
    {
        try {
            $sth = $this->dbh->query('SELECT current_version FROM version');
            $currentVersion = $sth->fetchColumn(0);
            $sth->closeCursor();

            return $currentVersion;
        } catch (PDOException $e) {
            $this->createVersionTable(self::NO_VERSION);

            return self::NO_VERSION;
        }
    }

    /**
     * @param string $schemaVersion
     *
     * @return void
     */
    private function createVersionTable($schemaVersion)
    {
        $this->dbh->exec('CREATE TABLE IF NOT EXISTS version (current_version TEXT NOT NULL)');
        $this->dbh->exec(\sprintf("INSERT INTO version (current_version) VALUES('%s')", $schemaVersion));
    }
}
