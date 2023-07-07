<?php

use dokuwiki\Utf8\PhpString;

/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 * @author     Gina Häußge <osd@foosel.net>
 */

class helper_plugin_pagelist extends DokuWiki_Plugin
{

    /** @var string table style: 'default', 'table', 'list'*/
    protected $style = '';
    /** @var bool whether heading line is shown */
    protected $showheader = false;
    /** @var bool whether first headline/title is shown in the page column */
    protected $showfirsthl = false;

    /**
     * @var array with entries: 'columnname' => bool/int enabled/disable column, something also config setting
     * @deprecated 2022-08-17 still public, will change to protected
     */
    public $column = [];
    /**
     * @var array with entries: 'columnname' => language strings for table headers as html for in th
     * @deprecated 2022-08-17 still public, will change to protected
     */
    public $header = [];

    /**
     * must contain a value to key 'id', can further contain as well :
     * 'title', 'date', 'user', 'desc', 'comments', 'tags', 'status' and 'priority', see addPage() for details
     *
     * @var null|array with entries: 'columnname' => value or if plugin html for in cell, null if no lines processed
     * @deprecated 2022-08-17 still public, will change to protected
     */
    public $page = null;

    /**
     * setting handled here to combine config with flags, but used outside the helpe
     * @var bool alphabetical sort of pages by pagename
     * @deprecated 2022-08-17 still public, will change to protected
     */
    public $sort = false;
    /**
     * setting handled here to combine config with flags, but used outside the helper
     * @var bool reverse alphabetical sort of pages by pagename
     * @deprecated 2022-08-17 still public, will change to protected
     */
    public $rsort = false;

    /**
     * 'pluginname' key of array, and is therefore unique, so max one column per plugin?
     * @var array with entries: 'pluginname' => ['columnname', 'columnname2']
     */
    protected $plugins = []; // array of plugins to extend the pagelist

    /** @var string final html output */
    protected $doc = '';

    /** @var null|mixed data retrieved from metadata array */
    protected $meta = null;

    /** @var array @deprecated 2022-08-17 still used by very old plugins */
    public $_meta = null;

    /** @var helper_plugin_pageimage */
    protected $pageimage = null;
    /** @var helper_plugin_discussion */
    protected $discussion = null;
    /** @var helper_plugin_linkback */
    protected $linkback = null;
    /** @var helper_plugin_tag */
    protected $tag = null;

    /**
     * Constructor gets default preferences TODO header array is not reset?
     *
     * These can be overriden by plugins using this class
     */
    public function __construct()
    {
        $this->style = $this->getConf('style'); //string
        $this->showheader = $this->getConf('showheader'); //on-off
        $this->showfirsthl = $this->getConf('showfirsthl'); //on-off
        $this->sort = $this->getConf('sort'); //on-off
        $this->rsort = $this->getConf('rsort'); //on-off

        $this->plugins = [
            'discussion' => ['comments'],
            'linkback' => ['linkbacks'],
            'tag' => ['tags'],
            'pageimage' => ['image'],
        ];

        $this->column = [
            'page' => true,
            'date' => $this->getConf('showdate'), //0,1,2
            'user' => $this->getConf('showuser'), //0,1,2,3,4
            'desc' => $this->getConf('showdesc'), //0,160,500
            'comments' => $this->getConf('showcomments'), //on-off
            'linkbacks' => $this->getConf('showlinkbacks'), //on-off
            'tags' => $this->getConf('showtags'), //on-off
            'image' => $this->getConf('showimage'), //on-off
            'diff' => $this->getConf('showdiff'), //on-off
        ];

        $this->header = [];
    }

    public function getMethods()
    {
        $result = [];
        $result[] = [
            'name' => 'addColumn',
            'desc' => '(optional) adds an extra column for plugin data',
            'params' => [
                'plugin name' => 'string',
                'column key' => 'string'
            ],
        ];
        $result[] = [
            'name' => 'modifyColumn',
            'desc' => '(optional) override value of an existing column, value equal to false disables column',
            'params' => [
                'column key' => 'string',
                'value' => 'int|bool'
            ],
        ];
        $result[] = [
            'name' => 'setHeader',
            'desc' => '(optional) Provide header data, if not given default values or [plugin]->th() is used',
            'params' => [
                'column key' => 'string',
                'value' => 'int|bool'
            ],
        ];        $result[] = [
            'name' => 'setFlags',
            'desc' => '(optional) overrides default flags, or en/disable existing columns',
            'params' => ['flags' => 'array'],
            'return' => ['success' => 'boolean'],
        ];
        $result[] = [
            'name' => 'startList',
            'desc' => '(required) prepares the table header for the page list',
        ];
        $result[] = [
            'name' => 'addPage',
            'desc' => '(required) adds a page to the list',
            'params' => ["page attributes, 'id' required, others optional" => 'array'],
        ];
        $result[] = [
            'name' => 'finishList',
            'desc' => '(required) returns the XHTML output',
            'return' => ['xhtml' => 'string'],
        ];
        return $result;
    }

