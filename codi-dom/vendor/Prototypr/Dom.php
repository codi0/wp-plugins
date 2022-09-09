<?php

namespace Prototypr;

class DomNode {

	public $node;

	protected $charset = '';
	protected $isHtml = true;
	protected $level = 0;
	protected $levelInc = false;

	public function __construct($node, $charset='', $isHtml=true) {
		//set vars
		$this->node = $node;
		$this->charset = $this->charset;
		$this->isHtml = $this->isHtml;
	}

	public function has() {
		//set vars
		$found = false;
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				if(!empty($old)) {
					$found = true;
					break 2;
				}
			}
		}
		//return
		return $found;
	}

	public function get($type='string', $first=true) {
		//set vars
		$res = array();
		$class = __CLASS__;
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//temp data
			$temp = array();
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//string or node?
				if(stripos($type, 'node') === 0) {
					//create node object
					$res[] = new $class(array(
						'node' => $type == 'node:clone' ? $old->cloneNode(true) : $old,
						'charset' => $this->charset,
						'isHtml' => $this->isHtml,
					));
				} else {
					//convert to string
					$old = $this->createString($old);
					$temp[] = $old;
				}
			}
			//to string?
			if(stripos($type, 'node') !== 0) {
				$res[] = implode("", $temp);
			}
			//first only?
			if($first) {
				$res = isset($res[0]) ? $res[0] : null;
				break;
			}
		}
		//reset
		$this->reset();
		//return
		return $res;
	}

	public function getAll($type='string') {
		return $this->get($type, false);
	}

	public function getInner($asNodes=false, $first=true) {
		return $this->children()->get($asNodes, $first);
	}

	public function set($data) {
		//create nodes
		$newNodes = $this->createNodes($data);
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//set vars
				$parent = $old->parentNode;
				$sibling = $old->nextSibling;
				//remove old node
				$parent->removeChild($old);
			}
			//valid parent?
			if(!isset($parent) || !$parent) {
				continue;
			}
			//add new nodes
			foreach($newNodes as $new) {
				if($sibling) {
					$parent->insertBefore($new->cloneNode(true), $sibling);
				} else {
					$parent->appendChild($new->cloneNode(true));
				}
			}
		}
		//return
		return $this->reset();
	}

	public function setInner($data) {
		//replace children?
		if($this->hasChildren()) {
			return $this->children()->set($data);
		}
		//insert child
		return $this->insertChild($data);
	}

	public function innerHTML($data = null) {
		//set html?
		if($data !== null) {
			return $this->setInner($data);
		}
		//return
		return $this->getInner();
	}

	public function fill(array $data) {
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				$this->fillNodeRecursive($old, $data);
			}
		}
		//return
		return $this->reset();
	}

	public function fillInner(array $data) {
		return $this->children()->fill($data);
	}

	public function wrap($data) {
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//create nodes
			$newNodes = $this->createNodes($data);
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//set vars
				$parent = $old->parentNode;
				$sibling = $old->nextSibling;
				//update new nodes
				foreach($newNodes as $new) {
					$new->appendChild($old->cloneNode(true));
				}
				//remove old node
				$parent->removeChild($old);
			}
			//valid parent?
			if(!isset($parent) || !$parent) {
				continue;
			}
			//add new nodes
			foreach($newNodes as $new) {
				if($sibling) {
					$parent->insertBefore($new->cloneNode(true), $sibling);
				} else {
					$parent->appendChild($new->cloneNode(true));
				}
			}
		}
		//return
		return $this->reset();
	}

	public function wrapInner($data) {
		return $this->children()->wrap($data);
	}

	public function unwrap() {
		//set vars
		$remove = array();
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//get data
				$hash = spl_object_hash($old->parentNode);
				//already processed?
				if(isset($remove[$hash])) {
					continue;
				}
				//add to queue
				$remove[$hash] = $old->parentNode;
				//loop through all children
				foreach($old->parentNode->childNodes as $child) {
					if($old->parentNode->parentNode) {
						$old->parentNode->parentNode->insertBefore($child->cloneNode(true), $old->parentNode);
					}
				}
			}
		}
		//loop through removals
		foreach($remove as $r) {
			$r->parentNode->removeChild($r);
		}
		//return
		return $this->reset();	
	}

	public function unwrapInner() {
		return $this->children()->unwrap();
	}

	public function remove() {
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				$old->parentNode->removeChild($old);
			}
		}
		//return
		return $this->reset();
	}

	public function removeInner() {
		return $this->children()->remove();
	}

	public function insertChild($data, $first=false) {
		//create nodes
		$newNodes = $this->createNodes($data);
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//loop through new nodes
				foreach($newNodes as $new) {
					//node has children?
					if($first && $old->firstChild) {
						$old->insertBefore($new->cloneNode(true), $old->firstChild);
					} else {
						$old->appendChild($new->cloneNode(true));
					}
				}
			}
		}
		//return
		return $this->reset();
	}

	public function insertBefore($data) {
		//create nodes
		$newNodes = $this->createNodes($data);
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//loop through new nodes
				foreach($newNodes as $new) {
					$old->parentNode->insertBefore($new->cloneNode(true), $old);
				}
			}
		}
		//return
		return $this->reset();
	}

	public function insertAfter($data) {
		//create nodes
		$newNodes = $this->createNodes($data);
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//loop through new nodes
				foreach($newNodes as $new) {
					//node has sibling?
					if($old->nextSibling) {
						$old->parentNode->insertBefore($new->cloneNode(true), $old->nextSibling);
					} else {
						$old->parentNode->appendChild($new->cloneNode(true));
					} 
				}
			}
		}
		//return
		return $this->reset();
	}

	public function getAttr($key, $first=true, $incNode=false) {
		//set vars
		$res = array();
		$key = strtolower($key);
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				if($incNode) {
					$res[] = array( 'value' => $old->getAttribute($key), 'node' => $old );
				} else {
					$res[] = $old->getAttribute($key);
				}
			}
		}
		//reset
		$this->reset();
		//return
		return $first ? (isset($res[0]) ? $res[0] : null) : $res;
	}

	public function setAttr($key, $val=null) {
		//set vars
		$key = is_array($key) ? $key : array( $key => $val );
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through array
			foreach($this->prepNodes($nodes) as $old) {
				//loop through attr
				foreach($key as $k => $v) {
					$old->setAttribute($k, $v);
				}
			}
		}
		//reset
		$this->reset();
		//return
		return $this;
	}

	public function removeAttr($key) {
		//set vars
		$key = is_array($key) ? $key : array( $key );
		//loop through matches
		foreach($this->initNode() as $nodes) {
			//loop through nodes
			foreach($this->prepNodes($nodes) as $old) {
				//loop through attr
				foreach($key as $k) {
					$old->removeAttribute($k);
				}
			}
		}
		//reset
		$this->reset();
		//return
		return $this;
	}

	public function hasClass($name) {
		//get all elements
		$elements = $this->getAttr('class', false, true);
		//loop through elements
		foreach($elements as $el) {
			//get parts
			$parts = array_map('trim', explode(" ", $el['value']));
			//loop through parts
			foreach($parts as $k => $v) {
				if($v && $v == $name) {
					return true;
				}
			}
		}
		//not found
		return false;
	}

	public function addClass($name) {
		//get elements
		$elements = $this->getAttr('class', false, true);
		//loop through array
		foreach($elements as $el) {
			//get parts
			$found = false;
			$parts = array_map('trim', explode(" ", $el['value']));
			//loop through parts
			foreach($parts as $k => $v) {
				if($v && $v == $name) {
					$found = true;
					break;
				}
			}
			//add class?
			if($found === false) {
				$el['node']->setAttribute('class', trim($el['value'] . ' ' . $name));
			}
		}
		//chain it
		return $this;
	}

	public function removeClass($name) {
		//get elements
		$elements = $this->getAttr('class', false, true);
		//loop through array
		foreach($elements as $el) {
			//get parts
			$found = false;
			$parts = array_map('trim', explode(" ", $el['value']));
			//loop through parts
			foreach($parts as $k => $v) {
				if($v && $v == $name) {
					unset($parts[$k]);
					$found = true;
				}
			}
			//remove class?
			if($found !== false) {
				$el['node']->setAttribute('class', implode(" ", $parts));
			}
		}
		//chain it
		return $this;
	}

	public function hasChildren() {
		//set vars
		$res = false;
		//loop through nodes
		foreach($this->initNode() as $node) {
			//children found?
			if($node->childNodes->length > 0) {
				$res = true;
			}
		}
		//return
		return $res;
	}

	public function children($num=1, $inc=false) {
		//set level
		$this->level = $this->level - (int) $num;
		//include all levels?
		$this->levelInc = (bool) $inc;
		//return
		return $this;
	}

	public function parent($num=1, $inc=false) {
		//set level
		$this->level = $this->level + (int) $num;
		//include all levels?
		$this->levelInc = (bool) $inc;
		//return
		return $this;
	}

	public function reset() {
		//clear vars
		$this->level = 0;
		$this->levelInc = false;
		//return
		return $this;
	}

	protected function initNode() {
		//convert object to array?
		if($this->node instanceOf \DOMNode) {
			$this->node = array( $this->node );
		}
		//return
		return $this->node ?: array();
	}

	protected function prepNodes(\DOMNode $node) {
		//level unchanged?
		if($this->level == 0) {
			return array( $node );
		}
		//set vars
		$res = array();
		$prop = $this->level > 0 ? 'parentNode' : 'childNodes';
		$limit = $this->level > 0 ? $this->level : $this->level * -1;
		//loop through levels
		for($z=0; $z < $limit; $z++) {
			//prop found?
			if(!isset($node->$prop) || !$node->$prop) {
				return false;
			}
			//reset array?
			if(!$this->levelInc) {
				$res = array();
			}
			//update node
			$node = $node->$prop;
			//prepare nodes
			if(!$node instanceOf \DOMNodeList) {
				if($node !== (array) $node) {
					$node = array( $node );
				}
			}
			//loop through array
			foreach($node as $old) {
				$res[] = $old;
			}
		}
		//return
		return $res;
	}

	protected function createDom($data='') {
		//trim data
		$data = trim($data);
		//get charset?
		if(!$this->charset) {
			$this->charset = $this->detectCharset($data);
		}
		//check data encoding
		$data = $this->checkEncoding($data, $this->charset);
		//create DOM object
		$dom = new \DOMDocument('1.0', $this->charset);
		//get load method
		$method = $this->isHtml ? 'loadHTML' : 'loadXML';
		//load to dom?
		if(strlen($data) > 0) {
			libxml_use_internal_errors(true);
			$dom->$method($data);
			libxml_clear_errors();
		}
		//remove cdata?
		if(stripos($data, '<script') !== false && stripos($data, '<![CDATA') === false) {
			$this->removeCdata($dom);
		}
		//return
		return $dom;
	}

	protected function createNodes($data) {
		//set vars
		$res = array();
		$class = __CLASS__;
		$method = __FUNCTION__;
		$data = is_array($data) ? $data : array($data);
		//set dom object
		foreach($this->initNode() as $node) {
			$dom = $node->ownerDocument;
			break;
		}
		//does dom exist?
		if(!isset($dom) || !$dom) {
			return $res;
		}
		//loop through data
		foreach($data as $d) {
			if($d instanceOf \DOMNode) {
				//object
				$res[] = $d;
			} elseif($d instanceOf $class) {
				$res[] = is_array($d->node) ? $d->node[0] : $d->node;
			} elseif(is_array($d)) {
				//array
				$res += $this->$method($d);
			} elseif(is_string($d)) {
				//is raw text?
				if($d && strip_tags($d) === $d) {
					//create text node
					$res[] = $dom->createTextNode($d);
				} else {
					//load dom
					$nodes = null;
					$tmp = $this->createDom($d);
					//search nodes
					foreach(array( 'body', 'head', 'html' ) as $el) {
						//match found?
						if($nodes = $tmp->getElementsByTagName($el)->item(0)) {
							break;
						}
					}
					//import nodes?
					if($nodes && $nodes->childNodes) {
						//loop through children
						foreach($nodes->childNodes as $node) {
							$res[] = $dom->importNode($node, true);
						}
					}
				}
			}
		}
		//return
		return $res;
	}

	protected function createString(\DOMNode $node) {
		return $node->ownerDocument->saveXML($node);
	}

	protected function fillNodeRecursive($node, array $data) {
		//set vars
		$count = 0;
		$method = __FUNCTION__;
		//has next sibling?
		if(!$current = $this->findNextNode($node->firstChild, 1)) {
			return;
		}
		//loop through data
		foreach($data as $k => $v) {
			//set vars
			$count++;
			$updated = false;
			//set node value
			if(is_array($v) && isset($v[0])) {
				$this->$method($current, $v);
			} else {
				//delete child nodes
				while($current->firstChild) {
					$current->removeChild($current->firstChild);
				}
				//get new nodes
				$new = $this->createNodes($v);
				//loop through nodes
				foreach($new as $n) {
					$current->appendChild($n);
				}
			}
			//get next sibling?
			if($next = $this->findNextNode($current->nextSibling, 1)) {
				$current = $next;
			} elseif($count < count($data)) {
				$current = $current->parentNode->appendChild($current->cloneNode(true));
			}
		}
	}

	protected function findNextNode($node, $type) {
		//loop it!
		while($node) {
			//type match?
			if($node->nodeType == (int) $type) {
				return $node;
			}
			//update node
			$node = $node->nextSibling;
		}
		//not found
		return null;
	}

	protected function detectCharset($data, $charset=null) {
		//auto-detect?
		if(!$charset && function_exists('mb_detect_encoding')) {
			//auto-detect
			$charset = mb_detect_encoding($data);
			//convert ascii?
			if($charset == 'ASCII') {
				$charset = 'UTF-8';
			}
		}
		//return (upper case)
		return strtoupper($charset ? $charset : 'UTF-8');
	}

	protected function checkEncoding($data, $charset=null) {
		//convert encoding?
		if(function_exists('mb_convert_encoding')) {
			$data = mb_convert_encoding($data, "HTML-ENTITIES", $charset);
		}
		//remove invalid characters?
		if(function_exists('iconv')) {
			$data = @iconv($charset, $charset . "//IGNORE", $data);
		}
		//return
		return $data;
	}

	protected function removeCdata($dom) {
		//has script nodes?
		if(!$scripts = $dom->getElementsByTagName('script')) {
			return;
		}
		//loop through scripts
		foreach($scripts as $s) {
			//is first node cdata?
			if($s->firstChild && $s->firstChild->nodeType == 4) {
				$cdata = $s->removeChild($s->firstChild);
				$text = $dom->createTextNode($cdata->nodeValue);
				$s->appendChild($text);
			}
		}
	}

}

