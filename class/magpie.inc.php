<?php

/**
 * Project:     MagpieRSS: a simple RSS integration tool
 * File:        rss_parse.inc  - parse an RSS or Atom feed
 *               return as a simple object.
 *
 * Handles RSS 0.9x, RSS 2.0, RSS 1.0, and Atom 0.3
 *
 * The lastest version of MagpieRSS can be obtained from:
 * http://magpierss.sourceforge.net
 *
 * For questions, help, comments, discussion, etc., please join the
 * Magpie mailing list:
 * magpierss-general@lists.sourceforge.net
 *
 * @author           Kellan Elliott-McCrea <kellan@protest.net>
* @version          0.7a
 * @license          GPL
 *
 */

define('MAGPIE_DEBUG', 0);

define('RSS', 'RSS');
define('ATOM', 'Atom');

/**
 * Hybrid parser, and object, takes RSS as a string and returns a simple object.
 *
 * see: rss_fetch.inc for a simpler interface with integrated caching support
 *
 */
class MagpieRSS
{
    public $parser;

    public $current_item = array();  // item currently being parsed
    public $items        = array();  // collection of parsed items
    public $channel      = array();  // hash of channel fields
    public $textinput    = array();
    public $image        = array();
    public $feed_type;
    public $feed_version;
    public $encoding     = '';       // output encoding of parsed rss

    public $_source_encoding = '';     // only set if we have to parse xml prolog

    public $ERROR   = '';
    public $WARNING = '';

    // define some constants

    public $_CONTENT_CONSTRUCTS = array('content', 'summary', 'info', 'title', 'tagline', 'copyright');
    public $_KNOWN_ENCODINGS    = array('UTF-8', 'US-ASCII', 'ISO-8859-1');

    // parser variables, useless if you're not a parser, treat as private
    public $stack             = array(); // parser stack
    public $inchannel         = false;
    public $initem            = false;
    public $incontent         = false; // if in Atom <content mode="xml"> field
    public $intextinput       = false;
    public $inimage           = false;
    public $current_field     = '';
    public $current_namespace = false;

    /**
     *  Set up XML parser, parse source, and return populated RSS object..
     *
     * @param string $source          string containing the RSS to be parsed
     *
     *  NOTE:  Probably a good idea to leave the encoding options alone unless
     *         you know what you're doing as PHP's character set support is
     *         a little weird.
     *
     *  NOTE:  A lot of this is unnecessary but harmless with PHP5
     *
     *
     * @param string $output_encoding output the parsed RSS in this character
     *                                set defaults to ISO-8859-1 as this is PHP's
     *                                default.
     *
     *                                  NOTE: might be changed to UTF-8 in future
     *                                  versions.
     *
     * @param string $input_encoding  the character set of the incoming RSS source.
     *                                Leave blank and Magpie will try to figure it
     *                                out.
     *
     *
     * @param bool   $detect_encoding if false Magpie won't attempt to detect
     *                                source encoding. (caveat emptor)
     *
     */
    public function __construct(
        $source,
        $output_encoding = 'ISO-8859-1',
        $input_encoding = null,
        $detect_encoding = true
    ) {
        # if PHP xml isn't compiled in, die
        #
        if (!function_exists('xml_parser_create')) {
            $this->error("Failed to load PHP's XML Extension. " . 'http://www.php.net/manual/en/ref.xml.php',
                         E_USER_ERROR);
        }

        list($parser, $source) = $this->create_parser($source, $output_encoding, $input_encoding, $detect_encoding);

        if (!is_resource($parser)) {
            $this->error("Failed to create an instance of PHP's XML parser. "
                         . 'http://www.php.net/manual/en/ref.xml.php', E_USER_ERROR);
        }

        $this->parser = $parser;

        # pass in parser, and a reference to this object
        # setup handlers
        #
        xml_set_object($this->parser, $this);
        xml_set_element_handler($this->parser, 'feed_start_element', 'feed_end_element');

        xml_set_character_data_handler($this->parser, 'feed_cdata');

        $status = @xml_parse($this->parser, $source);

        if (!$status) {
            $errorcode = xml_get_error_code($this->parser);
            if ($errorcode != XML_ERROR_NONE) {
                $xml_error  = xml_error_string($errorcode);
                $error_line = xml_get_current_line_number($this->parser);
                $error_col  = xml_get_current_column_number($this->parser);
                $errormsg   = "$xml_error at line $error_line, column $error_col";

                $this->error($errormsg);
            }
        }

        xml_parser_free($this->parser);

        $this->normalize();
    }

