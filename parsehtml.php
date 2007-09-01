<?php
/**
 * parseHTML is a HTML parser which works with PHP 4 and above.
 * It tries to handle invalid HTML to some degree.
 *
 * @version 1.0 beta
 * @author Milian Wolff (mail@milianw.de, http://milianw.de)
 * @license LGPL, see LICENSE_LGPL.txt and the summary below
 * @copyright (C) 2007  Milian Wolff
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 */
class parseHTML {
	/**
	 * tags which are always empty (<br /> etc.)
	 *
	 * @var array<string>
	 */
	var $emptyTags = array(
		'br',
		'hr',
		'input',
		'img',
	);
	/**
	 * tags with preformatted text
	 * whitespaces wont be touched in them
	 *
	 * @var array<string>
	 */
	var $preformattedTags = array(
		'script',
		'style',
		'pre',
		'code',
	);
	/**
	 * html to be parsed
	 *
	 * @var string
	 */
	var $html = '';
	/**
	 * node type:
	 *
	 * - tag (see isStartTag)
	 * - text (includes cdata)
	 * - comment
	 * - doctype
	 * - pi (processing instruction)
	 *
	 * @var string
	 */
	var $nodeType = '';
	/**
	 * current node content, i.e. either a
	 * simple string (text node), or something like
	 * <tag attrib="value"...>
	 *
	 * @var string
	 */
	var $node = '';
	/**
	 * wether current node is an opening tag (<a>) or not (</a>)
	 * set to NULL if current node is not a tag
	 * NOTE: empty tags (<br />) set this to true as well!
	 *
	 * @var bool | null
	 */
	var $isStartTag = null;
	/**
	 * wether current node is an empty tag (<br />) or not (<a></a>)
	 *
	 * @var bool | null
	 */
	var $isEmptyTag = null;
	/**
	 * tag name
	 *
	 * @var string | null
	 */
	var $tagName = '';
	/**
	 * attributes of current tag
	 *
	 * @var array (attribName=>value) | null
	 */
	var $tagAttributes = null;
	/**
	 * wether the current tag is a block element
	 *
	 * @var bool | null
	 */
	var $isBlockElement = null;

