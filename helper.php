<?php
/**
 * Pagelist helper component.
 * 
 * There is an array called 'builtinplugins' for listing built-in plugins.
 * Do not change this.
 * 
 * For backwards compatiblity built-in plugins use '$this->header' and '$this->column'
 * instead of '$this->plugincolumns' and '$this->pluginheaders'.
 * 
 * New plugins should use 'addColumn()' to prevent unnecessary changes
 * to the pagelist helper plugin.
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 * @author     Gina Häußge <osd@foosel.net>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

class helper_plugin_pagelist extends DokuWiki_Plugin {

    /* public */

    var $page       = NULL;    // associative array for page to list
    // must contain a value to key 'id'
    // can contain: 'title', 'date', 'user', 'desc', 'comments',
    // 'tags', 'status' and 'priority'

    var $style      = '';      // table style: 'default', 'table', 'list'
    var $showheader = false;   // show a heading line
    var $column     = array(); // which columns to show
    var $header     = array(); // language strings for table headers
    var $sort       = false;   // alphabetical sort of pages by pagename
    var $rsort      = false;   // reverse alphabetical sort of pages by pagename

    var $plugins    = array(); // array of plugins to extend the pagelist
    var $discussion = NULL;    // discussion class object
    var $tag        = NULL;    // tag class object

    var $doc        = '';      // the final output XHTML string

    /* private */

    var $_meta         = NULL;    // metadata array for page
    protected $builtinplugins = NULL; // list of builtin-plugins
    protected $pluginheaders = array(); // language strings for table headers per plugin
    protected $plugincolumns = array(); // which columns to show per plugin

    /**
     * Constructor gets default preferences
     *
     * These can be overriden by plugins using this class
     */
    function __construct() {
        $this->style       = $this->getConf('style');
        $this->showheader  = $this->getConf('showheader');
        $this->showfirsthl = $this->getConf('showfirsthl');
        $this->sort        = $this->getConf('sort');
        $this->rsort       = $this->getConf('rsort');

        $this->column = array(
                'page'     => true,
                'date'     => $this->getConf('showdate'),
                'user'     => $this->getConf('showuser'),
                'desc'     => $this->getConf('showdesc'),
                'comments' => $this->getConf('showcomments'),
                'linkbacks'=> $this->getConf('showlinkbacks'),
                'tags'     => $this->getConf('showtags'),
                'image'    => $this->getConf('showimage'),
                'diff'     => $this->getConf('showdiff'),
                );

        $this->plugins = array(
                'discussion' => array('comments'),
                'linkback'   => array('linkbacks'),
                'tag'        => array('tags'),
                'pageimage'  => array('image'),
                );

        // Extra array to list built-in plugins. Do not change.
        $this->builtinplugins = array(
                'discussion', 'linkback', 'tag', 'pageimage',
                );
    }

    function getMethods() {
        $result = array();
        $result[] = array(
                'name'   => 'addColumn',
                'desc'   => 'adds an extra column for plugin data',
                'params' => array(
                    'plugin name' => 'string',
                    'column key' => 'string'),
                );
        $result[] = array(
                'name'   => 'setFlags',
                'desc'   => 'overrides standard values for showfooter and firstseconly settings',
                'params' => array('flags' => 'array'),
                'return' => array('success' => 'boolean'),
                );
        $result[] = array(
                'name'   => 'startList',
                'desc'   => 'prepares the table header for the page list',
                );
        $result[] = array(
                'name'   => 'addPage',
                'desc'   => 'adds a page to the list',
                'params' => array("page attributes, 'id' required, others optional" => 'array'),
                );
        $result[] = array(
                'name'   => 'finishList',
                'desc'   => 'returns the XHTML output',
                'return' => array('xhtml' => 'string'),
                );
        return $result;
    }

    /**
     * Reset to standard values.
     */
    function reset() {
        $this->header = array();
        $this->pluginheaders = array();
        $this->plugincolumns = array();
        $this->__construct();
    }

    /**
     * Adds an extra column named $col for plugin $plugin. This requires that
     * the plugin $plugin implements a helper plugin with the functions 'th' and 'td'!
     * 
     * The function 'th' will be called if "this->startList()" is called.
     * The call looks like this: $this->$plugin->th($col)
     * The function 'th' of the helper plugin of $plugin needs to return a column
     * name for column $col.
     * 
     * The function 'td' will be called if "this->addPage()" is called.
     * The call looks like this: $this->$plug->td($id, $col)
     * The function 'td' of the  helper plugin of $plugin needs to return a cell
     * value for page-id $id and column $col.
     * 
     * The functions 'th' and 'td' will be called multiple times if $plugin
     * has called 'addColumn()' with different column names.
     * 
     * The parameter $col MUST be optional to prevent crashes if an older version
     * of the pagelist plugin is installed which will call 'th' and 'td'
     * without any parameters. So valid function headers look like this:
     *     function th($column=NULL) { ... }
     *     function td($id, $column=NULL) { ... }
     * Or older helper plugins can stay like this:
     *     function th() { ... }
     *     function td($id) { ... }
     */
    function addColumn($plugin, $col) {
        if (array_key_exists($plugin, $this->plugins)) {
            $this->plugins[$plugin][] = $col;
        } else {
            $this->plugins[$plugin] = array($col);
        }
        $this->plugincolumns[$plugin][$col] = true;
    }

    /**
     * Set the header title for a column.
     * Can override existing values.
     */
    function setHeaderTitle($column, $title, $plugin=NULL) {
        if ($plugin == NULL
            || $plugin == 'pagelist'
            || in_array($plugin, $this->builtinplugins)) {
            $this->header[$column] = $title;
        } else {
            $this->pluginheaders[$plugin][$column] = $title;
        }
    }

    /**
     * Enable or disable a column.
     * Can override existing values.
     */
    function setColumn($column, $value, $plugin=NULL) {
        if ($plugin == NULL
            || $plugin == 'pagelist'
            || in_array($plugin, $this->builtinplugins)) {
            $this->column[$column] = $value;
        } else {
            $this->plugincolumns[$plugin][$column] = $value;
        }
    }

    /**
     * Overrides standard values for style, showheader and show(column) settings
     */
    function setFlags($flags) {
        if (!is_array($flags)) return false;

        $columns = array('date', 'user', 'desc', 'comments', 'linkbacks', 'tags', 'image', 'diff');
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

            if (substr($flag, 0, 2) == 'no') {
                $value = false;
                $flag  = substr($flag, 2);
            } else {
                $value = true;
            }
            
            if (in_array($flag, $columns)) $this->column[$flag] = $value;
        }
        return true;
    }

    /**
     * Sets the list header
     */
    function startList($callerClass=NULL) {

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
        
        if($class) {
            if ($callerClass) {
                $class .= ' '.$callerClass;
            }
            $this->doc = '<div class="table">'.DOKU_LF.'<table class="'.$class.'">'.DOKU_LF;
        } else {
            // Simplelist is enabled; Skip header and firsthl
            $this->showheader = false;
            $this->showfirsthl = false;
            //$this->doc .= DOKU_LF.DOKU_TAB.'</tr>'.DOKU_LF;
            $this->doc = '<ul>';
        }
        
        $this->page = NULL;

        // check if some plugins are available - if yes, load them!
        foreach ($this->plugins as $plug => $columns) {
            $builtin = in_array($plug, $this->builtinplugins);
            foreach ($columns as $col) {
                if ($builtin) {
                    if (!$this->column[$col]) continue;
                } else if (!$this->plugincolumns[$plug][$col]) {
                    continue;
                }
                if (plugin_isdisabled($plug) || (!$this->$plug = plugin_load('helper', $plug))) {
                    if ($builtin) {
                        $this->column[$col] = false;
                    } else {
                        $this->plugincolumns[$plug][$col] = false;
                    }
                }
            }
        }

        // header row
        if ($this->showheader) {
            $this->doc .= DOKU_TAB.'<tr>'.DOKU_LF.DOKU_TAB.DOKU_TAB;
            $columns = array('page', 'date', 'user', 'desc', 'diff');
            if ($this->column['image']) {	
                if (!$this->header['image']) $this->header['image'] = hsc($this->pageimage->th());
                    $this->doc .= '<th class="images">'.$this->header['image'].'</th>';
            }
            foreach ($columns as $col) {
                if ($this->column[$col]) {
                    if (!$this->header[$col]) $this->header[$col] = hsc($this->getLang($col));
                    $this->doc .= '<th class="'.$col.'">'.$this->header[$col].'</th>';
                }
            }
            foreach ($this->plugins as $plug => $columns) {
                $builtin = in_array($plug, $this->builtinplugins);
                foreach ($columns as $col) {
                    if ($builtin) {
                        if ($this->column[$col] && $col != 'image') {
                            if (!$this->header[$col]) $this->header[$col] = hsc($this->$plug->th($col));
                            $this->doc .= '<th class="'.$col.'">'.$this->header[$col].'</th>';
                        }
                    } else {
                        if ($this->plugincolumns[$plug][$col] && $col != 'image') {
                            if (!$this->pluginheaders[$plug][$col]) $this->pluginheaders[$plug][$col] = hsc($this->$plug->th($col));
                            $this->doc .= '<th class="'.$col.'">'.$this->pluginheaders[$plug][$col].'</th>';
                        }
                    }
                }
            }
            $this->doc .= DOKU_LF.DOKU_TAB.'</tr>'.DOKU_LF;
        }
        return true;
    }

    /**
     * Sets a list row
     */
    function addPage($page) {

        $id = $page['id'];
        if (!$id) return false;
        $this->page = $page;
        $this->_meta = NULL;
        
        if($this->style != 'simplelist') {
            // priority and draft
            if (!isset($this->page['draft'])) {
                $this->page['draft'] = ($this->_getMeta('type') == 'draft');
            }
            $class = '';
            if (isset($this->page['priority'])) $class .= 'priority'.$this->page['priority']. ' ';
            if ($this->page['draft']) $class .= 'draft ';
            if ($this->page['class']) $class .= $this->page['class'];
            if(!empty($class)) $class = ' class="' . $class . '"';
    
            $this->doc .= DOKU_TAB.'<tr'.$class.'>'.DOKU_LF;
            if ($this->column['image']) $this->_pluginCell('pageimage','image',$id);
            $this->_pageCell($id);    
            if ($this->column['date']) $this->_dateCell();
            if ($this->column['user']) $this->_userCell();
            if ($this->column['desc']) $this->_descCell();
            if ($this->column['diff']) $this->_diffCell($id);
            foreach ($this->plugins as $plug => $columns) {
                $builtin = in_array($plug, $this->builtinplugins);
                foreach ($columns as $col) {
                    if ($builtin) {
                        if ($this->column[$col] && $col != 'image') $this->_pluginCell($plug, $col, $id);
                    }
                    else if ($this->plugincolumns[$plug][$col] && $col != 'image') {
                        $this->_pluginCell($plug, $col, $id);
                    }
                }
            }
            
            $this->doc .= DOKU_TAB.'</tr>'.DOKU_LF;
        } else {
            $class = '';
            // simplelist is enabled; just output pagename
            $this->doc .= DOKU_TAB . '<li>' . DOKU_LF;
            if(page_exists($id)) $class = 'wikilink1';
            else $class = 'wikilink2';
            
            if (!$this->page['title']) $this->page['title'] = str_replace('_', ' ', noNS($id));
            $title = hsc($this->page['title']);
            
            $content = '<a href="'.wl($id).'" class="'.$class.'" title="'.$id.'">'.$title.'</a>';
            $this->doc .= $content;
            $this->doc .= DOKU_TAB . '</li>' . DOKU_LF;
        }

        return true;
    }

    /**
     * Sets the list footer
     */
    function finishList() {
        if($this->style != 'simplelist') {
            if (!isset($this->page)) $this->doc = '';
            else $this->doc .= '</table>'.DOKU_LF.'</div>'.DOKU_LF;
        } else {
            $this->doc .= '</ul>' . DOKU_LF;
        }

        // reset defaults
        $this->__construct();

        return $this->doc;
    }

    /* ---------- Private Methods ---------- */

    /**
     * Page title / link to page
     */
    function _pageCell($id) {

        // check for page existence
        if (!isset($this->page['exists'])) {
            if (!isset($this->page['file'])) $this->page['file'] = wikiFN($id);
            $this->page['exists'] = @file_exists($this->page['file']);
        }
        if ($this->page['exists']) $class = 'wikilink1';
        else $class = 'wikilink2';

        // handle image and text titles
        if ($this->page['titleimage']) {
            $title = '<img src="'.ml($this->page['titleimage']).'" class="media"';
            if ($this->page['title']) $title .= ' title="'.hsc($this->page['title']).'"'.
                ' alt="'.hsc($this->page['title']).'"';
            $title .= ' />';
        } else {
            if($this->showfirsthl) {
                $this->page['title'] = $this->_getMeta('title');
            }

            if (!$this->page['title']) $this->page['title'] = str_replace('_', ' ', noNS($id));
            $title = hsc($this->page['title']);
        }

        // produce output
        $content = '<a href="'.wl($id).($this->page['section'] ? '#'.$this->page['section'] : '').
            '" class="'.$class.'" title="'.$id.'">'.$title.'</a>';
        if ($this->style == 'list') $content = '<ul><li>'.$content.'</li></ul>';
        return $this->_printCell('page', $content);
    }

    /**
     * Date - creation or last modification date if not set otherwise
     */
    function _dateCell() {    
        global $conf;

        if($this->column['date'] == 2) {
            $this->page['date'] = $this->_getMeta(array('date', 'modified'));
        } elseif(!$this->page['date'] && $this->page['exists']) {
            $this->page['date'] = $this->_getMeta(array('date', 'created'));
        }

        if ((!$this->page['date']) || (!$this->page['exists'])) {
            return $this->_printCell('date', '');
        } else {
            return $this->_printCell('date', dformat($this->page['date'], $conf['dformat']));
        }
    }

    /**
     * User - page creator or contributors if not set otherwise
     */
    function _userCell() {
        if (!array_key_exists('user', $this->page)) {
            if ($this->column['user'] == 2) {
                $users = $this->_getMeta('contributor');
                if (is_array($users)) $this->page['user'] = join(', ', $users);
            } else {
                $this->page['user'] = $this->_getMeta('creator');
            }
        }
        return $this->_printCell('user', hsc($this->page['user']));
    }

    /**
     * Description - (truncated) auto abstract if not set otherwise
     */
    function _descCell() {
        if (array_key_exists('desc', $this->page)) {
            $desc = $this->page['desc'];
        } elseif (strlen($this->page['description']) > 0) {
            // This condition will become true, when a page-description is given
            // inside the syntax-block
            $desc = $this->page['description'];
        } else {
            $desc = $this->_getMeta(array('description', 'abstract'));
        }
        
        $max = $this->column['desc'];
        if (($max > 1) && (utf8_strlen($desc) > $max)) $desc = utf8_substr($desc, 0, $max).'…';
        return $this->_printCell('desc', hsc($desc));
    }

    /**
     * Diff icon / link to diff page
     */
    function _diffCell($id) {
        // check for page existence
        if (!isset($this->page['exists'])) {
            if (!isset($this->page['file'])) $this->page['file'] = wikiFN($id);
            $this->page['exists'] = @file_exists($this->page['file']);
        }

        // produce output
        $url_params = array();
        $url_params ['do'] = 'diff';
        $content = '<a href="'.wl($id, $url_params).($this->page['section'] ? '#'.$this->page['section'] : '').'" class="diff_link">
<img src="/lib/images/diff.png" width="15" height="11" title="'.hsc($this->getLang('diff_title')).'" alt="'.hsc($this->getLang('diff_alt')).'"/>
</a>';
        return $this->_printCell('page', $content);
    }

    /**
     * Plugins - respective plugins must be installed!
     * @see addColumn for more information
     */
    function _pluginCell($plug, $col, $id) {
        // At this point we do not need to differ between built-in and other plugins
        if (!isset($this->page['plugins'][$plug][$col])) $this->page['plugins'][$plug][$col] = $this->$plug->td($id, $col);
        return $this->_printCell($col, $this->page['plugins'][$plug][$col]);
    }

    /**
     * Produce XHTML cell output
     */
    function _printCell($class, $content) {
        if (!$content) {
            $content = '&nbsp;';
            $empty   = true;
        } else {
            $empty   = false;
        }
        $this->doc .= DOKU_TAB.DOKU_TAB.'<td class="'.$class.'">'.$content.'</td>'.DOKU_LF;
        return (!$empty);
    }


    /**
     * Get default value for an unset element
     */
    function _getMeta($key) {
        if (!$this->page['exists']) return false;
        if (!isset($this->_meta)) $this->_meta = p_get_metadata($this->page['id'], '', METADATA_RENDER_USING_CACHE);
        if (is_array($key)) return $this->_meta[$key[0]][$key[1]];
        else return $this->_meta[$key];
    }

}
// vim:ts=4:sw=4:et: 