    /**
     * @param $p
     * @param $element
     * @param $attrs
     */
    public function feed_start_element($p, $element, &$attrs) {
        $el    = $element = strtolower($element);
        $attrs = array_change_key_case($attrs, CASE_LOWER);

        // check for a namespace, and split if found
        $ns = false;
        if (strpos($element, ':')) {
            list($ns, $el) = explode(':', $element, 2);
        }
        if ($ns && $ns !== 'rdf') {
            $this->current_namespace = $ns;
        }

        # if feed type isn't set, then this is first element of feed
        # identify feed from root element
        #
        if (!isset($this->feed_type)) {
            if ($el === 'rdf') {
                $this->feed_type    = RSS;
                $this->feed_version = '1.0';
            } elseif ($el === 'rss') {
                $this->feed_type    = RSS;
                $this->feed_version = $attrs['version'];
            } elseif ($el === 'feed') {
                $this->feed_type    = ATOM;
                $this->feed_version = $attrs['version'];
                $this->inchannel    = true;
            }

            return;
        }

        if ($el === 'channel') {
            $this->inchannel = true;
        } elseif ($el === 'item' || $el === 'entry') {
            $this->initem = true;
            if (isset($attrs['rdf:about'])) {
                $this->current_item['about'] = $attrs['rdf:about'];
            }
        }

        // if we're in the default namespace of an RSS feed,
        //  record textinput or image fields
        elseif ($this->feed_type == RSS && $this->current_namespace === '' && $el === 'textinput') {
            $this->intextinput = true;
        } elseif ($this->feed_type == RSS && $this->current_namespace === '' && $el === 'image') {
            $this->inimage = true;
        } # handle atom content constructs
        elseif ($this->feed_type == ATOM && in_array($el, $this->_CONTENT_CONSTRUCTS)) {
            // avoid clashing w/ RSS mod_content
            if ($el === 'content') {
                $el = 'atom_content';
            }

            $this->incontent = $el;
        } // if inside an Atom content construct (e.g. content or summary) field treat tags as text
        elseif ($this->feed_type == ATOM && $this->incontent) {
            // if tags are inlined, then flatten
            $attrs_str = implode(' ', array_map('map_attrs', array_keys($attrs), array_values($attrs)));

            $this->append_content("<$element $attrs_str>");

            array_unshift($this->stack, $el);
        }

        // Atom support many links per containging element.
        // Magpie treats link elements of type rel='alternate'
        // as being equivalent to RSS's simple link element.
        //
        elseif ($this->feed_type == ATOM && $el === 'link') {
            if (isset($attrs['rel']) && $attrs['rel'] === 'alternate') {
                $link_el = 'link';
            } else {
                $link_el = 'link_' . $attrs['rel'];
            }

            $this->append($link_el, $attrs['href']);
        } // set stack[0] to current element
        else {
            array_unshift($this->stack, $el);
        }
    }

    /**
     * @param $p
     * @param $text
     */
    public function feed_cdata($p, $text) {
        if ($this->feed_type == ATOM && $this->incontent) {
            $this->append_content($text);
        } else {
            $current_el = implode('_', array_reverse($this->stack));
            $this->append($current_el, $text);
        }
    }

    /**
     * @param $p
     * @param $el
     */
    public function feed_end_element($p, $el) {
        $el = strtolower($el);

        if ($el === 'item' || $el === 'entry') {
            $this->items[]      = $this->current_item;
            $this->current_item = array();
            $this->initem       = false;
        } elseif ($this->feed_type == RSS && $this->current_namespace === '' && $el === 'textinput') {
            $this->intextinput = false;
        } elseif ($this->feed_type == RSS && $this->current_namespace === '' && $el === 'image') {
            $this->inimage = false;
        } elseif ($this->feed_type == ATOM && in_array($el, $this->_CONTENT_CONSTRUCTS)) {
            $this->incontent = false;
        } elseif ($el === 'channel' || $el === 'feed') {
            $this->inchannel = false;
        } elseif ($this->feed_type == ATOM && $this->incontent) {
            // balance tags properly
            // note:  i don't think this is actually neccessary
            if ($this->stack[0] == $el) {
                $this->append_content("</$el>");
            } else {
                $this->append_content("<$el />");
            }

            array_shift($this->stack);
        } else {
            array_shift($this->stack);
        }

        $this->current_namespace = false;
    }

    /**
     * @param        $str1
     * @param string $str2
     */
    public function concat(&$str1, $str2 = '') {
        if (!isset($str1)) {
            $str1 = '';
        }
        $str1 .= $str2;
    }