	/**
	 * keep whitespace
	 *
	 * @var int
	 */
	var $keepWhitespace = 0;
	/**
	 * list of open tags
	 * count this to get current depth
	 *
	 * @var array
	 */
	var $openTags = array();
	/**
	 * get next node, set $this->html prior!
	 *
	 * @param void
	 * @return bool
	 */
	function nextNode() {
		if (empty($this->html)) {
			# we are done with parsing the html string
			return false;
		}
		static $skipWhitespace = true;
		# dont truncate whitespaces for <code> or <pre> contents
		if ($this->isStartTag && !$this->isEmptyTag) {
			array_push($this->openTags, $this->tagName);
			if (in_array($this->tagName, $this->preformattedTags)) {
				$this->keepWhitespace++;
			}
		}

		if ($this->html[0] == '<') {
			$token = substr($this->html, 0, 9);
			if (substr($token, 0, 2) == '<?') {
				# xml prolog or other pi's
				trigger_error('this might need some work', E_USER_NOTICE);
				$pos = strpos($this->html, '>');
				$this->setNode('pi', $pos + 1);
				return true;
			}
			if (substr($token, 0, 4) == '<!--') {
				# comment
				$pos = strpos($this->html, '-->');
				if ($pos === false) {
					# could not find a closing -->, use next gt instead
					# this is firefox' behaviour
					$pos = strpos($this->html, '>') + 1;
				} else {
					$pos += 3;
				}
				$this->setNode('comment', $pos);

				$skipWhitespace = true;
				return true;
			}
			if ($token == '<!DOCTYPE') {
				# doctype
				$this->setNode('doctype', strpos($this->html, '>')+1);

				$skipWhitespace = true;
				return true;
			}
			if ($token == '<![CDATA[') {
				# cdata, use text node

				# remove leading <![CDATA[
				$this->html = substr($this->html, 9);

				$this->setNode('text', strpos($this->html, ']]>')+3);

				# remove trailing ]]> and trim
				$this->node = substr($this->node, 0, -3);
				$this->handleWhitespaces();

				$skipWhitespace = true;
				return true;
			}
			if ($this->parseTag()) {
				# seems to be a tag
				# handle whitespaces
				if ($this->isBlockElement) {
					$skipWhitespace = true;
				} else {
					$skipWhitespace = false;
				}
				return true;
			}
		}
		if ($this->keepWhitespace) {
			$skipWhitespace = false;
		}
		# when we get here it seems to be a text node
		$pos = strpos($this->html, '<');
		if ($pos === false) {
			$pos = strlen($this->html);
		}
		$this->setNode('text', $pos);
		$this->handleWhitespaces();
		if ($skipWhitespace && $this->node == ' ') {
			return $this->nextNode();
		}
		$skipWhitespace = false;
		return true;
	}
	/**
	 * parse tag, set tag name and attributes, see if it's a closing tag and so forth...
	 *
	 * @param void
	 * @return bool
	 */
	function parseTag() {
		# tag definitions must be on one line
		$endpos = strpos($this->html, "\n");
		if (!$endpos) {
			$endpos = strlen($this->html);
		}

		$a_ord = ord('a');
		$z_ord = ord('z');

		$tagName = '';

		$pos = 1;
		$isStartTag = $this->html[$pos] != '/';
		if (!$isStartTag) {
			$pos++;
		}
		# get tagName
		while ($pos < $endpos) {
			$pos_ord = ord($this->html[$pos]);
			if ($pos_ord >= $a_ord && $pos_ord <= $z_ord) {
				$tagName .= $this->html[$pos];
				$pos++;
			} else {
				$pos--;
				break;
			}
		}
		if (empty($tagName)) {
			# something went wrong => invalid tag
			$this->invalidTag();
			return false;
		}

		$tagName = strtolower($tagName);

		$isEmptyTag = false;
		$attributes = array();
		$currAttrib = '';
		while ($pos < $endpos) {
			$pos++;
			# close tag
			if ($this->html[$pos] == '>' || $this->html[$pos].$this->html[$pos+1] == '/>') {
				if ($this->html[$pos] == '/') {
					$emptyTag = true;
				}
				break;
			}

			$pos_ord = ord($this->html[$pos]);
			if ($pos_ord >= $a_ord && $pos_ord <= $z_ord) {
				# attribute name
				$currAttrib .= $this->html[$pos];
			} elseif (in_array($this->html[$pos], array(' ', "\t"))) {
				# drop whitespace
			} elseif (in_array($this->html[$pos].$this->html[$pos+1], array('="', "='"))) {
				$pos++;
				$await = $this->html[$pos]; # single or double quote
				$pos++;
				$value = '';
				while ($pos < $endpos && $this->html[$pos] != $await) {
					$value .= $this->html[$pos];
					$pos++;
				}
				$attributes[$currAttrib] = $value;
				$currAttrib = '';
			} else {
				$this->invalidTag();
				return false;
			}
		}
		if (!empty($currAttrib)) {
			# html 4 allows something like <option selected> instead of <option selected="selected">
			$attributes[$currAttrib] = $currAttrib;
		}
		if (!$isStartTag) {
			if (!empty($attributes) || $tagName != end($this->openTags)) {
				# end tags must not contain any attributes
				# or maybe we did not expect a different tag to be closed
				$this->invalidTag();
				return false;
			}
			array_pop($this->openTags);
			if (in_array($tagName, $this->preformattedTags)) {
				$this->keepWhitespace--;
			}
		}
		$pos++;
		$this->node = substr($this->html, 0, $pos);
		$this->html = substr($this->html, $pos);
		$this->tagName = $tagName;
		$this->tagAttributes = $attributes;
		$this->isStartTag = $isStartTag;
		$this->isEmptyTag = $isEmptyTag || in_array($tagName, $this->emptyTags);
		$this->nodeType = 'tag';
		$this->isBlockElement = $this->isBlockElement($tagName);
		return true;
	}
	/**
	 * handle invalid tags
	 *
	 * @param void
	 * @return void
	 */
	function invalidTag() {
		#trigger_error('invalid tag ('.str_replace("\n", ' ', substr($this->html, 0, 25))."...) encountered, will try to handle it gracefully\n".called()."\n", E_USER_NOTICE);
		$this->html = substr_replace($this->html, '&lt;', 0, 1);
	}
	/**
	 * update all vars and make $this->html shorter
	 *
	 * @param string $type see description for $this->nodeType
	 * @param int $pos to which position shall we cut?
	 * @return void
	 */
	function setNode($type, $pos) {
		if ($this->nodeType == 'tag') {
			# set tag specific vars to null
			# $type == tag should not be called here
			# see this::parseTag() for more
			$this->tagName = null;
			$this->tagAttributes = null;
			$this->isStartTag = null;
			$this->isEmptyTag = null;
			$this->isBlockElement = null;

		}
		$this->nodeType = $type;
		$this->node = substr($this->html, 0, $pos);
		$this->html = substr($this->html, $pos);
	}
	/**
	 * check if $this->html begins with $str
	 *
	 * @param string $str
	 * @return bool
	 */
	function match($str) {
		return substr($this->html, 0, strlen($str)) == $str;
	}
	/**
	 * check if $tagName is a block element
	 *
	 * @param string $tagName
	 * @return bool | null
	 */
	function isBlockElement($tagName) {
		static $elements = array (
			# tag name => <bool> is block
			# block elements
			'address' => true,
			'blockquote' => true,
			'center' => true,
			'del' => true,
			'dir' => true,
			'div' => true,
			'dl' => true,
			'fieldset' => true,
			'form' => true,
			'h1' => true,
			'h2' => true,
			'h3' => true,
			'h4' => true,
			'h5' => true,
			'h6' => true,
			'hr' => true,
			'ins' => true,
			'isindex' => true,
			'menu' => true,
			'noframes' => true,
			'noscript' => true,
			'ol' => true,
			'p' => true,
			'pre' => true,
			'table' => true,
			'ul' => true,
			# set table elements and list items to block as well
			'td' => true,
			'tr' => true,
			'th' => true,
			'li' => true,
			'dd' => true,
			'dt' => true,
			# header items and html / body as well
			'html' => true,
			'body' => true,
			'head' => true,
			'meta' => true,
			'style' => true,
			'title' => true,
			# inline elements
			'a' => false,
			'abbr' => false,
			'acronym' => false,
			'applet' => false,
			'b' => false,
			'basefont' => false,
			'bdo' => false,
			'big' => false,
			'br' => false,
			'button' => false,
			'cite' => false,
			'code' => false,
			'del' => false,
			'dfn' => false,
			'em' => false,
			'font' => false,
			'i' => false,
			'img' => false,
			'ins' => false,
			'input' => false,
			'iframe' => false,
			'kbd' => false,
			'label' => false,
			'map' => false,
			'object' => false,
			'q' => false,
			'samp' => false,
			'script' => false,
			'select' => false,
			'small' => false,
			'span' => false,
			'strong' => false,
			'sub' => false,
			'sup' => false,
			'textarea' => false,
			'tt' => false,
			'var' => false,
		);
		return $elements[$tagName];
	}
	/**
	 * truncate whitespaces
	 *
	 * @param void
	 * @return void
	 */
	function handleWhitespaces() {
		if ($this->keepWhitespace) {
			# <pre> or <code> before...
			return;
		}
		# truncate multiple whitespaces to a single one
		$this->node = preg_replace('#\s+#s', ' ', $this->node);
	}
}

