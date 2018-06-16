Very simple library written in PHP that can assist with database migrations for 
[SQLite](https://www.sqlite.org/index.html) and possibly other databases. Only 
SQLite is currently well tested.

# Why

Ability to modify an SQLite database scheme "in the field" to support software 
updates through DEB and RPM packages as to not require a system administrator
to manually run a database migration script.

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
  to be updated on every HTTP request;
* Implement rudimentary "locking" to prevent the migration to run multiple 
  times when application is under load.

# Limitations

Of course "hot migrations" are not reasonable when you have a million rows in
your database, but it SHOULD still work. It is recommended to only update in
service windows in any case.

# Assumptions

We assume you have a SQLite database somewhere with some tables in it. That's
it. Ideally you interface with your database through one class. This way you
can add an `update()` method to it that calls `Migrator::update`.

# Versions

The versions consist of a string of 10 digits. It makes sense to encode the 
current date in them with an additional 2 digit sequence number. The 
recommended format is `YYYYMMDDXX` where `XX` is the sequence that starts at 
`01` for the first version of that day. An example: `2018061503` for the 
third schema version on June 15th in 2018.

Internally the application uses `Migrator::NO_VERSION` for when no version 
table is available (yet). The value of `NO_VERISON` is `0000000000`.

# API

Embed the `Migrator` class in your database class to take care of schema 
updates. In the example below we start with a database without any version 
information. We want to add this and immediately perform an update to the 
schema:

    <?php
    require_once 'src/Migrator.php';

    use fkooman\SqliteMigrate\Migrator;

    $dbh = new PDO('sqlite:db.sqlite');
    $dbh->exec('CREATE TABLE foo (a INTEGER NOT NULL)');
    $dbh->exec('INSERT INTO foo (a) VALUES(3)');

     // the version of the database your application *expects*
    $m = new Migrator($dbh, '2018061501');

    if ($m->isUpdateRequired()) {
        $m->addUpdate(
            Migrator::NO_VERSION,
            '2018061501',
            [
                // add column "b"
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $m->update();
    }

If in the future you want to perform another schema update, you leave the above 
as is, but change the version in the constructor to the new version and add 
another update, from the previous version to the next, e.g.:

    <?php

    //
    // ...
    // 
    // NOTE the latest version ---------------------vvvvvvvvvv
    $m = new Migrator($dbh, '2018061601');
    if ($m->isUpdateRequired()) {
        $m->addUpdate(
            Migrator::NO_VERSION,
            '2018061501',
            [
                // add column "b"
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0)',
                'INSERT INTO foo (a) SELECT a FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $m->addUpdate(
            '2018061501',
            '2018061601',
            [
                // add column "c" as well
                'ALTER TABLE foo RENAME TO _foo',
                'CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0, c BOOLEAN DEFAULT 1)',
                'INSERT INTO foo (a, b) SELECT a, b FROM _foo',
                'DROP TABLE _foo',
            ]
        );
        $m->update();
    }

Now from any state in the history of the database you can migrate to the 
latest version.

# License

[MIT](LICENSE).