class Dom extends DomNode {

	protected $dom;

	protected $token = '';
	protected $isFragment = false;

	public function __construct($input=null) {
		//set token
		$this->token = $this->generateRandStr(8);
		//load data
		if($input instanceOf \DomDocument) {
			$this->dom = $input;
			$this->isHtml = $this->dom->xmlVersion ? false : true;
		} elseif(is_string($input) && $input) {
			$this->load($input);
		}
	}

	public function load($data) {
		//set vars
		$data = trim($data);
		$token = $this->token;
		$this->isHtml = true;
		$this->isFragment = false;
		//is url?
		if(strpos($data, 'http') === 0 && strpos($data, '://') !== false) {
			$data = trim(file_get_contents($data));
		}
		//is xml?
		if(stripos($data, '<?xml') === 0) {
			$this->isHtml = false;
		}
		//is html?
		if($this->isHtml && stripos($data, "<!DOCTYPE") !== 0) {
			//html fragment
			$this->isFragment = true;
			//remove tags
			$data = preg_replace(array('/<html.*?>/i', '/<\/html>/i', '/<head.*?>.*?<\/head>/is', '/<body.*?>/i', '/<\/body>/i'), '', $data);
			//format fragment
			$data = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=' . strtolower($this->charset ?: 'utf-8') . '" /></head><body>' . $data . '</body></html>';
		} elseif(!$this->isHtml && stripos($data, '<?xml') !== 0) {
			//xml fragment
			$this->isFragment = true;
			//format data
			$data = '<?xml version="1.0"?>' . $data;
		}
		//script content hack
		$data = preg_replace_callback('/<script\b[^>]*>([\s\S]*?)<\/script>/ims', function($matches) use($token) {
			return str_replace($matches[1], str_replace(array( '<', '>' ), array( '$%' . $token, '%$' . $token ), $matches[1]), $matches[0]);
		}, $data);
		//load dom
		$this->dom = $this->createDom($data);
		//return
		return $this;
	}

