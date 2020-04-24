<?php

namespace DCC\DictionarySearch;

use Exception;
use ExternalModules\AbstractExternalModule;
use \REDCap as REDCap;
use \Security as Security;

/** todo
 * Change required, custom alignment, identifier to appropriate drop downs (if needed)
 * dictionary is still in the global scope and needs to be moved to dSearch object.
 **/

/**
 * Class DictionarySearch
 * @package DCC\DictionarySearch
 */
class DictionarySearch extends AbstractExternalModule
{

    /**
     * @var string[]
     */
    private $dataDictionary;

    /**
     * @var array|string
     */
    private $instrumentNames;

    /**
     * @var boolean| true=has design rights.  False=Does not have designer rights.
     */
    private $designRights;
    /**
     * @var array  Multidimensional Array
     * Array 1 is eventId and contains Array 2 (Key value Array)
     * Array 2 shortName => (true = given at time point.  False=Not at time point)
     *
     * Sample: [166] => Array
     * (
     * [instrument_1] => true
     * [instrument_2] => false
     * [instrument_3] => false
     * [instrument_4] => true
     * )
     */
    private $eventGrid;
    /**
     * @var array|bool|mixed
     */
    private $events;
    /**
     * @var array every instrument has a complete variable.
     */
    private $completedInstrumentVars;
    /**
     * @var false|string
     */
    private $eventGridJSON;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param int $project_id Global Variable set by REDCap for Project ID.
     */
    public function redcap_project_home_page(int $project_id)
    {

    }

    /**
     *  Main action for Dictionary Search
     *  1) Ensures a project is selected
     *  2) Gets the JSON data dictionary
     *  3) displays the HTML form
     *  4) includes necessary JavaScripts.
     */
    public function controller()
    {
        global $project_id;
        if (!isset($project_id) || is_null($project_id)) {
            echo '<h2 class="alert alert-info" style="border-color: #bee5eb !important;">Please select a project</h2>';
            return;
        }
        $this->setDataDictionaryJSON($project_id);

        $this->instrumentNames = REDCap::getInstrumentNames();
        $userRights = REDCap::getUserRights(USERID);
        $user = array_shift($userRights);

        if ($user['design'] == 1) {
            $this->designRights = true;
        } else {
            $this->designRights = false;
        }

        $this->setEvents();

        $this->setInstrumentCompleteVar($this->instrumentNames);

        $this->setEventGrid($project_id, array_keys($this->events));

        $this->renderForm();

        $this->renderEventGrid();

        echo $this->renderScripts();

    }


    /**
     * @param null $project_id
     * @return null
     * @throws Exception
     */
    private function setDataDictionaryJSON($project_id = null)
    {
        if (is_null($project_id)) {
            return null;
        }
        $this->dataDictionary = REDCap::getDataDictionary($project_id, 'json');
    }

    /**
     * @return string[]
     */
    private function getDataDictionary()
    {
        return $this->dataDictionary;
    }


    /**
     * The URL to the JavaScript that powers the HTML search form.
     *
     * @return string
     */
    private function getJSUrl()
    {
        return $this->getUrl("js/search.js");
    }

    /**
     * URL to the instrument in the Online Designer.
     *
     * @return string
     */
    private function getOnlineDesignerURL()
    {
        global $redcap_base_url, $redcap_version, $project_id;
        return $redcap_base_url . 'redcap_v' . $redcap_version . '/Design/online_designer.php?pid=' . $project_id;
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $contents = file_get_contents(__DIR__ . '/html/form.html');
        if ($contents === false) {
            return 'HTML form not found';
        }
        echo $contents;
    }

    private function getInstrumentsNamesJS()
    {
        $js = '<script>dSearch.instrumentNames = new Map();';
        foreach ($this->instrumentNames as $short => $long) {
            $long = str_replace('"', '', $long);
            $js .= 'dSearch.instrumentNames.set("' . $short . '", "' . $long . '");';
        }
        $js .= '</script>';
        return $js;
    }

    private function renderScripts()
    {
        $dictionary = '<script>dSearch.dictionary = ' . $this->getDataDictionary() . ';</script>';
        $jsUrl = '<script src="' . $this->getJSUrl() . '"></script>';
        $designerUrl = '<script>dSearch.designerUrl="' . $this->getOnlineDesignerURL() . '";</script>';
        $designRights = '<script>dSearch.designRights=';
        if ($this->designRights) {
            $designRights .= 'true';
        } else {
            $designRights .= 'false';
        }
        $designRights .= '</script>';

        return $this->dSearchJsObject() . PHP_EOL .
            $this->getInstrumentsNamesJS() . PHP_EOL .
            $dictionary . PHP_EOL .
            $jsUrl . PHP_EOL .
            $designerUrl . PHP_EOL .
            $designRights . PHP_EOL .
            $this->getEventGridJS($this->eventGrid) . PHP_EOL;
    }

    private function dSearchJsObject()
    {
        return '<script>var dSearch = {};</script>';
    }

    private function setInstrumentCompleteVar($instrumentNames)
    {
        $this->completedInstrumentVars = [];
        foreach ($instrumentNames as $shortName => $longName) {
            $this->completedInstrumentVars[$shortName] = $shortName . '_complete';
        }
    }

    private function setEvents()
    {
        $this->events = REDCap::getEventNames(true, false);
    }


    private function setEventGrid($project_id, $eventIds)
    {
        global $project_id;
        // Check if project is longitudinal first
        if (!REDCap::isLongitudinal()) {
            return null;
        }

        $this->eventGrid = [];

        foreach ($eventIds as $eventId) {
            $allFieldsByEvent = REDCap::getValidFieldsByEvents($project_id, $eventId);
            foreach ($this->completedInstrumentVars as $shortName => $complete) {
                $this->eventGrid[$eventId][$shortName] = false;
                if (in_array($complete, $allFieldsByEvent)) {
                    $this->eventGrid[$eventId][$shortName] = true;
                }
            }
        }
    }

    private function getEventGrid()
    {
        return $this->eventGrid();
    }

    public function renderEventGrid()
    {
        $eventTable = "<table class='table table-bordered'><tr><td>Event</td>";
        foreach ($this->instrumentNames as $shortName => $longName) {
            $eventTable .= "<th data-form-name='" . $shortName . "'>" . $longName . '</th>';
        }
        $eventTable .= "</tr>";

        foreach ($this->eventGrid as $eventId => $formEvents) {

            $eventTable .= "<tr><td data-event='" . $eventId . "'>" .
                $this->events[$eventId] .
                "</td>";
            foreach ($formEvents as $form => $hasEvent) {
                if ($hasEvent) {
                    $eventTable .= "<td>Y</td>";
                } else {
                    $eventTable .= "<td>N</td>";
                }
            }
            $eventTable .= "</tr>";
        }
        $eventTable .= "</table>";
        echo $eventTable;
    }

    private function getEventGridJS($eventGrid)
    {
        $this->eventGridJSON = json_encode($eventGrid);
        $eventGrid = '<script>dSearch.eventGrid=' . $this->eventGridJSON . ';</script>';
        return $eventGrid;
    }
}