/**
 * indent a HTML string properly
 *
 * @param string $html
 * @param string $indent optional
 * @return string
 */
function indentHTML($html, $indent = "  ") {
	$parser = new parseHTML;
	$parser->html = $html;
	$html = '';
	$last = true;
	$indent_a = array();
	while($parser->nextNode()) {
		if ($parser->nodeType == 'tag' && $parser->isBlockElement) {
			$isPreOrCode = in_array($parser->tagName, array('code', 'pre'));
			if (!$parser->keepWhitespace && !$last && !$isPreOrCode) {
				$html .= "\n";
			}
			if ($parser->isStartTag) {
				$html .= implode($indent_a);
				array_push($indent_a, $indent);
			} else {
				array_pop($indent_a);
				if (!$isPreOrCode) {
					$html .= implode($indent_a);
				}
			}
			$html .= $parser->node;
			if (!$parser->keepWhitespace && !($isPreOrCode && $parser->isStartTag)) {
				$html .= "\n";
			}
			$last = true;
		} else {
			if ($last && !$parser->keepWhitespace) {
				$html .= implode($indent_a);
			}
			$html .= $parser->node;

			if (in_array($parser->nodeType, array('comment', 'pi', 'doctype'))) {
				$html .= "\n";
			} else {
				$last = false;
			}
		}
	}
	return $html;
}
/*
# testcase / example
error_reporting(E_ALL);

$html = '<?xml version="1.0" encoding="iso-8859-1"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
  <title>NewsPaper</title>
</head>
<body>
	<div style=">">
		asdfasdf
	</div>
</body>
</html>
';
#$html = '<a href="asdfasdf"       title=\'asdf\' foo="bar">asdf</a>';
echo indentHTML($html);
die();
*/