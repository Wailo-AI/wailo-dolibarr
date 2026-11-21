-- Copyright (C) 2026 Wailo <https://wailo.ai>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.

-- One row per Wailo contact linked to a Dolibarr contact (llx_socpeople).
-- doli_view = last state of the Dolibarr contact sent or written, in Wailo fields (JSON): detects Dolibarr changes.
-- wailo_view = last state of the Wailo contact seen, same fields (JSON): detects Wailo changes (three-way).
-- removed_at = set when the contact was removed in Wailo (Dolibarr contact made inactive): the link sleeps until it comes back.
-- deactivated_by_sync = 1 when the sync made the contact inactive at the removal (0: it was already inactive by choice).
CREATE TABLE llx_wailo_link(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	wailo_id varchar(64) NOT NULL,
	fk_socpeople integer NOT NULL,
	etag varchar(128),
	placeholder varchar(255),
	phone_digits text,
	doli_view text,
	wailo_view text,
	removed_at datetime,
	deactivated_by_sync smallint DEFAULT 0 NOT NULL,
	datec datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
