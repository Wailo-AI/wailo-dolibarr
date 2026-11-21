# CHANGELOG WAILO FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## 1.0.0

First stable version. A key revoked or no longer valid (Wailo answers 401) now stops the synchronisation at once with a
clear message on the setup page and in the journal: create a new key in the Wailo Enterprise space ("API access"
screen) and paste it. Nothing is changed in Dolibarr and no contact is put aside. Tested on Dolibarr 20.0.4 and 24.0.3,
and against the production API.

## 0.3.0

A contact removed in Wailo keeps its link asleep: if it comes back in Wailo, the same Dolibarr contact is made
active again with a dated note (no duplicate), and only the fields changed in Wailo meanwhile are written. A contact
that was already inactive before its removal stays inactive. A contact created again in Wailo (new id) with the same
e-mail or mobile as exactly one removed contact gets that Dolibarr contact back. A failed return is tried again. While it is
removed and inactive, nothing about it is sent to Wailo; if a Dolibarr user makes it active again, it is sent back to
Wailo as a new contact (usual limits and confirmations).

Dolibarr 20: the API key typed on the setup page was never saved (the 'password' input filter only exists in later
versions), and a contact removed in Wailo was never made inactive (Dolibarr 20 only fills ->statut). Both fixed and
tested on Dolibarr 20.0.4 and 24.0.3.

Official module id 210260, reserved on the Dolibarr wiki (List of modules id), replaces the provisional one.

## 0.2.0

Fixes after an independent review: removal in Wailo makes the Dolibarr contact inactive (never deleted, at most 5 per
pass); pairing at the initial import fills empty fields only; cursor saved after a complete import; three-way change
detection (stored Wailo view); refused contacts in quarantine; public note off by default; key only sent to *.wailo.app;
confirmations bound to the set shown; one entity; lock on key change; safe messages; 429 never waits in a web request;
inactive contacts not sent; unknown directory stops creations; unique link per Dolibarr contact.

## 0.1.0

First version (not published): two-way contact synchronisation, setup page, scheduled job, journal.
