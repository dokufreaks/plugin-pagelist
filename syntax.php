<?php
/**
 * Pagelist Plugin: lists pages
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>  
 */
// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
 
class syntax_plugin_pagelist extends DokuWiki_Syntax_Plugin {
  
  function getInfo(){
    return array(
      'author' => 'Esther Brunner',
      'email'  => 'wikidesign@gmail.com',
      'date'   => '2006-12-14',
      'name'   => 'Pagelist Plugin',
      'desc'   => 'lists pages',
      'url'    => 'http://www.wikidesign.ch/en/plugin/pagelist/start',
    );
  }

  function getType(){ return 'substition';}
  function getPType(){ return 'block';}
  function getSort(){ return 168; }
  
  /**
   * Connect pattern to lexer
   */
  function connectTo($mode){
    $this->Lexer->addSpecialPattern('<pagelist>.+?</pagelist>', $mode, 'plugin_pagelist');
  }

  /**
   * Handle the match
   */
  function handle($match, $state, $pos, &$handler){
    global $ID;
    
    $match = substr($match, 10, -11);  // strip markup
    $items = explode('*', $match);
    
    $pages = array();
    $c = count($items);
    for ($i = 0; $i < $c; $i++){
      if (!preg_match('/\[\[(.+?)\]\]/', $items[$i], $match)) continue;
      list($id, $title) = explode('|', $match[1], 2);
      list($id, $section) = explode('#', $id, 2);
      if (!$id) $id = $ID;
      resolve_pageid(getNS($ID), $id, $exists);
      
      // page has an image title
      if (($title) && (preg_match('/\{\{(.+?)\}\}/', $title, $match))){
        list($image, $title) = explode('|', $match[1], 2);
        list($ext, $mime) = mimetype($image);
        if (!substr($mime, 0, 5) == 'image') $image = '';
        $pages[] = array(
          'id'      => $id,
          'section' => cleanID($section),
          'title'   => trim($title),
          'image'   => trim($image),
          'exists'  => $exists,
        );
        
      // text title (if any)
      } else {
        $pages[] = array(
          'id'      => $id,
          'section' => cleanID($section),
          'title'   => trim($title),
          'exists'  => $exists,
        );
      }
    }
        
    return $pages;
  }

  /**
   * Create output
   */
  function render($mode, &$renderer, $pages){

    // for XHTML output
    if ($mode == 'xhtml'){
      if (!$my =& plugin_load('helper', 'pagelist')) return false;
      $my->startList();
      foreach($pages as $page){
        $my->addPage($page);
      }
      $renderer->doc .= $my->finishList();
      return true;
      
    // for metadata renderer
    } elseif ($mode == 'metadata'){
      foreach ($pages as $page){
        $renderer->meta['relation']['references'][$page['id']] = $page['exists'];
      }
      return true;
    }
    return false;
  }
  
}
 
//Setup VIM: ex: et ts=4 enc=utf-8 :