	public function save($pretty=false) {
		//set vars
		$token = $this->token;
		//save output
		if($this->isHtml) {
			$data = @$this->dom->saveHTML();
		} else {
			$data = @$this->dom->saveXML($this->dom->documentElement);
		}
		//script content hack
		$data = preg_replace_callback('/<script\b[^>]*>([\s\S]*?)<\/script>/ims', function($matches) use($token) {
			return str_replace($matches[1], str_replace(array( '$%' . $token, '%$' . $token ), array( '<', '>' ), $matches[1]), $matches[0]);
		}, $data);
		//remove xml header?
		if($this->isHtml || $this->isFragment) {
			$data = preg_replace('/<\?xml.*?\?>/i', '', $data);
		}
		//html fragment?
		if($this->isHtml && $this->isFragment) {
			$data = preg_replace('~<(?:!DOCTYPE|/?(?:html|head|meta|body))[^>]*>\s*~i', '', $data);
		}
		//format output?
		if($pretty !== false) {
			$data = $this->formatOutput($data);
		}
		//return
		return trim($data);
	}

	public function select($selector) {
		//reset node
		$this->node = null;
		//translate to xpath
		$query = DomXpath::fromSelector($selector);
		//run query
		return $this->query($query);
	}

	public function query($query) {
		//set vars
		$query = trim($query);
		$xpath = new \DOMXPath($this->dom);
		//run query
		$this->node = @$xpath->query($query);
		//valid query?
		if($this->node === false) {
			throw new \Exception("Invalid xpath query - " . $query);
		}
		//return
		return $this;
	}

