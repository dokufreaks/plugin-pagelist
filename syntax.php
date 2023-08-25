<?php

/**
 * Pagelist Plugin: lists pages
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */

class syntax_plugin_pagelist extends DokuWiki_Syntax_Plugin
{

    public function getType()
    {
        return 'substition';
    }

    public function getPType()
    {
        return 'block';
    }

    public function getSort()
    {
        return 168;
    }

    /**
     * Connect pattern to lexer
     *
     * @param string $mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<pagelist.+?</pagelist>', $mode, 'plugin_pagelist');
    }

    /**
     * Handle the match
     *
     * @param string $match The text matched by the patterns
     * @param int $state The lexer state for the match
     * @param int $pos The character position of the matched text
     * @param Doku_Handler $handler The Doku_Handler object
     * @return  array Return an array with all data you want to use in render
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $ID;

        $match = substr($match, 9, -11);  // strip markup
        list($flags, $match) = array_pad(explode('>', $match, 2), 2, null);
        $flags = explode('&', substr($flags, 1));
        $items = explode('*', $match);

        $pages = [];
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            if (!preg_match('/\[\[(.+?)]]/', $items[$i], $match)) continue;
            list($id, $title, $description) = array_pad(explode('|', $match[1], 3), 3, null);
            list($id, $section) = array_pad(explode('#', $id, 2), 2, null);
            if (!$id) $id = $ID;

            // Igor and later
            if (class_exists('dokuwiki\File\PageResolver')) {
                $resolver = new dokuwiki\File\PageResolver($ID);
                $id = $resolver->resolveId($id);
                $exists = page_exists($id);
            } else {
                // Compatibility with older releases
                resolve_pageid(getNS($ID), $id, $exists);
            }

            // page has an image title
            if (($title) && (preg_match('/\{\{(.+?)}}/', $title, $match))) {
                list($image, $title) = array_pad(explode('|', $match[1], 2), 2, null);
                list(, $mime) = mimetype($image);
                if (!substr($mime, 0, 5) == 'image') $image = '';
                $pages[] = [
                    'id' => $id,
                    'section' => cleanID($section),
                    'title' => trim($title),
                    'titleimage' => trim($image),
                    'description' => trim($description), // Holds the added parameter for own descriptions
                ];


            } else {
                // text title (if any)
                $pages[] = [
                    'id' => $id,
                    'section' => cleanID($section),
                    'title' => trim($title),
                    'description' => trim($description), // Holds the added parameter for own descriptions
                ];
            }
        }
        return [$flags, $pages];
    }

    /**
     * Create output
     *
     * @param string $format output format being rendered
     * @param Doku_Renderer $renderer the current renderer object
     * @param array $data data created by handler()
     * @return boolean rendered correctly?
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        list($flags, $pages) = $data;

        foreach ($pages as $i => $page) {
            $pages[$i]['exists'] = page_exists($page['id']);
        }

        // for XHTML output
        if ($format == 'xhtml') {
            /** @var helper_plugin_pagelist $pagelist */
            if (!$pagelist = plugin_load('helper', 'pagelist')) return false;
            $pagelist->setFlags($flags);
            $pagelist->startList();

            foreach ($pages as $page) {
                $pagelist->addPage($page);
            }
            $renderer->doc .= $pagelist->finishList();
            return true;

            // for metadata renderer
        } elseif ($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */
            foreach ($pages as $page) {
                $renderer->meta['relation']['references'][$page['id']] = $page['exists'];
            }
            return true;
        }
        return false;
    }
}
