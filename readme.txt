=== Database Debugging Tools for Developers ===
Contributors: Magenta Cuda
Donate link:
Tags: database, diff, backup, tool, testing
Requires at least: 3.6
Tested up to: 4.7
Stable tag: 2.2.0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
WordPress database tools for debugging.

== Description ==
These tools are intended to be used by WordPress developers for testing and debugging.

The backup tool lets you do a quick backup of individual MySQL tables by duplication into the same database, i.e. using "CREATE TABLE copy LIKE orig; INSERT INTO copy SELECT * FROM orig;".
Useful for testing when you know only some tables will be changed so you don't have to save and restore the entire database.
Most useful for repeated testing, i.e. backup table(s), test, restore table(s), test, restore table(s), ... test, restore table(s), delete backup.

The diff tool shows the rows in the selected tables that were inserted, updated or deleted.
For updated rows the columns that have changed values have the values highlighted - red for the original value and green for the new value.
Although large values are truncated in table cells the full value is available by clicking on the table cell.
Further, serialized values are prettified using JSON.stringify( value, 4 ).
The diff tool is intended for viewing the effect on the database tables of a small number of WordPress operations,
i.e. it is not suitable for testing a large number of WordPress operations.

Please visit [https://wpdbdt.wordpress.com/](https://wpdbdt.wordpress.com/) for a very quick introduction.

**This plugin requires at least PHP 5.4.**

== Installation ==
1. Download the plugin from the WordPress repository.
2. Open the 'Plugins' menu item and activate the plugin.
3. Read the tutorial at [https://wpdbdt.wordpress.com/](https://wpdbdt.wordpress.com/)

== Frequently Asked Questions ==

= Can this be used for backing up a database? =

No, the backup is done by creating additional tables in the same database so if the database is lost the backup is also lost.
   
== Screenshots ==
1. Backup Tool
2. Diff Tool
3. Diff Tool - Detail View

== Changelog ==
= 2.2.0.1 =
* fix a small bug with SELECT with multiple primary keys
= 2.2 =
* fix broken RegEx's which failed to match some SQL operations as it should
* support logging SQL SELECT operations
* support recovery from backup/restore timeout failure
= 2.1.1 =
* fix diff tool so that it correctly handles tables with multiple primary keys
= 2.1.0.2 =
* bug fix
= 2.1.0.1 =
* bug fix
= 2.1 =
* improved user interface
* code refactored to improve software quality
= 2.0.1.1 =
* Fix HTML entities bug
= 2.0.1 =
* Remember the table size, cell size and sort order for each table for the next session
* Highlight the changed fields in serialized values
= 2.0.0.1 =
* Fix readme tags
= 2.0 =
* Added the diff tool
= 1.0 =
* Initial release.
  
== Upgrade Notice ==
= 2.2.0.1 =
* fix a small bug with SELECT with multiple primary keys
= 2.2 =
* fix broken RegEx's which failed to match some SQL operations as it should
* support logging SQL SELECT operations 
* support recovery from backup/restore timeout failure
= 2.1.1 =
* fix diff tool so that it correctly handles tables with multiple primary keys
= 2.1.0.2 =
* bug fix
= 2.1.0.1 =
* bug fix
= 2.1 =
* improved user interface
* code refactored to improve software quality
= 2.0.1.1 =
* Fix HTML entities bug
= 2.0.1 =
* Remember the table size, cell size and sort order for each table for the next session 
* Highlight the changed fields in serialized values
= 2.0.0.1 =
* Fix readme tags
= 2.0 =
* Added the diff tool
= 1.0 =
* Initial release.