	public function document() {
		return $this->dom->documentElement;
	}

	protected function generateRandStr($length) {
		//set vars
		$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		//return output
		return substr(str_shuffle(str_repeat($chars, mt_rand(1, 10))), 1, $length);
	}

	protected function formatOutput($data) {
		//set vars
		$output = "";
		$padding = 0;
		$wasText = false;
		$data = preg_replace('/<[^<]*>/', "\n$0\n", $data);
		//convert to tokens
		$token = strtok($data, "\n");
		//start token loop
		while($token !== false) {
			//set vars
			$indent = 0;
			$empty = false;
			//valid token?
			if(!$token = trim($token)) {
				$token = strtok("\n");
				continue;
			}
			//not a tag?
			if($token[0] != '<') {
				$output = rtrim($output) . $token;
				$token = strtok("\n");
				$wasText = true;
				continue;
			}
			//check options
			if(preg_match('/.+<\/\w[^>]*>$/', $token, $matches)) {
				$indent = 0;
			} elseif(preg_match('/^<\/\w/', $token, $matches)) {
				$padding--;
			} elseif(preg_match('/^<\w[^>]*[^\/]>.*$/', $token, $matches)) {
				$indent = 1;
			}
			//get current line
			$line = $wasText ? $token : str_pad($token, strlen($token) + $padding, "\t", STR_PAD_LEFT);
			$lineTrim = trim($line);
			//empty element?
			if(substr($lineTrim, 0, 2) == '</') {
				$outputTrim = trim($output);
				$outputExp = explode("<", $outputTrim);
				$outputLast = $output_exp[count($outputExp)-1];
				if($outputLast[0] != '/' && $outputLast[strlen($outputLast)-1] == '>' && $outputLast[strlen($outputLast)-2] != '/') {
					$output = $outputTrim . $lineTrim . "\n";
					$empty = true;
				}
			}
			//add now?
			if(!$empty) {
				$output .= $line . "\n";
			}
			//next token
			$token = strtok("\n");
			$padding += $indent;
			$wasText = false;
		}
		//return
		return $output;
	}