    /**
     * (optional) Adds an extra column named $col for plugin $plugin.
     * The data for the extra column is provided via:
     *    1) extra entry to setHeader([]) or addPage([])
     *    2) or, alternatively, by the plugin $plugin that implements a helper component with the functions:
     *  - th($col, &$class=null) or th()
     *  - td($id, $col=null, &$class=null) or td($id)
     *
     *
     * @param string $plugin plugin name
     * @param string $col column name. Assumption: unique between all builtin columns and plugin supplied columns
     */
    public function addColumn($plugin, $col)
    {
        //prevent duplicates if adding a column of already listed plugins
        if(!isset($this->plugins[$plugin]) || !in_array($col, $this->plugins[$plugin])) {
            $this->plugins[$plugin][] = $col;
        }
        $this->column[$col] = true;
    }

    /**
     * (optional) Allow to override the column values e.g. to disable a column
     *
     * @param string $col column name
     * @param int|bool $value must evaluate to false/true for dis/enabling column. Sometimes value is used for specific setting
     * @see $column
     */
    public function modifyColumn($col, $value)
    {
        if(isset($this->column[$col])) {
            $this->column[$col] = $value;
        }
    }

    /**
     * (optional) Provide header data, if not given for built-in columns localized strings are used, or for plugins the th() function
     * @see $column the keys of $header should match the keys of $column
     *
     * @param array $header entries, if not given default values or plugin->th() is used
     * @return void
     */
    public function setHeader($header) {
        if(is_array($header)) {
            $this->header = $header;
        }
    }

    /**
     * (Optional) Overrides standard values for style, showheader and show(column) settings
     *
     * @param string[] $flags
     *  possible flags:
     *     for styling: 'default', 'table', 'list', 'simplelist'
     *     for dis/enabling header: '(no)header', and show titel for page column with '(no)firsthl',
     *     for sorting: 'sort', 'rsort', 'nosort'
     *     for dis/enabling columns: accepts keys of $column, e.g. default: 'date', 'user', 'desc', 'comments', 'linkbacks', 'tags', 'image', 'diff'
     * @return bool, false if no array given
     */
    public function setFlags($flags)
    {
        if (!is_array($flags)) return false;

        foreach ($flags as $flag) {
            switch ($flag) {
                case 'default':
                    $this->style = 'default';
                    break;
                case 'table':
                    $this->style = 'table';
                    break;
                case 'list':
                    $this->style = 'list';
                    break;
                case 'simplelist':
                    $this->style = 'simplelist'; // Displays pagenames only, no other information
                    break;
                case 'header':
                    $this->showheader = true;
                    break;
                case 'noheader':
                    $this->showheader = false;
                    break;
                case 'firsthl':
                    $this->showfirsthl = true;
                    break;
                case 'nofirsthl':
                    $this->showfirsthl = false;
                    break;
                case 'sort':
                    $this->sort = true;
                    $this->rsort = false;
                    break;
                case 'rsort':
                    $this->sort = false;
                    $this->rsort = true;
                    break;
                case 'nosort':
                    $this->sort = false;
                    $this->rsort = false;
                    break;
                case 'showdiff':
                    $flag = 'diff';
                    break;
            }

            /** @see $column array, enable/disable columns */
            if (substr($flag, 0, 2) == 'no') {
                $value = false;
                $flag = substr($flag, 2);
            } else {
                $value = true;
            }

            if(isset($this->column[$flag]) && $flag !== 'page') {
                $this->column[$flag] = $value;
            }
        }
        return true;
    }

