Very simple library that can assist with database migrations for SQLite when 
using PHP.

# Why

Ability to update an SQLite database "in the field" when software updates 
through Debian and RPM packages do not require administrator involvement.

We wanted to avoid requiring administrators to manually run database 
migrations.

# Features

* Start out with an application that has no database schema versioning, no need
  to rewrite everything;
* Chained migration, i.e. apply multiple schema updates simultaneously;
* No need to run through all migrations on "fresh" application install;
* Uses `PDO`, "optimized" for SQLite, but other databases may work;
* Works for "hot migrations" in the field, i.e. you can hook it into the normal
  application flow, migration will run when needed;
* Prevents multiple migrations to run in parallel when your application is 
  under heavy load;

# Limitations

Of course "hot migrations" are not reasonable when you have a million rows in
your database, but it should still work. It is recommended to only update in
service windows.

# Assumptions

We assume you have a SQLite database somewhere with some tables in it and one
class responsible for managing them. For example, my database classes typically
have an `init()` method that can be executed via a file system script to 
initialize the database on fresh installations of the application.

With this class you can create an `update()` method that triggers the 
migrations.

# Use

You can use [Composer](https://getcomposer.org/):

    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.tuxed.net/fkooman/php-sqlite-migrate"
        }
    ],

    ...

    "require": {
        "fkooman/sqlite-migrate": "dev-master"

        ...

    }

# API

Embed the `Migrator` class in your database class to take care of table 
migration. In the example below we have a database currently without versioning
where we want to add versioning and immediately perform an update:

    <?php
    require_once 'src/Migrator.php';

    $dbh = new PDO('sqlite:db.sqlite');
    $dbh->exec('CREATE TABLE foo (a INTEGER NOT NULL)');
    $dbh->exec('INSERT INTO foo (a) VALUES(3)');

     // the version of the database your application *expects*
    $m = new \fkooman\SqliteMigrate\Migrator($dbh, '2018061501');

    if ($m->isUpdateRequired()) {
        $m->addUpdate(
            \fkooman\SqliteMigrate\Migrator::NO_VERSION,
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

If in the future you want to add another modifcation, you leave the above as 
is, but change the version in the constructor to the new version and add 
another update, from the previous version to the next, e.g.:

    <?php

    //
    // ...
    // 
    // NOTE the latest version ---------------------vvvvvvvvvv
    $m = new \fkooman\SqliteMigrate\Migrator($dbh, '2018061601');
    if ($m->isUpdateRequired()) {
        $m->addUpdate(
            \fkooman\SqliteMigrate\Migrator::NO_VERSION,
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

