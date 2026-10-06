# Access Transparency

<img src="https://github.com/ticgal/accesstransparency/blob/985d98c6e01ccf92f428f5a43226e6150a935107/accesstransparency.png" alt="Access Transparency Logo" height="250px" width="250px" class="js-lazy-loaded">

[![License](https://img.shields.io/badge/License-GNU%20AGPLv3-blue.svg?style=flat-square)](https://github.com/ticgal/actualtime/blob/master/LICENSE)
[![Twitter](https://img.shields.io/badge/Twitter-TICGAL-blue.svg?style=flat-square)](https://twitter.com/ticgalcom)
[![Web](https://img.shields.io/badge/Web-TICGAL-blue.svg?style=flat-square)](https://tic.gal/)
[![Web](https://img.shields.io/badge/Web-Access%20Transparency-blue.svg?style=flat-square)](https://tic.gal/glpi/glpi-plugins/access-transparency/)
[![Localazy](https://img.shields.io/badge/Translate-Localazy-cyan)](https://localazy.com/p/access-transparency#translations)


# Access Transparency

Provides full visibility and traceability of user activity in GLPI.  
Access Transparency is a plugin for GLPI that tracks and displays detailed user actions and object interactions across the system.

## Setup

Install this plugin like any other GLPI plugin:

1. Download the plugin and place it in the `plugins/` folder of your GLPI instance.
2. Go to **Setup → Plugins** in GLPI and enable **Access Transparency**.
3. Assign access permissions under **Administration → Profiles → Access Transparency** so users can access the tab.

## How to Use

Once installed and enabled:

- A **dedicated tab** is added to each user profile, showing a centralized history of actions performed by that user.
- A **document-level tab** shows which users opened a file and when.
- Some records include a direct link to the affected object, when available.

## Features

- **Centralized user activity log** showing actions performed across the system.
- **File open tracking** to record document access events.
- **Filterable and paginated views** to easily locate specific actions.
- **Exportable logs** (CSV) from both full and filtered views.
- **Dedicated history tabs** for users and documents, showing where a document was opened from.
- **Localized records**, displayed in the default GLPI language or English where applicable.
- **Entity-aware**: details of items the viewer cannot read are never displayed.

## Configuration

- Define how long the records are kept via **Configuration → General → Access Transparency**.
- GLPI history and events are copied into the plugin by the **PluginAccesstransparencyGetLogs** automatic action,
  and old records are purged by the **PurgeAccessTransparencyLogs** automatic action.
- Exclude service/system accounts (e.g. `inventory`, `ocsinventory`, `glpi-agent`) from
  tracking by listing their logins, comma-separated, in **Excluded logins** on the same
  configuration page. Matching is case-insensitive; nothing is recorded for excluded accounts
  (neither their document accesses nor their history and events).