    /**
     * (required) Sets the list header
     *
     * @param null|string $callerClass
     * @return bool
     */
    public function startList($callerClass = null)
    {

        // table style
        switch ($this->style) {
            case 'table':
                $class = 'inline';
                break;
            case 'list':
                $class = 'ul';
                break;
            case 'simplelist':
                $class = false;
                break;
            default:
                $class = 'pagelist';
        }

        if ($class) {
            if ($callerClass) {
                $class .= ' ' . $callerClass;
            }
            $this->doc = '<div class="table"><table class="' . $class . '">';
        } else {
            // Simplelist is enabled; Skip header and firsthl
            $this->showheader = false;
            $this->showfirsthl = false;
            //$this->doc .= DOKU_LF.DOKU_TAB.'</tr>'.DOKU_LF;
            $this->doc = '<ul>';
        }

        $this->page = null;

        // check if some plugins are available - if yes, load them!
        foreach ($this->plugins as $plugin => $columns) {
            foreach($columns as $col) {
                if (!$this->column[$col]) continue;

                if (!$this->$plugin = $this->loadHelper($plugin)) {
                    $this->column[$col] = false;
                }
            }
        }

        // header row
        if ($this->showheader) {
            $this->doc .= '<tr>';
            $columns = ['page', 'date', 'user', 'desc', 'diff'];
            //image column first
            if ($this->column['image']) {
                if (empty($this->header['image'])) {
                    $this->header['image'] = hsc($this->pageimage->th('image'));
                }
                $this->doc .= '<th class="images">' . $this->header['image'] . '</th>';
            }
            //pagelist columns
            foreach ($columns as $col) {
                if ($this->column[$col]) {
                    if (empty($this->header[$col])) {
                        $this->header[$col] = hsc($this->getLang($col));
                    }
                    $this->doc .= '<th class="' . $col . '">' . $this->header[$col] . '</th>';
                }
            }
            //plugin columns
            foreach ($this->plugins as $plugin => $columns) {
                foreach ($columns as $col) {
                    if ($this->column[$col] && $col != 'image') {
                        if (empty($this->header[$col])) {
                            $this->header[$col] = hsc($this->$plugin->th($col, $class));
                        }
                        $this->doc .= '<th class="' . $col . '">' . $this->header[$col] . '</th>';
                    }
                }
            }
            $this->doc .= '</tr>';
        }
        return true;
    }

    /**
     * (required) Prints html of a list row, call for every row
     *
     * @param array $page
     *       'id'     => string page id
     *       'title'  => string First headline, otherwise page id TODO always replaced by id or title from metadata?
     *       'titleimage' => string media id
     *       'date'   => int timestamp of creation date, otherwise modification date (e.g. sometimes needed for plugin)
     *       'user'   => string $meta['creator']
     *       'desc'   => string $meta['description']['abstract']
     *       'description' => string description set via pagelist syntax
     *       'exists' => bool page_exists($id)
     *       'perm'   => int auth_quickaclcheck($id)
     *       'draft'  => string $meta['type'] set by blog plugin
     *       'priority' => string priority of task: 'low', 'medium', 'high', 'critical'
     *       'class'  => string class set for each row
     *       'file'   => string wikiFN($id)
     *       'section' => string id of section, added as #ancher to page url
     *    further key-value pairs for columns set by plugins (optional), if not defined th() and td() of plugin are called
     * @return bool, false if no id given
     */
    public function addPage($page)
    {

        $id = $page['id'];
        if (!$id) return false;

        $this->page = $page;
        $this->meta = null;

        if ($this->style != 'simplelist') {
            // priority and draft
            if (!isset($this->page['draft'])) {
                $this->page['draft'] = ($this->getMeta('type') == 'draft');
            }
            $class = '';
            if (isset($this->page['priority'])) {
                $class .= 'priority' . $this->page['priority'] . ' ';
            }
            if (!empty($this->page['draft'])) {
                $class .= 'draft ';
            }
            if (!empty($this->page['class'])) {
                $class .= $this->page['class'];
            }

            if (!empty($class)) {
                $class = ' class="' . $class . '"';
            }

            $this->doc .= '<tr' . $class . '>';
            //image column first
            if (!empty($this->column['image'])) {
                $this->pluginCell('pageimage', 'image', $id);
            }
            $this->pageCell($id);

            if (!empty($this->column['date'])) {
                $this->dateCell();
            }
            if (!empty($this->column['user'])) {
                $this->userCell();
            }
            if (!empty($this->column['desc'])) {
                $this->descriptionCell();
            }
            if (!empty($this->column['diff'])) {
                $this->diffCell($id);
            }
            foreach ($this->plugins as $plugin => $columns) {
                foreach ($columns as $col) {
                    if (!empty($this->column[$col]) && $col != 'image') {
                        $this->pluginCell($plugin, $col, $id);
                    }
                }
            }

            $this->doc .= '</tr>';
        } else {
            // simplelist is enabled; just output pagename
            $this->doc .= '<li>';
            if (page_exists($id)) {
                $class = 'wikilink1';
            } else {
                $class = 'wikilink2';
            }

            if (empty($this->page['title'])) {
                $this->page['title'] = str_replace('_', ' ', noNS($id));
            }
            $title = hsc($this->page['title']);

            $content = '<a href="' . wl($id) . '" class="' . $class . '" title="' . $id . '">' . $title . '</a>';
            $this->doc .= $content;
            $this->doc .= '</li>';
        }

        return true;
    }

