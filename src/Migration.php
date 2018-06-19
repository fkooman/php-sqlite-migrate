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

use fkooman\SqliteMigrate\Exception\MigrationException;
use PDO;
use PDOException;
use RangeException;
use RuntimeException;

class Migration
{
    const NO_VERSION = '0000000000';

    /** @var \PDO */
    private $dbh;

    /** @var string */
    private $schemaVersion;

    /** @var string */
    private $schemaDir;

    /**
     * @param \PDO   $dbh           database handle
     * @param string $schemaDir     directory containing schema and migration files
     * @param string $schemaVersion most recent database schema version
     */
    public function __construct(PDO $dbh, $schemaDir, $schemaVersion)
    {
        if ('sqlite' !== $dbh->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            // we only support SQLite for now
            throw new RuntimeException('only SQLite is supported');
        }
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->dbh = $dbh;
        $this->schemaDir = $schemaDir;
        $this->schemaVersion = self::validateSchemaVersion($schemaVersion);
    }

    /**
     * Initialize the database using the schema file located in the schema
     * directory with schema version.
     *
     * @return void
     */
    public function init()
    {
        $this->runQueriesFromFile(\sprintf('%s/%s.schema', $this->schemaDir, $this->schemaVersion));
        $this->createVersionTable($this->schemaVersion);
    }

    /**
     * Run the migration.
     *
     * @return bool
     */
    public function run()
    {
        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion === $this->schemaVersion) {
            // database schema is up to date, no update required
            return false;
        }

        // this creates a "lock" as only one process will succeed in this...
        $this->dbh->exec('CREATE TABLE _migration_in_progress (dummy INTEGER)');

        // disable "foreign_keys" if they were on...
        $sth = $this->dbh->query('PRAGMA foreign_keys');
        $hasForeignKeys = '1' === $sth->fetchColumn(0);
        $sth->closeCursor();
        if ($hasForeignKeys) {
            $this->dbh->exec('PRAGMA foreign_keys = OFF');
        }

        $migrationList = @\glob(\sprintf('%s/*_*.migration', $this->schemaDir));
        if (false === $migrationList) {
            throw new RuntimeException(\sprintf('unable to read schema directory "%s"', $this->schemaDir));
        }

        foreach ($migrationList as $migrationFile) {
            $fromTo = \basename($migrationFile, '.migration');
            list($fromVersion, $toVersion) = \explode('_', $fromTo);
            if ($fromVersion === $currentVersion && $fromVersion !== $this->schemaVersion) {
                try {
                    $this->dbh->beginTransaction();
                    $this->dbh->exec(\sprintf("DELETE FROM version WHERE current_version = '%s'", $fromVersion));

                    $this->runQueriesFromFile(\sprintf('%s/%s.migration', $this->schemaDir, $fromTo));

                    $this->dbh->exec(\sprintf("INSERT INTO version (current_version) VALUES('%s')", $toVersion));
                    $this->dbh->commit();
                    $currentVersion = $toVersion;
                } catch (PDOException $e) {
                    $this->dbh->rollback();

                    throw $e;
                }
            }
        }

        // enable "foreign_keys" if they were on...
        if ($hasForeignKeys) {
            $this->dbh->exec('PRAGMA foreign_keys = ON');
        }

        // release "lock"
        $this->dbh->exec('DROP TABLE _migration_in_progress');

        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion !== $this->schemaVersion) {
            throw new MigrationException(\sprintf('unable to upgrade to "%s", required migrations not available', $this->schemaVersion));
        }

        return true;
    }

    /**
     * Gets the current version of the database schema.
     *
     * @return string
     */
    public function getCurrentVersion()
    {
        try {
            $sth = $this->dbh->query('SELECT current_version FROM version');
            $currentVersion = $sth->fetchColumn(0);
            $sth->closeCursor();
            if (false === $currentVersion) {
                throw new MigrationException('unable to retrieve current version');
            }

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

    /**
     * @param string $schemaVersion
     *
     * @return string
     */
    private static function validateSchemaVersion($schemaVersion)
    {
        if (1 !== \preg_match('/^[0-9]{10}$/', $schemaVersion)) {
            throw new RangeException('schemaVersion must be 10 a digit string');
        }

        return $schemaVersion;
    }

    /**
     * @param string $filePath
     *
     * @return void
     */
    private function runQueriesFromFile($filePath)
    {
        $fileContent = @\file_get_contents($filePath);
        if (false === $fileContent) {
            throw new RuntimeException(\sprintf('unable to read "%s"', $filePath));
        }
        foreach (\explode("\n", $fileContent) as $dbQuery) {
            if (0 !== \strlen(\trim($dbQuery))) {
                $this->dbh->exec($dbQuery);
            }
        }
    }
}
