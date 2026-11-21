<?php

/* Copyright (C) 2026 Wailo <https://wailo.ai>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \defgroup   wailo     Module Wailo for Dolibarr
 * \brief      Two-way synchronisation of Dolibarr contacts with Wailo.
 *
 * \file       wailo/core/modules/modWailo.class.php
 * \ingroup    wailo
 * \brief      Description and activation file for module Wailo
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Wailo
 */
class modWailo extends DolibarrModules
{
    /**
     * Constructor. Define names, constants, directories, boxes, permissions
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;

        // Provisional id for development (> 500000). To be replaced by a reserved id before publication.
        $this->numero = 210260;
        $this->rights_class = 'wailo';
        $this->family = 'crm';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'WailoModuleDesc';
        $this->descriptionlong = 'WailoModuleDescLong';
        $this->editor_name = 'Wailo';
        $this->editor_url = 'https://wailo.ai';
        $this->editor_squarred_logo = 'wailo_logo.png@wailo';
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'wailo@wailo';

        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array(),
            'moduleforexternal' => 0,
        );

        // Data directory (DOL_DATA_ROOT/wailo/temp): only used for the lock file of a pass.
        $this->dirs = array('/wailo/temp');
        $this->config_page_url = array('setup.php@wailo');

        $this->hidden = false;
        $this->depends = array('modSociete');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('wailo@wailo');
        $this->phpmin = array(7, 2);
        $this->need_dolibarr_version = array(20, 0);
        $this->need_javascript_ajax = 0;
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // Constants: name, type, value, description, visible, entity ('current'), delete on unactive
        $this->const = array(
            array('WAILO_API_URL', 'chaine', 'https://api.wailo.app/api/public/v1', 'Wailo API base URL', 0, 'current', 0),
            array('WAILO_DIRECTORY', 'chaine', 'Clients', 'Wailo directory for contacts created in Dolibarr', 0, 'current', 0),
            array('WAILO_SYNC_NOTE', 'chaine', '0', 'Synchronise the public note (off by default)', 0, 'current', 0),
        );

        if (!isModEnabled('wailo')) {
            $conf->wailo = new stdClass();
            $conf->wailo->enabled = 0;
        }

        $this->tabs = array();
        $this->dictionaries = array();
        $this->boxes = array();

        // Scheduled job: one pass every 5 minutes (Wailo guide: every 1 to 5 minutes).
        // Runs only when the Dolibarr scheduler (module Cron + system cron calling cron_run_jobs.php) is set up.
        $this->cronjobs = array(
            0 => array(
                'label' => 'WailoCronSyncLabel',
                'jobtype' => 'method',
                'class' => '/wailo/class/wailosync.class.php',
                'objectname' => 'WailoSync',
                'method' => 'runScheduledSync',
                'parameters' => '',
                'comment' => 'WailoCronSyncComment',
                'frequency' => 5,
                'unitfrequency' => 60,
                'status' => 1,
                'test' => 'isModEnabled("wailo")',
                'priority' => 50,
            ),
        );

        // No permission of its own: setup and manual sync are reserved to Dolibarr administrators.
        $this->rights = array();
        $this->menu = array();
    }

    /**
     * Function called when module is enabled.
     * Creates the module tables (sql/ directory), constants, directories and scheduled job.
     *
     * @param string $options Options when enabling module ('', 'noboxes')
     * @return int<-1,1>      1 if OK, <=0 if KO
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/wailo/sql/');
        if ($result < 0) {
            return -1;
        }

        $this->remove($options);

        $sql = array();

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Tables, links and settings are kept: re-enabling the module resumes the synchronisation.
     *
     * @param string $options Options when disabling module ('', 'noboxes')
     * @return int<-1,1>      1 if OK, <=0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