	protected function initNode() {
		//create dom?
		if(!$this->dom) {
			$this->load('')->select('body');
		}
		//call parent
		return parent::initNode();
	}

}

//Adapted from: http://www.github.com/bkdotcom/CssXpath
class DomXpath {

	private static $cache = [];
	private static $strings = [];
	private static $clearStrings = true;

	public static function fromSelector($selector) {
		//is cached?
		if(isset(self::$cache[$selector])) {
			return self::$cache[$selector];
		}
		//transform selector
		$xpath = ' ' . $selector;
		//reset strings?
		if(self::$clearStrings) {
			self::$strings = [];
		}
		//regex replacements
		$regexs = array(

			array( '/([\s]?)\[(.*?)\]/', function($matches) {
				return self::transformAttr($matches);
			}),

			array( '/:contains\((.*?)\)/', function($matches) {
				self::$strings[] = '[contains(text(), "' . $matches[1] . '")]';
				return '[{' . (count(self::$strings) - 1) . '}]';
			}),

			array( '/([\s]?):not\((.*?)\)/', function($matches) {
				self::$clearStrings = false;
				$xpathNot = self::fromSelector($matches[2]);
				self::$clearStrings = true;
				$xpathNot = preg_replace('#^//\*\[(.+)\]#', '$1', $xpathNot);
				self::$strings[] = ($matches[1] ? '*' : '') . '[not(' . $xpathNot . ')]';
				return '[{' . (count(self::$strings) - 1) . '}]';
			}),
            
            array( '/\s{2,}/', function() {
				return ' ';
			}),
 
			array( '/\s*,\s*/', function () {
				return '|//';
			}),

			array( '/:(text|password|checkbox|radio|reset|file|hidden|image|datetime|datetime-local|date|month|time|week|number|range|email|url|search|tel|color)/', function($matches) {
				return '[@type="' . $matches[1] . '"]';
			}),

			array( '/([\s]?):button/', function($matches) {
				self::$strings[] = ($matches[1] ? '*' : '') . '[self::button or @type="button"]';
				return '[{' . (count(self::$strings) - 1) . '}]';
			}),

			array( '/([\s]?):input/', function($matches) {
				self::$strings[] = ($matches[1] ? '*' : '') . '[self::input or self::select or self::textarea or self::button]';
				return '[{' . (count(self::$strings) - 1) . '}]';
			}),

			array( '/([\s]?):submit/', function($matches) {
				self::$strings[] = ($matches[1] ? '*' : '') . '[@type="submit" or (self::button and not(@type))]';
				return '[{' . (count(self::$strings) - 1) . '}]';
			}),

            array( '/:header/', function() {
				self::$strings[] = '*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]';
				return '[{' . (count(self::$strings) - 1) . '}]';
            }),

			array( '/:(autofocus|checked|disabled|required|selected)/', function($matches) {
				return '[@' . $matches[1] . ']';
			}),

			array( '/:autocomplete/', function() {
				return '[@autocomplete="on"]';
			}),

			array( '/(\S*):nth-child\((\d+)\)/', function($matches) {
				return($matches[1] ? $matches[1] : '*') . '[' . $matches[2] . ']';
			}),

            array( '/(\S*):nth-last-child\((\d+)\)/', function($matches) {
				return ($matches[1] ? $matches[1] : '*') . '[position()=(last()-(' . $matches[2] . '-1))]';
			}),

			array( '/(\S*):last-child/', function($matches) {
				return ($matches[1] ? $matches[1] : '*') . '[last()]';
			}),

            array( '/(\S*):first-child/', function($matches) {
				return ($matches[1] ? $matches[1] : '*') . '[1]';
			}),

			array( '/\s*\+\s*([^\s]+)/', function($matches) {
				return '/following-sibling::' . $matches[1] . '[1]';
			}),

			array( '/\s*~\s*([^\s]+)/', function($matches) {
				return '/following-sibling::' . $matches[1];
			}),

			array( '/\s*>\s*/', function() {
				return '/';
			}),

			array( '/\s/', function() {
				return '//';
			}),

            array( '/([a-z0-9\]]?)#([a-z][-a-z0-9_]+)/i', function($matches) {
				return $matches[1] . ($matches[1] ? '' : '*') . '[@id="' . $matches[2] . '"]';
			}),

			array( '/([a-z0-9\]]?)\.(-?[_a-z]+[_a-z0-9-]*)/i', function($matches) {
				return $matches[1] . ($matches[1] ? '' : '*') . '[contains(concat(" ", normalize-space(@class), " "), " ' . $matches[2] . ' ")]';
            }, 1),

			array( '/:scope/', function() {
				return '//';
			}),

			array( '/^.+!.+$/', function($matches) {
				$subSelectors = explode(',', $matches[0]);
				foreach($subSelectors as $i => $subSelector) {
					$parts = explode('!', $subSelector);
					$subSelector = array_shift($parts);
					if(preg_match_all('/((?:[^\/]*\/?\/?)|$)/', $parts[0], $matches)) {
						$results = $matches[0];
						$results[] = str_repeat('/..', count($results) - 2);
						$subSelector .= implode('', $results);
					}
					$subSelectors[$i] = $subSelector;
				}
				return implode(',', $subSelectors);
			}),

			array( '/\[\{(\d+)\}\]/', function($matches) {
				return self::$strings[$matches[1]];
			}),

		);
		//process regex patterns
		foreach($regexs as $regCallback) {
			$limit = isset($regCallback[2]) ? $regCallback[2] : -1;
			if($limit < 0) {
				$xpath = preg_replace_callback($regCallback[0], $regCallback[1], $xpath);
				continue;
			}
			$count = 0;
			do {
				$xpath = preg_replace_callback($regCallback[0], $regCallback[1], $xpath, $limit, $count);
			} while($count > 0);
		}
		//final formatting
		$xpath = preg_match('/^\/\//', $xpath) ? $xpath : '//' . $xpath;
		$xpath = preg_replace('#/{4}#', '', $xpath);
		//cache xpath
		self::$cache[$selector] = $xpath;
		//return
		return $xpath;
	}

