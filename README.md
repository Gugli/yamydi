# yamydi
Yet another MySQL schema diff tool.

#Goal
This very simple tool has one purpose : generate a .sql patch needed to change a database from current schema to another, and report the nature of the change (is there potential data loss, can it break requests on the database...).

**It's not meant to be smart**. If you need smart, use migrations :)

#Usage

## Options

Options are self-explainatory :

* --current-host
* --current-user
* --current-password
* --current-database
* --wanted-host
* --wanted-user
* --wanted-password
* --wanted-database
* --out-file : if ommitted, output to stdout

## Example
php /path/to/yamydi.php --current-host=localhost --current-user=root --current-password=MYSQLROOTPASSWORD --current-database=current_db --wanted-host=localhost --wanted-user=root --wanted-password=MYSQLROOTPASSWORD --wanted-database=wanted_db --out-file=/path/to/patch.sql