    /**
     * (required) Sets the list footer, reset helper to defaults
     *
     * @return string html
     */
    public function finishList()
    {
        if ($this->style != 'simplelist') {
            if (!isset($this->page)) {
                $this->doc = '';
            } else {
                $this->doc .= '</table></div>';
            }
        } else {
            $this->doc .= '</ul>';
        }

        // reset defaults
        $this->__construct();

        return $this->doc;
    }

    /* ---------- Private Methods ---------- */

    /**
     * Page title / link to page
     *
     * @param string $id page id displayed in this table row
     * @return bool whether empty
     */
    protected function pageCell($id)
    {

        // check for page existence
        if (!isset($this->page['exists'])) {
            if (!isset($this->page['file'])) {
                $this->page['file'] = wikiFN($id);
            }
            $this->page['exists'] = @file_exists($this->page['file']);
        }
        if ($this->page['exists']) {
            $class = 'wikilink1';
        } else {
            $class = 'wikilink2';
        }

        // handle image and text titles
        if (!empty($this->page['titleimage'])) {
            $title = '<img src="' . ml($this->page['titleimage']) . '" class="media"';
            if (!empty($this->page['title'])) {
                $title .= ' title="' . hsc($this->page['title']) . '" alt="' . hsc($this->page['title']) . '"';
            }
            $title .= ' />';
        } else {
            //not overwrite titles in earlier provided data
            if (!$this->page['title'] && $this->showfirsthl) {
                $this->page['title'] = $this->getMeta('title');
            }

            if (!$this->page['title']) {
                $this->page['title'] = str_replace('_', ' ', noNSorNS($id));
            }
            $title = hsc($this->page['title']);
        }

        // produce output
        $content = '<a href="' . wl($id) . (!empty($this->page['section']) ? '#' . $this->page['section'] : '') .
            '" class="' . $class . '" title="' . $id . '">' . $title . '</a>';
        if ($this->style == 'list') {
            $content = '<ul><li>' . $content . '</li></ul>';
        }
        return $this->printCell('page', $content);
    }

    /**
     * Date - creation or last modification date if not set otherwise
     *
     * @return bool whether empty
     */
    protected function dateCell()
    {
        global $conf;

        if ($this->column['date'] == 2) {
            $this->page['date'] = $this->getMeta('date', 'modified');
        } elseif (empty($this->page['date']) && !empty($this->page['exists'])) {
            $this->page['date'] = $this->getMeta('date', 'created');
        }

        if (empty($this->page['date']) || empty($this->page['exists'])) {
            return $this->printCell('date', '');
        } else {
            return $this->printCell('date', dformat($this->page['date'], $conf['dformat']));
        }
    }

    /**
     * User - page creator or contributors if not set otherwise
     *
     * @return bool whether empty
     */
    protected function userCell()
    {
        if (!array_key_exists('user', $this->page)) {
            $content = null;
            switch ($this->column['user']) {
                case 1:
                    $content = $this->getMeta('creator');
                    $content = hsc($content);
                    break;
                case 2:
                    $users = $this->getMeta('contributor');
                    if (is_array($users)) {
                        $content = join(', ', $users);
                        $content = hsc($content);
                    }
                    break;
                case 3:
                    $content = $this->getShowUserAsContent($this->getMeta('user'));
                    break;
                case 4:
                    $users = $this->getMeta('contributor');
                    if (is_array($users)) {
                        $content = '';
                        $item = 0;
                        foreach ($users as $userid => $fullname) {
                            $item++;
                            $content .= $this->getShowUserAsContent($userid);
                            if ($item < count($users)) {
                                $content .= ', ';
                            }
                        }
                    }
                    break;
            }
            $this->page['user'] = $content;
        }
        return $this->printCell('user', $this->page['user']);
    }