	private static function transformAttr($matches) {
		//set vars
		$matchesInner = array();
		$return = '[@' . $matches[2] . ']';
		//has match?
		if(preg_match('/^(.*?)(=|~=|\|=|\^=|\$=|\*=|!=)[\'"]?(.*?)[\'"]?$/', $matches[2], $matchesInner)) {
			$name = $matchesInner[1];
			$comparison = $matchesInner[2];
			$value = $matchesInner[3];
			switch($comparison) {
				case '=':
					$return = '[@' . $name . '="' . $value . '"]';
					break;
				case '~=':
					$return = '[contains(concat(" ", @' . $name . ', " "), " ' . $value . ' ")]';
					break;
				case '|=':
					$return = '[starts-with(concat(@' . $name . ', "-"), "' . $value . '-")]';
					break;
				case '^=':
					$return = '[starts-with(@' . $name . ', "' . $value . '")]';
					break;
				case '$=':
					$return = '[ends-with(@' . $name . ', "' . $value . '")]';
					break;
				case '*=':
					$return = '[contains(@' . $name . ', "' . $value . '")]';
					break;
				case '!=':
					$return = '[@' . $name . '!="' . $value . '"]';
					break;
			}
		}
		//cache string
		self::$strings[] = ($matches[1] ? '*' : '') . $return;
		//return
		return ($matches[1] ? ' ' : '') . '[{' . (count(self::$strings) - 1) . '}]';
	}

}