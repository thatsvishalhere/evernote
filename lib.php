<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Portfolio to access and add Evernote content.
 *
 * @package    portfolio
 * @subpackage evernote
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/portfolio/plugin.php');
require_once($CFG->libdir . '/oauthlib.php');
require_once( __DIR__ .'/lib/evernote/Evernote/Client.php');
require_once( __DIR__ .'/lib/evernote/packages/Types/Types_types.php');

// Import the classes that we're going to be using.
use EDAM\Error\EDAMSystemException,
    EDAM\Error\EDAMUserException,
    EDAM\Error\EDAMErrorCode,
    EDAM\Error\EDAMNotFoundException;

// To use the main Evernote API interface.
use Evernote\Client;

// Import Note class.
use EDAM\Types\Note;
use EDAM\Types\NoteAttributes;
use EDAM\NoteStore\NoteStoreClient;

// Import the Resources Classes for attachments.
use EDAM\Types\Resource;
use EDAM\Types\ResourceAttributes;
use EDAM\Types\Data;

// Import Userstore.
use EDAM\Userstore\UserStoreClient;

/**
 * Portfolio class to access/add notes to the Evernote accounts.
 *
 * This class uses the Evernote API in the library to access and then
 * Add to the Evernote accounts of the users after modifying the contents for the
 * Evernote Markup Language.
 *
 * @package    portfolio_evernote
 * @category   portfolio
 * @copyright  2013 Vishal Raheja
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class portfolio_plugin_evernote extends portfolio_plugin_push_base {
    private $test;
    private $authorizeurl;
    private $resourcearray = array();
    /**
     * URL to the API.
     * Production services: https://www.evernote.com
     * Development services: https://sandbox.evernote.com
     * @var string
     */
    private $api = 'https://sandbox.evernote.com';

    /**
     * The oauth_helper for oauth authentication of Evernote.
     * @var oauth_helper
     */
    private $oauth;

    /**
     * Note store URL retrieved after the user authenticates the application.
     * @var string
     */
    private $notestoreurl = null;

    /**
     * Notestore of the user.
     * @var NoteStoreClient
     */
    private $notestore;

    /**
     * Prefix for the user preferences.
     * @var string
     */
    private $settingprefix = 'evernote_';

    /**
     * Token received after a valid OAuth authentication.
     * @var string
     */
    private $accesstoken = null;

    /**
     * Token received from Evernote after checking the user credentials and the permissions by the user.
     * @var string
     */
    private $oauthtoken = null;

    /**
     * To create a new client which would contact the Evernote.
     * @var Client Object
     */
    private $client = null;

    /**
     * Token after the checking the config credentials provided by the administrator.
     * @var string
     */
    private $requesttoken = null;

    /**
     * The evernote markup language content that is to be shown in the notebook.
     * @var string
     */
    private $enmlcontent;

    /**
     * The verifier string if the user allows the application to access his evernote account.
     * @var string
     */
    private $oauthverifier;

    /**
     * Notebooks in the Evernote account of the user.
     * @var array
     */
    private $notebookarray;

    /**
     * The user's default notebook guid.
     * @var string
     */
    private $defaultnotebookguid;

    /**
     * Sandbox flag. Turn it to false for production.
     * @var boolean
     */
    private $sandboxflag = true;

    /**
     * Constructor
     *
     * @param int $instanceid id of plugin instance to construct
     * @param mixed $record stdclass object or named array - use this if you already have the record to avoid another query
     */
    public function __construct($instanceid, $record=null) {
        parent::__construct($instanceid, $record);

        $this->accesstoken = get_user_preferences($this->settingprefix.'accesstoken', null);
        $this->notestoreurl = get_user_preferences($this->settingprefix.'notestoreurl', null);
    }

    public static function allows_multiple_exports() {
        // Since this plugin needs authentication from external sources, we have to disable this.
        return false;
    }

    public static function get_name() {
        return get_string('pluginname', 'portfolio_evernote');
    }

    public function has_export_config() {
        // To ensure that the user configures before exporting the package.
        return true;
    }

    public function export_config_form(&$mform) {
        global $CFG;
        $signin = optional_param('signin', 0, PARAM_BOOL);
        $returnurl = new moodle_url('/portfolio/add.php');
        $returnurl->param('id', $this->exporter->get('id'));
        $returnurl->param('sesskey', sesskey());
        $returnurl->param('signin', 1);

        // If the user wants to sign into another account, cancel the export and reset the variables.
        if ($signin) {
            set_user_preference($this->settingprefix.'tokensecret', '');
            set_user_preference($this->settingprefix.'accesstoken', '');
            set_user_preference($this->settingprefix.'notestoreurl', '');
            $returnurl->param('cancel', 1);
            $returnurl->param('cancelsure', 1);
            redirect($returnurl);
        }
        $user = $this->get_userstore()->getUser($this->accesstoken);
        $mform->addElement('static', 'plugin_username', 'Evernote User account ', $user->username);
        $mform->addElement('static', 'plugin_signinusername', '',
            '<a href="'.$returnurl.'">'.get_string('signinanother', 'portfolio_evernote').'</a>');
        $mform->addElement('text', 'plugin_notetitle', get_string('customnotetitlelabel', 'portfolio_evernote'));
        $mform->setDefault('plugin_notetitle', get_string('defaultnotetitle', 'portfolio_evernote'));
        $this->notebookarray = $this->list_notebooks();
        $notebookselect = $mform->addElement('select', 'plugin_notebooks', 'Select Notebook', $this->notebookarray);
        $notebookselect->setSelected($this->defaultnotebookguid);
        $strrequired = get_string('required');
        $mform->addRule('plugin_notetitle', $strrequired, 'required', null, 'client');
        $mform->addRule('plugin_notebooks', $strrequired, 'required', null, 'client');
    }

    public function get_export_summary() {
        return array(
            'Evernote Username'=>$this->get_userstore()->getUser($this->accesstoken)->username,
            'Note Title'=>'<h3>'.$this->get_export_config('notetitle').'</h3>',
            'Notebook'=>$this->notebookarray[$this->get_export_config('notebooks')]
        );
    }

    public function get_allowed_export_config() {
        return array('notetitle', 'notebooks');
    }

    public function prepare_package() {
        return true;
    }

    public function send_package() {
        $files = $this->exporter->get_tempfiles();
        $writefile = "write.txt";
        $current = file_get_contents($writefile);
        foreach ($files as $file) {
            // Get the enml of only those files which have been modified content of the current page and converted into html.
            if ($file->get_filepath() == "/") {
                if ($file->get_mimetype()=='text/html') {
                    $htmlcontents = $file->get_content();
                    $this->enmlcontent = $this->getenml($htmlcontents);
                } else {
                    // For Milestone 2.
                }
            } else {
                // For Milestone 2.
            }
        }
        $this->create_note();
        $this->resourcearray = null;
        return true;
    }

    // Unsure about it.
    public function get_interactive_continue_url() {
        return false;
    }

    public function expected_time($callertime) {
        // We trust what the portfolio says.
        return $callertime;
    }

    public static function has_admin_config() {
        return true;
    }

    public static function admin_config_form(&$mform) {
        $mform->addElement('static', null, '', get_string('oauthinfo', 'portfolio_evernote'));
        $mform->addElement('text', 'consumerkey', get_string('consumerkey', 'portfolio_evernote'));
        $mform->setType('consumerkey', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'secret', get_string('secret', 'portfolio_evernote'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);
        $strrequired = get_string('required');
        $mform->addRule('consumerkey', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }

    public static function get_allowed_config() {
        return array('consumerkey', 'secret');
    }

    public static function allows_multiple_instances() {
        return false;
    }

    public function supported_formats() {
        return array(/*PORTFOLIO_FORMAT_RICHHTML, */PORTFOLIO_FORMAT_PLAINHTML);
    }

    public function steal_control($stage) {
        global $CFG;
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }

        // First stage of oauth from the user configuration.
        $this->get_token_from_config();
    }

    public function post_control($stage, $params) {
        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return;
        }

        // Second and third (final) steps for oauth authentication.
        // Storing the access credentials for the user.
        $this->get_token_credentials();
    }

    public function instance_sanity_check() {
        $consumerkey = $this->get_config('consumerkey');
        $secret = $this->get_config('secret');

        // If there is no oauth config
        // there will be no config and this plugin should be disabled.
        if (empty($consumerkey) or empty($secret)) {
            return 'nooauthcredentials';
        }
        return 0;
    }

    /**
     * To get the token by verifying the Evernote API Consumer key and Secret
     */
    private function get_token_from_config() {
        $result = $this->get_oauth()->request_token();
        $this->authorizeurl = $result['authorize_url'];
        if (get_user_preferences($this->settingprefix.'tokensecret', '') == null) {
            set_user_preference($this->settingprefix.'tokensecret', $result['oauth_token_secret']);
            set_user_preference($this->settingprefix.'accesstoken', '');
            redirect($this->authorizeurl);
        }
    }

    /**
     * To get the token for the user after the user has granted access to his Evernote account.
     */
    private function get_token_credentials() {
        $token  = optional_param('oauth_token', '', PARAM_TEXT);
        $verifier  = optional_param('oauth_verifier', '', PARAM_TEXT);
        $secret = get_user_preferences($this->settingprefix.'tokensecret', '');

        // Set the user variables if the user grants access, else reset the values.
        if ($verifier != null) {
            $access = $this->get_oauth()->get_access_token($token, $secret, $verifier);
            $this->test = $access;
            $notestore  = $access['edam_noteStoreUrl'];
            $userid  = $access['edam_userId'];
            $accesstoken  = $access['oauth_token'];
            $this->accesstoken = $accesstoken;
            $this->notestoreurl = $notestore;
            set_user_preference($this->settingprefix.'accesstoken', $accesstoken);
            set_user_preference($this->settingprefix.'notestoreurl', $notestore);
            set_user_preference($this->settingprefix.'userid', $userid);
        } else {
            set_user_preference($this->settingprefix.'tokensecret', '');
            throw new portfolio_plugin_exception('nopermission', 'portfolio_evernote');
        }
    }

    /**
     * Create the note from the class variable of enml content.
     */
    protected function create_note() {
        $note = new Note();
        $note->title = $this->get_export_config('notetitle');
        $note->content = $this->enmlcontent;
        $note->resources = $this->resourcearray;
        $note->notebookGuid = $this->get_export_config('notebooks');
        $notebooks = $this->get_notestore()->createNote($this->accesstoken, $note);
    }

    /**
     * To get the list of the user's notebooks.
     * @return array of Evernote Notebooks
     */
    protected function list_notebooks() {
        $notebooks = $this->get_notestore()->listNotebooks($this->accesstoken);
        $notebookarray = array();
        if (!empty($notebooks)) {
            foreach ($notebooks as $notebook) {
                if ($notebook->defaultNotebook) {
                    $this->defaultnotebookguid = $notebook->guid;
                    $notebookarray[$notebook->guid] = get_string('denotedefaultnotebook', 'portfolio_evernote', $notebook->name);
                } else {
                    $notebookarray[$notebook->guid] = $notebook->name;
                }
            }
        }
        return $notebookarray;
    }

    /**
     * To convert HTML content into ENML content.
     *
     * @param string $htmlcontents HTML string that is needed to convert to ENML.
     * @return string  ENML string of the given HTML string.
     */
    protected function getenml($htmlcontents) {
        $htmlcontents = strip_tags($htmlcontents, '<a><abbr><acronym><address><area><b><bdo><big><blockquote><br><caption>'.
            '<center><cite><code><col><colgroup><dd><del><dfn><div><dl><dt><em><font><h1><h2><h3><h4><h5><h6><hr><i><img><ins>'.
            '<kbd><li><map><ol><p><pre><q><s><samp><small><span><strike><strong><sub><sup><table><tbody><td><tfoot><th><thead>'.
            '<title><tr><tt><u><ul><var><xmp>');
        $htmlcontents = str_replace("<br/ >", "", $htmlcontents);
        // Removing the disallowed attributes.
        $htmlcontents = '<body>'.$htmlcontents.'</body>';
        $dom = new DOMDocument();
        $dom->loadHTML($htmlcontents);
        $elements = $dom->getElementsByTagName('body')->item(0);
        $elements = $this->reform_style_attribute($elements);
        $htmlcontents = $this->get_inner_html($elements);
        $enmlcontents = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<!DOCTYPE en-note SYSTEM "http://xml.evernote.com/pub/enml2.dtd">' .
            '<en-note>'.$htmlcontents.'</en-note>';
        return $enmlcontents;
    }

    /**
     * Subsidiary function to get the inner html of the DOM element.
     *
     * @param DOM element $node for which we need the inner html.
     * @return string of the inner html of the DOM element given.
     */
    protected function get_inner_html( $node ) {
        $innerhtml= '';
        if ($node->childNodes!==null) {
            $children = $node->childNodes;
            foreach ($children as $child) {
                $innerhtml .= $child->ownerDocument->saveXML( $child );
            }
        }
        return $innerhtml;
    }

    /**
     * Subsidiary function to remove all the unsupported attributes but to retain some font attributes from the style attribute.
     *
     * @param DOM element $elements
     * @return DOM element without the unsupported attributes and the necessary font effects retained by modifying the inner html.
     */
    protected function reform_style_attribute ($elements) {
        if ($elements->childNodes!==null) {
            $numchildnodes = $elements->childNodes->length;
            for ($i=0; $i<$numchildnodes; $i++) {
                $element = $this->reform_style_attribute($elements->childNodes->item(0));
                $elements->removeChild($elements->childNodes->item(0));
                $elements->appendChild($element);
            }
            if ($elements->hasAttribute('class')) {
                $elements->removeAttribute('class');
            }
            if ($elements->hasAttribute('id')) {
                $elements->removeAttribute('id');
            }
            if ($elements->hasAttribute('onclick')) {
                $elements->removeAttribute('onclick');
            }
            if ($elements->hasAttribute('ondblclick')) {
                $elements->removeAttribute('ondblclick');
            }
            if ($elements->hasAttribute('accesskey')) {
                $elements->removeAttribute('accesskey');
            }
            if ($elements->hasAttribute('data')) {
                $elements->removeAttribute('data');
            }
            if ($elements->hasAttribute('dynsrc')) {
                $elements->removeAttribute('dynsrc');
            }
            if ($elements->hasAttribute('tabindex')) {
                $elements->removeAttribute('tabindex');
            }
        }
        return $elements;
    }

    /**
     * Get the OAuth object.
     *
     * @return oauth_helper object.
     */
    private function get_oauth() {
        if (empty($this->oauth)) {
            $callbackurl = new moodle_url('/portfolio/add.php', array(
                    'postcontrol' => '1',
                    'type' => 'evernote'
                ));
            $args['oauth_consumer_key'] = $this->get_config('consumerkey');
            $args['oauth_consumer_secret'] = $this->get_config('secret');
            $args['oauth_callback'] = $callbackurl->out(false);
            $args['api_root'] = $this->api;
            $args['request_token_api'] = $this->api . '/oauth';
            $args['access_token_api'] = $this->api . '/oauth';
            $args['authorize_url'] = $this->api . '/OAuth.action';
            $this->oauth = new oauth_helper($args);
        }
        return $this->oauth;
    }

    /**
     * Return the object to call Evernote's API
     *
     * @return NoteStoreClient object
     */
    protected function get_notestore() {
        if (empty($this->notestore)) {
            $parts = parse_url($this->notestoreurl);
            if (!isset($parts['port'])) {
                if ($parts['scheme'] === 'https') {
                    $parts['port'] = 443;
                } else {
                    $parts['port'] = 80;
                }
            }
            $notestorehttpclient = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $notestoreprotocol = new TBinaryProtocol($notestorehttpclient);
            $this->notestore = new NoteStoreClient($notestoreprotocol, $notestoreprotocol);
        }
        return $this->notestore;
    }

    /**
     * Return the userstore of the user to get the username
     *
     * @return UserStoreClient object
     */
    protected function get_userstore() {
        $url = $this->api."/edam/user";
        if (empty($this->userstore)) {
            $parts = parse_url($url);
            if (!isset($parts['port'])) {
                if ($parts['scheme'] === 'https') {
                    $parts['port'] = 443;
                } else {
                    $parts['port'] = 80;
                }
            }
            $userstorehttpclient = new THttpClient($parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
            $userstoreprotocol = new TBinaryProtocol($userstorehttpclient);
            $this->userstore = new UserStoreClient($userstoreprotocol, $userstoreprotocol);
        }
        return $this->userstore;
    }
}