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

### Bugfix
- Log purge no longer deletes every record when the retention is not set
- Log ingestion no longer stalls on records that cannot be attributed to a user
- Database changes of upgrades are now applied (missing executeMigration())
- Automatic actions are removed on uninstall
- Faster User tab and purge (composite index, single DELETE)

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