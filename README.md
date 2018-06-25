Library written in PHP that can assist with 
[SQLite](https://www.sqlite.org/index.html) database migrations.

# Why

Sometimes you need to perform database schema migrations "in the field" without
having the option to have an administrator manually perform the database 
migration after installing a software update.

Typically this is useful for software that is deployed on systems out of your
control through an OS package manager where you hook into the normal update 
process of the OS and want to have the ability to update the database schema.

This library is optimized for running during the "initialization" phase of your
`index.php`, so it can be executed on every request.

# Features

* Can be implemented for a currently deployed (web) application that did not 
  consider database scheme updates from the start;
* Ability run through multiple schema updates when e.g. software was not 
  regularly updated and multiple schema updates occurred between the installed 
  version and the latest version;
* No need to run through all schema updates on application install. The 
  application will always install the latest schema and start from there;
* Uses `PDO` database abstraction layer;
* Optimized for running during every (HTTP) request;
* Uses database transactions to run migrations, guaranteeing a migration either
  fully completed or is rolled back;
* Implement rudimentary "locking" to prevent the migration to run multiple 
  times when application is under load.

# Limitations

Migrating during the normal application flow may not be reasonable for tables
with millions of rows. Manually triggering the update during e.g. a maintenance 
window is also supported.

# Assumptions

We assume you have a SQLite database somewhere with some tables in it, or not 
yet when you start a new application. That's it. Ideally you interface with 
your database through one class. This way you can add the methods `init()` and 
`migrate()` to them. See [API](#api) below for examples.

# Versions

The versions consist of a string of 10 digits. It makes sense to encode the 
current date in them with an additional 2 digit sequence number. The 
recommended format is `YYYYMMDDXX` where `XX` is the sequence that starts at 
`00` for the first version of that day. An example: `2018061502` for the 
third schema version on June 15th in 2018.

Internally the library uses `Migration::NO_VERSION` for when a database does 
not yet have a version. The value of `NO_VERSION` is `0000000000`. You can 
use `0000000000` also in migration files.

# Schema Files

Schema files are used to initialize a clean database. It contains the 
`CREATE TABLE` statements. They are named after their version and located in 
the schema directory. As an example, `/usr/share/app/schema/2018050501.schema` 
contains:

    CREATE TABLE foo (a INTEGER NOT NULL);

Schema files contain ONE query per line and are separated by a semi colon 
(`;`).

# Migration Files

Migration files contain the queries moving from one version to the next. 
Suppose in order to move from `2018050501` to `2018050502` a column is added
to the table `foo`. In this case, the file 
`/usr/share/app/schema/2018050501_2018050502.migration` could contain this:

    ALTER TABLE foo RENAME TO _foo;
    CREATE TABLE foo (a INTEGER NOT NULL, b INTEGER DEFAULT 0);
    INSERT INTO foo (a) SELECT a FROM _foo;
    DROP TABLE _foo;

Make sure to also create a `2018050502.schema` so new installations will 
immediately get the new database schema.

Migration files contain ONE query per line and are separated by a semi colon 
(`;`).

# API

The API is very simple. The constructor requires a `PDO` object, the directory
where the schema and migration files can be found and the latest database
schema version.

    $migration = new Migration(
        new PDO('sqlite::memory:'),
        '/usr/share/app/schema',
        '2018050501'
    );

    // initialize the database by looking for 2018050501.schema in the schema
    // directory. ONLY use this during application installation!
    $migration->init();

    // run the migration when needed moving from the currently deployed schema
    // version to the version specified in the constructor by looking for 
    // migration files in the schema directory
    $migration->run();

If your application has an "install" or "init" script you can use that to call
the `init()` method.

To perform database schema updates when needed, you can use the following call
in your `index.php` before using the database:

    if ($migration->run()) {
        echo "Migrated!";
    } else { 
        echo "No migration was needed.";
    }

The `run()` method returns a `boolean`, indicating whether or not a migration 
was performed.

# Contact

You can contact me with any questions or issues regarding this project. Drop
me a line at [fkooman@tuxed.net](mailto:fkooman@tuxed.net).

If you want to (responsibly) disclose a security issue you can also use the
PGP key with key ID `9C5EDD645A571EB2` and fingerprint
`6237 BAF1 418A 907D AA98  EAA7 9C5E DD64 5A57 1EB2`.

# License

[MIT](LICENSE).