    /**
     * Internal function to get user column as set in 'showuseras' config option.
     *
     * @param string $login_name
     * @return string whether empty
     */
    private function getShowUserAsContent($login_name)
    {
        if (function_exists('userlink')) {
            $content = userlink($login_name);
        } else {
            $content = editorinfo($login_name);
        }
        return $content;
    }

    /**
     * Description - (truncated) auto abstract if not set otherwise
     *
     * @return bool whether empty
     */
    protected function descriptionCell()
    {
        if (array_key_exists('desc', $this->page)) {
            $desc = $this->page['desc'];
        } elseif (strlen($this->page['description']) > 0) {
            // This condition will become true, when a page-description is given
            // inside the pagelist plugin syntax-block
            $desc = $this->page['description'];
        } else {
            //supports meta stored by the Description plugin
            $desc = $this->getMeta('plugin_description','keywords');

            //use otherwise the default dokuwiki abstract
            if(!$desc) {
                $desc = $this->getMeta('description', 'abstract');
            }
            if(blank($desc)) {
                $desc = '';
            }
        }

        $max = $this->column['desc'];
        if ($max > 1 && PhpString::strlen($desc) > $max) {
            $desc = PhpString::substr($desc, 0, $max) . '…';
        }
        return $this->printCell('desc', hsc($desc));
    }

    /**
     * Diff icon / link to diff page
     *
     * @param string $id page id displayed in this table row
     * @return bool whether empty
     */
    protected function diffCell($id)
    {
        // check for page existence
        if (!isset($this->page['exists'])) {
            if (!isset($this->page['file'])) {
                $this->page['file'] = wikiFN($id);
            }
            $this->page['exists'] = @file_exists($this->page['file']);
        }

        // produce output
        $url_params = [];
        $url_params ['do'] = 'diff';
        $url = wl($id, $url_params) . (!empty($this->page['section']) ? '#' . $this->page['section'] : '');
        $content = '<a href="' . $url . '" class="diff_link">
                    <img src="' . DOKU_BASE . 'lib/images/diff.png" width="15" height="11"
                     title="' . hsc($this->getLang('diff_title')) . '" alt="' . hsc($this->getLang('diff_alt')) . '"/>
                    </a>';
        return $this->printCell('diff', $content);
    }

    /**
     * Plugins - respective plugins must be installed!
     *
     * @param string $plugin pluginname
     * @param string $col column name. Before not provided to td of plugin. Since 2022. Allows different columns per plugin.
     * @param string $id page id displayed in this table row
     * @return bool whether empty
     */
    protected function pluginCell($plugin, $col, $id)
    {
        if (!isset($this->page[$col])) {
            $this->page[$col] = $this->$plugin->td($id, $col);
        }
        return $this->printCell($col, $this->page[$col]);
    }

    /**
     * Produce XHTML cell output
     *
     * @param string $class class per td
     * @param string $content html
     * @return bool whether empty
     */
    protected function printCell($class, $content)
    {
        if (!$content) {
            $content = '&nbsp;';
            $empty = true;
        } else {
            $empty = false;
        }
        $this->doc .= '<td class="' . $class . '">' . $content . '</td>';
        return $empty;
    }


    /**
     * Get default value for an unset element
     *
     * @param string $key one key of metadata array
     * @param string $subkey second key as subkey of metadata array
     * @return false|mixed content of the metadata (sub)array
     */
    protected function getMeta($key, $subkey=null)
    {
        if (empty($this->page['exists']) || empty($this->page['id'])) {
            return false;
        }
        if (!isset($this->meta)) {
            $this->meta = p_get_metadata($this->page['id'], '', METADATA_RENDER_USING_CACHE);
        }

        if($subkey === null) {
            return $this->meta[$key] ?? null;
        } else {
            return $this->meta[$key][$subkey] ?? null;
        }
    }

}
