Very simple library written in PHP that can assist with database migrations for 
[SQLite](https://www.sqlite.org/index.html) and possibly other databases. Only 
SQLite is currently well tested.

# Why

Ability to modify an SQLite database scheme "in the field" to support software 
updates through DEB and RPM packages so as to not require a system 
administrator to manually run a database migration script.

**NOTE**: for (very) big migrations this may NOT be a good idea!

# Features

* Can be implemented for a currently deployed application using SQLite that
  did not consider database scheme updates;
* Ability run through multiple schema updates when e.g. software was not 
  regularly updated and multiple updates occurred between the installed version
  and the latest version;
* No need to run through all schema updates on application install. The 
  application can always install the latest schema;
* Uses `PDO`, so other databases MAY work. Some testing was done with 
  [MariaDB](https://mariadb.org/) and 
  [PostgreSQL](https://www.postgresql.org/).
* Optionally can be used for "hot migrations", i.e. check if the schema needs 
  to be updated on every HTTP request and then perform the update;
* Uses database transactions to run migration steps;
* Implement rudimentary "locking" to prevent the migration to run multiple 
  times when application is under load.

# Limitations

Of course "hot migrations" are not reasonable when you have a million rows in
your database, but it SHOULD still work. It is recommended to only update in
a maintenance windows in any case.

# Assumptions

We assume you have a SQLite database somewhere with some tables in it, or not 
yet when you start a new application. That's it. Ideally you interface with 
your database through one class. This way you can add `init()` and `update()` 
to it. See [API](#api) below.

# Versions

The versions consist of a string of 10 digits. It makes sense to encode the 
current date in them with an additional 2 digit sequence number. The 
recommended format is `YYYYMMDDXX` where `XX` is the sequence that starts at 
`00` for the first version of that day. An example: `2018061502` for the 
third schema version on June 15th in 2018.

Internally the library uses `Migrator::NO_VERSION` for when no version table is 
available (yet). The value of `NO_VERSION` is `0000000000`.

# API

We assume you have a database class where we'll define an `init()` and 
`update()` method. The `init()` is used during application installation to 
initialize the database. The `init()` function will always contain the most up 
to data database schema with the version matching `SCHEMA_VERSION` as used 
below. The `update()` method contains the required update steps to reach *this* 
schema version if an old schema version is currently deployed.

    <?php

    use fkooman\SqliteMigrate\Migrator;

    class DbStorage
    {
        const SCHEMA_VERSION = '2018061001';

        /** @var \PDO */
        private $dbh;

        /** @var \fkooman\SqliteMigrate\Migrator */
        private $migrator;

        public function __construct(PDO $dbh)
        {
            $this->dbh = $dbh;
            $this->migrator = new Migrator($dbh, self::SCHEMA_VERSION);
        }

        /**
         * Call this when you install your application, this happens only once.
         */
        public function init()
        {
            $this->migrator->init(
                [
                    'CREATE TABLE foo (a INTEGER NOT NULL)',
                ]
            );
        }

        /**
         * Call this every time. If there is nothing to update it will only
         * perform 1 SELECT query...
         */
        public function update()
        {
            $this->migrator->update();
        }
    }

In the future if you want to update the schema, what you'll do is increment the 
`SCHEMA_VERSION`, make sure the `init()` method creates this version of the 
schema and define an update step in the `update()` method. Suppose the new 
version of the schema is `2018061601` then you can define this update:

    <?php 

    // ...

    const SCHEMA_VERSION = '2018061601';

    // ...

    public function init()
    {
        $this->migrator->init(
            [
                'CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0)',
            ]
        );
    }

    public function update()
    {
        $this->migrator->addUpdate(
            '2018061001',
            '2018061601',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $this->migrator->update();
    }

If you deployed your application initially without any schema version, you 
also need an update from the schema without version to one with version:

    <?php

    // ...

    const SCHEMA_VERSION = '2018061601';

    // ...

    public function update()
    {
        $this->migrator->addUpdate(
            Migrator::NO_VERSION,
            '2018061001',
            []
        );
        $this->migrator->addUpdate(
            '2018061001',
            '2018061601',
            [
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $this->migrator->update();
    }

Here, in this example we assumed that schema `2018061001` is the same as the 
schema without version, so this particular update doesn't need to do anything, 
except bring it under version control. After this, the next update can be 
applied, exactly like before. The library will chain the updates and execute
them one after the other.

# Contact

You can contact me with any questions or issues regarding this project. Drop
me a line at [fkooman@tuxed.net](mailto:fkooman@tuxed.net).

If you want to (responsibly) disclose a security issue you can also use the
PGP key with key ID `9C5EDD645A571EB2` and fingerprint
`6237 BAF1 418A 907D AA98  EAA7 9C5E DD64 5A57 1EB2`.

# License

[MIT](LICENSE).
