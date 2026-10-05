# Changelog for AccessTransparency

## [1.3.0] - Unreleased
### Added
- Server-side tracking of document downloads, whatever the way the document is opened
- Item a document was opened from (ticket, change...) on the Document and User tabs
- Excluded logins: nothing is recorded for the listed accounts (e.g. service or inventory accounts)
- CSV export of the User and Document tabs, keeping the active filters
- Impersonation, massive action and setup events in the user history
- Migration of the document accesses of previous versions into the logs table

### Security
- Escape event messages before display (stored XSS through the failed login message)
- Check the plugin right and the item visibility on the Document tab and its content
- Hide the User tab without the plugin right
- Hide details of items the viewer cannot read (entity isolation)
- Remove the AJAX endpoint that allowed forging document access records
- Store and backfill the itemtype of events so their item visibility is checked; hide events whose item is unknown
- Events of unresolvable or removed itemtypes (e.g. of a disabled plugin) are hidden; only core "system" events are global
- Document accesses are recorded only when the file is actually served (exact script path, GET, successful response); the item it was opened from is kept only if it exists, the user can read it and the document is linked to it
- Close generic access to the log records (legacy REST API, search engine): they are only readable through the plugin tabs
- CSV exports require the same User/Document read right as the tabs
- Neutralise spreadsheet formulas in CSV exports and limit exports to 10,000 rows
- Attribute events to users only on whole-word login matches
- Attribute history entries only to the real actor id (and the impersonated user, verified against current names): ids typed in a display name are ignored

### Bugfix
- Log purge no longer deletes every record when the retention is not set
- Log ingestion no longer stalls on records that cannot be attributed to a user
- Database changes of upgrades are now applied (missing executeMigration())
- Automatic actions are removed on uninstall
- Faster User tab and purge (composite index, single DELETE)
- User tab no longer crashes when a record refers to an item of a disabled plugin (e.g. a Charges rule criterion)
- Event itemtypes are stored with their declared class name case
- Log ingestion commits each batch of rows with its cursor: an interrupted run no longer loses nor duplicates rows
- Migration of the document accesses of previous versions is atomic and can be retried without duplicates
- Tab and CSV filters only accept known values (malformed filters no longer cause errors)
- Configuration only accepts the form fields and valid retention values; ingestion cursors can't be changed from the form

## [1.2.0] - 2026-07-03
### Added
- Document access tracking for documents accessed by users
- View of tracking information like historical data, event logs, and opened document
- Consolidate logs into a single table

## [1.1.0] - 2026-02-05
### Bugfix
- Fix RAM Consumption

## [1.0.1] - 2025-12-19
### Bugfix
- Fix users filters

## [1.0.0] - 2025-12-19
### Added
- Initial release