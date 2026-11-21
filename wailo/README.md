# WAILO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Features

Two-way synchronisation of Dolibarr contacts with [Wailo](https://wailo.ai):

- contacts created, modified or deleted in Dolibarr are sent to Wailo, and the other way round;
- a scheduled job runs one pass every 5 minutes, plus a "Synchronise now" button;
- a contact removed in Wailo is never deleted from Dolibarr: it becomes inactive, with a private note; if it comes
  back in Wailo, the same Dolibarr contact becomes active again (no duplicate);
- safeguards: at most 5 deletions (or deactivations) per pass, human confirmation above 50 operations,
  confirmation of the first sending after a change of enterprise; each confirmation covers the exact set shown;
- when the module starts on a Dolibarr that already holds contacts, a contact found in Wailo (same e-mail or mobile)
  is paired: only its empty Dolibarr fields are filled, its third party never changes, and what only Dolibarr knows
  is sent to Wailo;
- the Wailo API key is stored encrypted by Dolibarr and never displayed again.

Field mapping: first name, last name, company (third party), job title, first e-mail, two phones
(mobile, work), first address, LinkedIn, Facebook, Instagram (module "Social networks" on), public note (option,
off by default, plain text). Inactive and private Dolibarr contacts are not sent.

Requires a Wailo account with API access. A trial key (`wlo_test_`) opens a sandbox of 40 fictitious contacts:
use it on a test Dolibarr only. On a Dolibarr that holds real contacts, the fictitious contacts would be added to it
(and real contacts sharing an e-mail or a mobile with a fictitious one would be paired with it).

## Installation

Go to `Home > Setup > Modules > Deploy external module` and upload `module_wailo-x.y.z.zip`.
Enable the module "Wailo", open its setup page, paste the API key, then click "Test the connection".

The scheduled job needs the Dolibarr scheduler (module "Scheduled jobs" and `scripts/cron/cron_run_jobs.php`
called by the system cron). Without it, use the "Synchronise now" button.

## Licenses

GPLv3 or (at your option) any later version. See file COPYING for more information.