    /**
     * @param $text
     */
    public function append_content($text) {
        if ($this->initem) {
            $this->concat($this->current_item[$this->incontent], $text);
        } elseif ($this->inchannel) {
            $this->concat($this->channel[$this->incontent], $text);
        }
    }

    // smart append - field and namespace aware
    /**
     * @param $el
     * @param $text
     */
    public function append($el, $text) {
        if (!$el) {
            return;
        }
        if ($this->current_namespace) {
            if ($this->initem) {
                $this->concat($this->current_item[$this->current_namespace][$el], $text);
            } elseif ($this->inchannel) {
                $this->concat($this->channel[$this->current_namespace][$el], $text);
            } elseif ($this->intextinput) {
                $this->concat($this->textinput[$this->current_namespace][$el], $text);
            } elseif ($this->inimage) {
                $this->concat($this->image[$this->current_namespace][$el], $text);
            }
        } else {
            if ($this->initem) {
                $this->concat($this->current_item[$el], $text);
            } elseif ($this->intextinput) {
                $this->concat($this->textinput[$el], $text);
            } elseif ($this->inimage) {
                $this->concat($this->image[$el], $text);
            } elseif ($this->inchannel) {
                $this->concat($this->channel[$el], $text);
            }
        }
    }

    public function normalize() {
        // if atom populate rss fields
        if ($this->is_atom()) {
            $this->channel['description'] = $this->channel['tagline'];
            for ($i = 0, $iMax = count($this->items); $i < $iMax; ++$i) {
                $item = $this->items[$i];
                if (isset($item['summary'])) {
                    $item['description'] = $item['summary'];
                }
                if (isset($item['atom_content'])) {
                    $item['content']['encoded'] = $item['atom_content'];
                }

                $atom_date = isset($item['issued']) ? $item['issued'] : @$item['modified'];
                if ($atom_date) {
                    $epoch = @parse_w3cdtf($atom_date);
                    if ($epoch && $epoch > 0) {
                        $item['date_timestamp'] = $epoch;
                    }
                }

                $this->items[$i] = $item;
            }
        } elseif ($this->is_rss()) {
            $this->channel['tagline'] = $this->channel['description'];
            for ($i = 0, $iMax = count($this->items); $i < $iMax; ++$i) {
                $item = $this->items[$i];
                if (isset($item['description'])) {
                    $item['summary'] = $item['description'];
                }
                if (isset($item['content']['encoded'])) {
                    $item['atom_content'] = $item['content']['encoded'];
                }

                if ($this->is_rss() === '1.0' && isset($item['dc']['date'])) {
                    $epoch = @parse_w3cdtf($item['dc']['date']);
                    if ($epoch && $epoch > 0) {
                        $item['date_timestamp'] = $epoch;
                    }
                } elseif (isset($item['pubdate'])) {
                    $epoch = @strtotime($item['pubdate']);
                    if ($epoch > 0) {
                        $item['date_timestamp'] = $epoch;
                    }
                }

                $this->items[$i] = $item;
            }
        }
    }

    /**
     * @return bool
     */
    public function is_rss() {
        if ($this->feed_type == RSS) {
            return $this->feed_version;
        } else {
            return false;
        }
    }

    /**
     * @return bool
     */
    public function is_atom() {
        if ($this->feed_type == ATOM) {
            return $this->feed_version;
        } else {
            return false;
        }
    }

    /**
     * return XML parser, and possibly re-encoded source
     * @param $source
     * @param $out_enc
     * @param $in_enc
     * @param $detect
     * @return array
     */
    public function create_parser($source, $out_enc, $in_enc, $detect) {
        if (substr(phpversion(), 0, 1) == 5) {
            $parser = $this->php5_create_parser($in_enc, $detect);
        } else {
            list($parser, $source) = $this->php4_create_parser($source, $in_enc, $detect);
        }
        if ($out_enc) {
            $this->encoding = $out_enc;
            xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, $out_enc);
        }

