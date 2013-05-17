<?php

/**
 * The MIT License (MIT)
 * 
 * Copyright (c) 2008-2013 pelago
 * Copyright (c) 2013 silverorange
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
class Emogrifier
{
    const
        CACHE_CSS = 0,
        CACHE_SELECTOR = 1,
        CACHE_XPATH = 2,

        // for calculating nth-of-type and nth-child selectors
        INDEX = 0,
        MULTIPLIER = 1;

    protected
        $html = '',
        $css = '',
        $unprocessableHTMLTags = array('wbr'),
        $caches = array();

    // this attribute applies to the case where you want to preserve your original text encoding.
    // by default, emogrifier translates your text into HTML entities for two reasons:
    // 1. because of client incompatibilities, it is better practice to send out HTML entities rather than unicode over email
    // 2. it translates any illegal XML characters that DOMDocument cannot work with
    // if you would like to preserve your original encoding, set this attribute to true.
    public $preserveEncoding = false;

    public function __construct($html = '', $css = '')
    {
        $this->html = $html;
        $this->css  = $css;
        $this->clearCache();
    }

    public function setHTML($html = '') { $this->html = $html; }
    public function setCSS($css = '')
    {
        $this->css = $css;
        $this->clearCache(static::CACHE_CSS);
    }

    public function clearCache($key = null)
    {
        if (!is_null($key)) {
            if (isset($this->caches[$key])) {
                $this->caches[$key] = array();
            }
        } else {
            $this->caches = array(
                static::CACHE_CSS       => array(),
                static::CACHE_SELECTOR  => array(),
                static::CACHE_XPATH     => array(),
            );
        }
    }

    // there are some HTML tags that DOMDocument cannot process, and will throw an error if it encounters them.
    // in particular, DOMDocument will complain if you try to use HTML5 tags in an XHTML document.
    // these functions allow you to add/remove them if necessary.
    // it only strips them from the code (does not remove actual nodes).
    public function addUnprocessableHTMLTag($tag)
    {
        $this->unprocessableHTMLTags[] = $tag;
    }

    public function removeUnprocessableHTMLTag($tag)
    {
        if (($key = array_search($tag,$this->unprocessableHTMLTags)) !== false) {
            unset($this->unprocessableHTMLTags[$key]);
        }
    }

    // applies the CSS you submit to the html you submit. places the css inline
    public function emogrify()
    {
        $body = $this->html;

        // remove any unprocessable HTML tags (tags that DOMDocument cannot parse; this includes wbr and many new HTML5 tags)
        if (count($this->unprocessableHTMLTags)) {
            $unprocessableHTMLTags = implode('|',$this->unprocessableHTMLTags);
            $body = preg_replace("/<\/?($unprocessableHTMLTags)[^>]*>/i",'',$body);
        }

        $encoding = mb_detect_encoding($body);
        $body = mb_convert_encoding($body, 'HTML-ENTITIES', $encoding);

        $xmldoc = new DOMDocument();
        $xmldoc->encoding = $encoding;
        $xmldoc->strictErrorChecking = false;
        $xmldoc->formatOutput = true;
        $xmldoc->loadHTML($body);
        $xmldoc->normalizeDocument();

        $xpath = new DOMXPath($xmldoc);

        // before be begin processing the CSS file, parse the document and normalize all existing CSS attributes (changes 'DISPLAY: none' to 'display: none');
        // we wouldn't have to do this if DOMXPath supported XPath 2.0.
        // also store a reference of nodes with existing inline styles so we don't overwrite them
        $vistedNodes = $vistedNodeRef = array();
        $nodes = @$xpath->query('//*[@style]');
        foreach ($nodes as $node) {
            $normalizedOrigStyle = preg_replace('/[A-z\-]+(?=\:)/Se',"strtolower('\\0')", $node->getAttribute('style'));

            // in order to not overwrite existing style attributes in the HTML, we have to save the original HTML styles
            $nodeKey = md5($node->getNodePath());
            if (!isset($vistedNodeRef[$nodeKey])) {
                $vistedNodeRef[$nodeKey] = $this->cssStyleDefinitionToArray($normalizedOrigStyle);
                $vistedNodes[$nodeKey]   = $node;
            }

            $node->setAttribute('style', $normalizedOrigStyle);
        }

        // grab any existing style blocks from the html and append them to the existing CSS
        // (these blocks should be appended so as to have precedence over conflicting styles in the existing CSS)
        $css = $this->css;
        $nodes = @$xpath->query('//style');
        foreach ($nodes as $node) {
            // append the css
            $css .= "\n\n{$node->nodeValue}";
            // remove the <style> node
            $node->parentNode->removeChild($node);
        }

        // filter the CSS
        $search = array(
            '/\/\*.*\*\//sU', // get rid of css comment code
            '/^\s*@import\s[^;]+;/misU', // strip out any import directives
            '/^\s*@media\s[^{]+{\s*}/misU', // strip any empty media enclosures
            '/^\s*@media\s+((aural|braille|embossed|handheld|print|projection|speech|tty|tv)\s*,*\s*)+{.*}\s*}/misU', // strip out all media types that are not 'screen' or 'all' (these don't apply to email)
        );

        $replace = array(
            '',
            '',
            '',
            '',
            '\\1',
        );

        $css = preg_replace($search, $replace, $css);

        // media queries to preserve
        $regexp = '/^\s*@media\s[^{]+{.*}\s*}/misU';
        preg_match_all($regexp, $css, $preserved_styles);
        $css = preg_replace($regexp, '', $css);

        $csskey = md5($css);
        if (!isset($this->caches[static::CACHE_CSS][$csskey])) {

            // process the CSS file for selectors and definitions
            preg_match_all('/(^|[^{}])\s*([^{]+){([^}]*)}/mis', $css, $matches, PREG_SET_ORDER);

            $all_selectors = array();
            foreach ($matches as $key => $selectorString) {
                // if there is a blank definition, skip
                if (!strlen(trim($selectorString[3]))) {
                    continue;
                }

                // else split by commas and duplicate attributes so we can sort by selector precedence
                $selectors = explode(',',$selectorString[2]);
                foreach ($selectors as $selector) {

                    // don't process pseudo-elements and behavioral (dynamic) pseudo-classes; ONLY allow structural pseudo-classes
                    if (strpos($selector, ':') !== false && !preg_match('/:\S+\-(child|type)\(/i', $selector)) {
                        continue;
                    }

                    $all_selectors[] = array(
                        'selector' => trim($selector),
                        'attributes' => trim($selectorString[3]),
                        'line' => $key, // keep track of where it appears in the file, since order is important
                    );
                }
            }

            // now sort the selectors by precedence
            usort($all_selectors, array($this,'sortBySelectorPrecedence'));

            $this->caches[static::CACHE_CSS][$csskey] = $all_selectors;
        }

        foreach ($this->caches[static::CACHE_CSS][$csskey] as $value) {

            // query the body for the xpath selector
            $nodes = $xpath->query($this->translateCSStoXpath(trim($value['selector'])));

            foreach ($nodes as $node) {
                // if it has a style attribute, get it, process it, and append (overwrite) new stuff
                if ($node->hasAttribute('style')) {
                    // break it up into an associative array
                    $oldStyleArr = $this->cssStyleDefinitionToArray($node->getAttribute('style'));
                    $newStyleArr = $this->cssStyleDefinitionToArray($value['attributes']);

                    // new styles overwrite the old styles (not technically accurate, but close enough)
                    $combinedArr = array_merge($oldStyleArr,$newStyleArr);
                    $style = '';
                    foreach ($combinedArr as $k => $v) {
                        $style .= (strtolower($k) . ':' . $v . ';');
                    }
                } else {
                    // otherwise create a new style
                    $style = trim($value['attributes']);
                }
                $node->setAttribute('style', $style);
            }
        }

        // now iterate through the nodes that contained inline styles in the original HTML
        foreach ($vistedNodeRef as $nodeKey => $origStyleArr) {
            $node = $vistedNodes[$nodeKey];
            $currStyleArr = $this->cssStyleDefinitionToArray($node->getAttribute('style'));

            $combinedArr = array_merge($currStyleArr, $origStyleArr);
            $style = '';
            foreach ($combinedArr as $k => $v) {
                $style .= (strtolower($k) . ':' . $v . ';');
            }

            $node->setAttribute('style', $style);
        }

        // This removes styles from your email that contain display:none.
        // We need to look for display:none, but we need to do a case-insensitive search. Since DOMDocument only supports XPath 1.0,
        // lower-case() isn't available to us. We've thus far only set attributes to lowercase, not attribute values. Consequently, we need
        // to translate() the letters that would be in 'NONE' ("NOE") to lowercase.
        $nodes = $xpath->query('//*[contains(translate(translate(@style," ",""),"NOE","noe"),"display:none")]');

        // The checks on parentNode and is_callable below ensure that if we've deleted the parent node,
        // we don't try to call removeChild on a nonexistent child node
        if ($nodes->length > 0) {
            foreach ($nodes as $node) {
                if ($node->parentNode && is_callable(array($node->parentNode,'removeChild'))) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // add back in preserved media query styles
        $style = $xmldoc->createElement('style');
        $style->setAttribute('type', 'text/css');
        $style->nodeValue = implode("\n", $preserved_styles[0]);
        $body = $xpath->query('//body');
        if ($body->length > 0) {
            $body->item(0)->appendChild($style);
        }

        if ($this->preserveEncoding) {
            return mb_convert_encoding($xmldoc->saveHTML(), $encoding, 'HTML-ENTITIES');
        } else {
            return $xmldoc->saveHTML();
        }
    }

    protected function sortBySelectorPrecedence($a, $b)
    {
        $precedenceA = $this->getCSSSelectorPrecedence($a['selector']);
        $precedenceB = $this->getCSSSelectorPrecedence($b['selector']);

        // we want these sorted ascendingly so selectors with lesser precedence get processed first and
        // selectors with greater precedence get sorted last
        return ($precedenceA == $precedenceB) ? ($a['line'] < $b['line'] ? -1 : 1) : ($precedenceA < $precedenceB ? -1 : 1);
    }

    protected function getCSSSelectorPrecedence($selector)
    {
        $selectorkey = md5($selector);
        if (!isset($this->caches[static::CACHE_SELECTOR][$selectorkey])) {
            $precedence = 0;
            $value = 100;
            $search = array('\#','\.',''); // ids: worth 100, classes: worth 10, elements: worth 1

            foreach ($search as $s) {
                if (trim($selector == '')) {
                    break;
                }
                $num = 0;
                $selector = preg_replace('/'.$s.'\w+/','',$selector,-1,$num);
                $precedence += ($value * $num);
                $value /= 10;
            }
            $this->caches[static::CACHE_SELECTOR][$selectorkey] = $precedence;
        }

        return $this->caches[static::CACHE_SELECTOR][$selectorkey];
    }

    // right now we support all CSS 1 selectors and most CSS2/3 selectors.
    // http://plasmasturm.org/log/444/
    protected function translateCSStoXpath($css_selector)
    {
        $css_selector = trim($css_selector);
        $xpathkey = md5($css_selector);
        if (!isset($this->caches[static::CACHE_XPATH][$xpathkey])) {
            // returns an Xpath selector
            $search = array(
               '/\s+>\s+/', // Matches any element that is a child of parent.
               '/\s+\+\s+/', // Matches any element that is an adjacent sibling.
               '/\s+/', // Matches any element that is a descendant of an parent element element.
               '/([^\/]+):first-child/i', // first-child pseudo-selector
               '/([^\/]+):last-child/i', // last-child pseudo-selector
               '/(\w)\[(\w+)\]/', // Matches element with attribute
               '/(\w)\[(\w+)\=[\'"]?(\w+)[\'"]?\]/', // Matches element with EXACT attribute
               '/(\w+)?\#([\w\-]+)/e', // Matches id attributes
               '/(\w+|[\*\]])?((\.[\w\-]+)+)/e', // Matches class attributes

            );
            $replace = array(
                '/',
                '/following-sibling::*[1]/self::',
                '//',
                '*[1]/self::\\1',
                '*[last()]/self::\\1',
                '\\1[@\\2]',
                '\\1[@\\2="\\3"]',
                "(strlen('\\1') ? '\\1' : '*').'[@id=\"\\2\"]'",
                "(strlen('\\1') ? '\\1' : '*').'[contains(concat(\" \",@class,\" \"),concat(\" \",\"'.implode('\",\" \"))][contains(concat(\" \",@class,\" \"),concat(\" \",\"',explode('.',substr('\\2',1))).'\",\" \"))]'",
            );

            $css_selector = '//'.preg_replace($search, $replace, $css_selector);

            // advanced selectors are going to require a bit more advanced emogrification
            // if we required PHP 5.3 we could do this with closures
            $css_selector = preg_replace_callback('/([^\/]+):nth-child\(\s*(odd|even|[+\-]?\d|[+\-]?\d?n(\s*[+\-]\s*\d)?)\s*\)/i', array($this, 'translateNthChild'), $css_selector);
            $css_selector = preg_replace_callback('/([^\/]+):nth-of-type\(\s*(odd|even|[+\-]?\d|[+\-]?\d?n(\s*[+\-]\s*\d)?)\s*\)/i', array($this, 'translateNthOfType'), $css_selector);

            $this->caches[static::CACHE_SELECTOR][$xpathkey] = $css_selector;
        }

        return $this->caches[static::CACHE_SELECTOR][$xpathkey];
    }

    protected function translateNthChild($match)
    {
        $result = $this->parseNth($match);

        if (isset($result[self::MULTIPLIER])) {
            if ($result[self::MULTIPLIER] < 0) {
                $result[self::MULTIPLIER] = abs($result[self::MULTIPLIER]);

                return sprintf("*[(last() - position()) mod %u = %u]/self::%s", $result[self::MULTIPLIER], $result[self::INDEX], $match[1]);
            } else {
                return sprintf("*[position() mod %u = %u]/self::%s", $result[self::MULTIPLIER], $result[self::INDEX], $match[1]);
            }
        } else {
            return sprintf("*[%u]/self::%s", $result[self::INDEX], $match[1]);
        }
    }

    protected function translateNthOfType($match)
    {
        $result = $this->parseNth($match);

        if (isset($result[self::MULTIPLIER])) {
            if ($result[self::MULTIPLIER] < 0) {
                $result[self::MULTIPLIER] = abs($result[self::MULTIPLIER]);

                return sprintf("%s[(last() - position()) mod %u = %u]", $match[1], $result[self::MULTIPLIER], $result[self::INDEX]);
            } else {
                return sprintf("%s[position() mod %u = %u]", $match[1], $result[self::MULTIPLIER], $result[self::INDEX]);
            }
        } else {
            return sprintf("%s[%u]", $match[1], $result[self::INDEX]);
        }
    }

    protected function parseNth($match)
    {
        if (in_array(strtolower($match[2]), array('even','odd'))) {
            $index = strtolower($match[2]) == 'even' ? 0 : 1;

            return array(self::MULTIPLIER => 2, self::INDEX => $index);
        // if there is a multiplier
        } elseif (stripos($match[2], 'n') === false) {
            $index = intval(str_replace(' ', '', $match[2]));

            return array(self::INDEX => $index);
        } else {

            if (isset($match[3])) {
                $multiple_term = str_replace($match[3], '', $match[2]);
                $index = intval(str_replace(' ', '', $match[3]));
            } else {
                $multiple_term = $match[2];
                $index = 0;
            }

            $multiplier = str_ireplace('n', '', $multiple_term);

            if (!strlen($multiplier)) {
                $multiplier = 1;
            } elseif ($multiplier == 0) {
                return array(self::INDEX => $index);
            } else {
                $multiplier = intval($multiplier);
            }

            while ($index < 0) {
                $index += abs($multiplier);
            }

            return array(self::MULTIPLIER => $multiplier, self::INDEX => $index);
        }
    }

    protected function cssStyleDefinitionToArray($style)
    {
        $definitions = explode(';',$style);
        $retArr = array();
        foreach ($definitions as $def) {
            if (empty($def) || strpos($def, ':') === false) {
                continue;
            }
            list($key,$value) = explode(':',$def,2);
            if (empty($key) || strlen(trim($value)) === 0) {
                continue;
            }
            $retArr[trim($key)] = trim($value);
        }

        return $retArr;
    }
}