        return array($parser, $source);
    }

    /**
     * Instantiate an XML parser under PHP5
     *
     * PHP5 will do a fine job of detecting input encoding
     * if passed an empty string as the encoding.
     *
     * All hail libxml2!
     * @param $in_enc
     * @param $detect
     * @return resource
     */
    public function php5_create_parser($in_enc, $detect) {
        // by default php5 does a fine job of detecting input encodings
        if (!$detect && $in_enc) {
            return xml_parser_create($in_enc);
        } else {
            return xml_parser_create('');
        }
    }

    /**
     * Instaniate an XML parser under PHP4
     *
     * Unfortunately PHP4's support for character encodings
     * and especially XML and character encodings sucks.  As
     * long as the documents you parse only contain characters
     * from the ISO-8859-1 character set (a superset of ASCII,
     * and a subset of UTF-8) you're fine.  However once you
     * step out of that comfy little world things get mad, bad,
     * and dangerous to know.
     *
     * The following code is based on SJM's work with FoF
     * @see http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
     * @param $source
     * @param $in_enc
     * @param $detect
     * @return array
     */
    public function php4_create_parser($source, $in_enc, $detect) {
        if (!$detect) {
            return array(xml_parser_create($in_enc), $source);
        }

        if (!$in_enc) {
            if (preg_match('/<?xml.*encoding=[\'"](.*?)[\'"].*?>/m', $source, $m)) {
                $in_enc                = strtoupper($m[1]);
                $this->source_encoding = $in_enc;
            } else {
                $in_enc = 'UTF-8';
            }
        }

        if ($this->known_encoding($in_enc)) {
            return array(xml_parser_create($in_enc), $source);
        }

        /*
        // the dectected encoding is not one of the simple encodings PHP knows

        // attempt to use the iconv extension to
        // cast the XML to a known encoding
        // @see http://php.net/iconv

        if (function_exists('iconv')) {
            $encoded_source = iconv($in_enc,'UTF-8', $source);
            if ($encoded_source) {
                return array(xml_parser_create('UTF-8'), $encoded_source);
            }
        }

        // iconv didn't work, try mb_convert_encoding
        // @see http://php.net/mbstring
        if (function_exists('mb_convert_encoding')) {
            $encoded_source = iconv($source, 'UTF-8', $in_enc );
            if ($encoded_source) {
                return array(xml_parser_create('UTF-8'), $encoded_source);
            }
        }

        // else
        $this->error("Feed is in an unsupported character encoding. ($in_enc) " .
                     "You may see strange artifacts, and mangled characters.",
                     E_USER_NOTICE);
        */

        return array(xml_parser_create(), $source);
    }

    /**
     * @param $enc
     * @return bool|string
     */
    public function known_encoding($enc) {
        $enc = strtoupper($enc);
        if (in_array($enc, $this->_KNOWN_ENCODINGS)) {
            return $enc;
        } else {
            return false;
        }
    }

    /**
     * @param     $errormsg
     * @param int $lvl
     */
    public function error($errormsg, $lvl = E_USER_WARNING) {
        // append PHP's error message if track_errors enabled
        if (!empty($php_errormsg)) {
            $errormsg .= " ($php_errormsg)";
        }
        if (MAGPIE_DEBUG) {
            trigger_error($errormsg, $lvl);
        } else {
            error_log($errormsg, 0);
        }

        $notices = E_USER_NOTICE | E_NOTICE;
        if ($lvl & $notices) {
            $this->WARNING = $errormsg;
        } else {
            $this->ERROR = $errormsg;
        }
    }
} // end class RSS

/**
 * @param $k
 * @param $v
 * @return string
 */
function map_attrs($k, $v) {
    return "$k=\"$v\"";
}

/**
 * @param $date_str
 * @return int
 */
function parse_w3cdtf($date_str) {
    # regex to match wc3dtf
    $pat = "/(\d{4})-(\d{2})-(\d{2})[T]?(\d{2})?[:]?(\d{2})?(:(\d{2}))?(?:([-+])(\d{2}):?(\d{2})|(Z))?/";

    if (preg_match($pat, $date_str, $match)) {
        list($year, $month, $day, $hours, $minutes, $seconds) = array(
            $match[1],
            $match[2],
            $match[3],
            $match[4],
            $match[5],
            $match[6]
        );

        # calc epoch for current date assuming GMT
        $epoch = gmmktime((int)$hours, (int)$minutes, (int)$seconds, (int)$month, (int)$day, (int)$year);

        $offset = 0;
        if ($match[10] === 'Z') {
            # zulu time, aka GMT
        } else {
            list($tz_mod, $tz_hour, $tz_min) = array($match[8], $match[9], $match[10]);

            # zero out the variables
            if (!$tz_hour) {
                $tz_hour = 0;
            }
            if (!$tz_min) {
                $tz_min = 0;
            }

            $offset_secs = (($tz_hour * 60) + $tz_min) * 60;

            # is timezone ahead of GMT?  then subtract offset
            #
            if ($tz_mod == '+') {
                $offset_secs = $offset_secs * -1;
            }

            $offset = $offset_secs;
        }
        $epoch = $epoch + $offset;

        return $epoch;
    } else {
        return -1;
    }
}
