<?php

define('LINE_ENDING', "\r\n");
define('PRODID', '-//Niko//DHBW iCal Fixing Proxy//DE');
//define('CONTENT_TYPE', 'text/plain');
define('CONTENT_TYPE', 'text/Calendar');
define('CHARSET', 'UTF-8');

ini_set('default_charset', CHARSET);
mb_internal_encoding(CHARSET);

class VCal {
    public $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function withElements(array $elements) {
        return new VCal($this->container->withElements($elements));
    }

    public function compile() {
        return $this->compileContainer($this->container);
    }

    protected function compileContainer(Container $container) {
        $out = 'BEGIN:' . $container->name . LINE_ENDING;
        foreach ($container->elements as $element) {
            $out .= $this->compileElement($element);
        }
        $out .= 'END:' . $container->name . LINE_ENDING;
        return $out;
    }

    protected function compileElement(Element $element) {
        if ($element instanceof Property) {
            return $this->compileProperty($element);
        } else if ($element instanceof Container) {
            return $this->compileContainer($element);
        } else {
            throw new Exception("Unknown element type!");
        }
    }

    protected function compileProperty(Property $property) {
        return $property->name . ':' . $property->value . LINE_ENDING;
    }

    public static function parse($input, Listener $l) {
        $input = $l->beginVCal($input);
        $element = self::parseElement(new CharStream($input), $l);
        if ($element instanceof Container) {
            return $l->endVCal(new VCal($element));
        }
        return null;
    }

    private static function parseElement(CharStream $s, Listener $l, $currentContainer = null) {
        $l->beginElement($currentContainer);
        $line = self::parseLine($s, $l);
        if (!$line) {
            return null;
        }
        list($key, $value) = $line;
        $lowerKey = mb_strtolower($key);
        if ($lowerKey == 'begin') {
            return $l->endElement(self::parseContainer($s, $l, trim($value)));
        } else if ($lowerKey == 'end' && mb_strtolower(trim($value)) == mb_strtolower($currentContainer)) {
            return null;
        } else {
            return $l->endElement(self::parseProperty($l, $key, $value));
        }
    }

    private static function parseContainer(CharStream $s, Listener $l, $name) {
        $l->beginContainer($name);
        $out = array();
        while (($element = self::parseElement($s, $l, $name)) !== null) {
            $out[] = $element;
        }

        return $l->endContainer(new Container($name, $out));
    }

    private static function parseProperty(Listener $l, $key, $value) {
        return $l->endProperty(new Property($l->beginProperty($key), $value));
    }

    private static function parseLine(CharStream $s, Listener $l) {
        $l->beginLine();
        $name = self::readName($s, $l);
        $value = self::readValue($s, $l);
        if (!$name) {
            return false;
        }
        return $l->endLine(array($name, $value));
    }

    private static function readName(CharStream $s, Listener $l) {
        $l->beginName();
        $name = trim(self::readUntil($s, ':'));
        if (!$name) {
            $l->endName(null);
            return false;
        }
        return $l->endName($name);
    }

    private static function readValue(CharStream $s, Listener $l) {
        $l->beginValue();
        return $l->endValue(self::readUntil($s, "\n"));
    }

    private static function readUntil(CharStream $s, $until) {
        $out = '';
        while (($c = $s->next()) !== false) {
            if ($c == $until) {
                break;
            }
            $out .= $c;
        }
        return $out;
    }
}

class Element {
    public $name;

    public function __construct($name)
    {
        $this->name = $name;
    }

    public function is($name) {
        return strcasecmp($this->name, trim($name)) === 0;
    }
}

class Property extends Element {
    const METHOD = 'METHOD';
    const PRODID = 'PRODID';
    const UID = 'UID';
    const START = 'DTSTART';
    const END = 'DTEND';
    const SUMMARY = 'SUMMARY';

    public $value;

    public function __construct($name, $value)
    {
        parent::__construct($name);
        $this->value = $value;
    }

    public function __toString()
    {
        return $this->name . ':' . $this->value;
    }
}

class Container extends Element {
    const ROOT = 'VCALENDAR';
    const EVENT = 'VEVENT';

    /**
     * @var Element[]
     */
    public $elements;

    public function __construct($name, array $elements)
    {
        parent::__construct($name);
        $this->elements = $elements;
    }

    /**
     * @param name
     * @return Property
     */
    public function getFirst($name) {
        foreach ($this->elements as $element) {
            if ($element instanceof Property && $element->is($name)) {
                return $element;
            }
        }
        return null;
    }

    public function setFirst($name, $value) {
        $out = array();
        $replaced = false;
        foreach ($this->elements as $element) {
            if (!$replaced && $element instanceof Property && $element->is($name)) {
                $out[] = new Property($name, $value);
                $replaced = true;
            } else {
                $out[] = $element;
            }
        }

        return new Container($this->name, $out);
    }

    public function insertAfter($name, Element $element) {
        $out = array();
        foreach ($this->elements as $e) {
            $out[] = $e;
            if ($e->is($name)) {
                $out[] = $element;
            }
        }
        return new Container($this->name, $out);
    }

    public function withElements(array $elements)
    {
        return new Container($this->name, $elements);
    }
}

class Listener {

    public function beginVCal($original) {
        return $original;
    }
    public function endVCal(VCal $VCal) {
        return $VCal;
    }
    public function beginElement($container) {

    }
    public function endElement(Element $element) {
        return $element;
    }
    public function beginContainer($name) {
        return $name;
    }
    public function endContainer(Container $container) {
        return $container;
    }
    public function beginProperty($name) {
        return $name;
    }
    public function endProperty(Property $property) {
        return $property;
    }

    public function beginLine() {

    }

    public function endLine(array $line) {
        return $line;
    }

    public function beginName() {

    }

    public function endName($name) {
        return $name;
    }

    public function beginValue() {

    }

    public function endValue($value) {
        return $value;
    }
}

class CharStream {
    private $string;
    private $length;
    private $offset = -1;

    public function __construct($string)
    {
        if (mb_detect_encoding($string, array('UTF-8'), true) === 'UTF-8') {
            $this->string = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
            $this->length = count($this->string);
        } else {
            $this->string = $string;
            $this->length = strlen($this->string);
        }
    }

    public function seek($n = 1) {
        $this->offset = max($this->offset + $n, -1);
    }

    public function next() {
        if (!$this->has()) {
            return false;
        }
        $this->seek();
        return $this->peek();
    }

    public function peek() {
        //echo "_" . str_replace(array("\n"), array('\n'), $this->string[$this->offset]) . "_" . $this->offset . "/" . ($this->length - 1) . "\n";
        return $this->string[$this->offset];
    }

    public function has($n = 1) {
        return ($this->offset + $n) < $this->length;
    }

    public function rest() {
        if ($this->has()) {
            return mb_substr($this->string, $this->offset);
        }
        return '';
    }

    public function offset() {
        return $this->offset;
    }

    public function length() {
        return $this->length;
    }
}

class FixupListener extends Listener {

    private $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    public function beginVCal($original)
    {
        return trim(str_replace(array("\r\n", "\r"), "\n", $original));
    }

    public function endVCal(VCal $VCal)
    {
        if (count($this->filters) === 0) {
            return parent::endVCal($VCal);
        }

        $out = array();
        foreach ($VCal->container->elements as $element) {
            $keep = true;
            if ($element instanceof Container && $element->is(Container::EVENT)) {
                foreach ($this->filters as $name => $pattern) {
                    $prop = $element->getFirst($name);
                    if ($prop !== null && preg_match($pattern, $prop->value)) {
                        $keep = false;
                        break;
                    }
                }
            }
            if ($keep) {
                $out[] = $element;
            }
        }

        return $VCal->withElements($out);
    }

    public function endContainer(Container $container)
    {
        if ($container->is(Container::ROOT)) {
            return $container->insertAfter(Property::METHOD, new Property(Property::PRODID, PRODID));
        }
        if ($container->is(Container::EVENT)) {
            $uid = $container->getFirst(Property::UID)->value;
            $dtend = $container->getFirst(Property::END)->value;
            return $container->setFirst(Property::UID, $uid . '_' . $dtend);
        }
        return $container;
    }

    public function endProperty(Property $property)
    {
        $val = trim($property->value);
        if (empty($val) || ($val[0] == '"' && $val[0] == $val[count($val) - 1])) {
            return $property;
        }
        $from = array("\n", '\\',    ':',  ';',  ',');
        $to   = array('\n', '\\\\', '\;', '\;', '\,');
        return new Property($property->name, str_replace($from, $to, $val));
    }

}

function loadOriginalICal($course)
{
    $context = stream_context_create(array('http' => array('header' => 'Accept-Charset: UTF-8, *;q=0')));
    $original = file_get_contents('http://vorlesungsplan.dhbw-mannheim.de/ical.php?uid=' . $course, (defined('FILE_BINARY') ? FILE_BINARY : false), $context);

    $headers = array();
    if (isset($http_response_header))
    {
        foreach ($http_response_header as $header) {
            $colonPos = mb_strpos($header, ':');
            if ($colonPos !== false) {
                $name = mb_strtolower(trim(mb_substr($header, 0, $colonPos)));
                $value = trim(mb_substr($header, $colonPos + 1));
                $headers[$name] = $value;
            }
        }
    }

    $fileName = "$course.ics";
    $dispositionHeader = 'content-disposition';
    if (isset($headers[$dispositionHeader])) {
        $match = array();
        if (preg_match('/filename=\"((?:\\"|[^"])+?)"/i', $headers[$dispositionHeader], $match)) {
            $fileName = stripcslashes($match[1]);
        }
    }

    return array($original, $fileName);
}

$course = '6188001';
if (isset($_REQUEST['course']) && trim($_REQUEST['course'])) {
    $course = trim($_REQUEST['course']);
}
$filters = array();
if (isset($_REQUEST['filter']) && is_array($_REQUEST['filter'])) {
    $filters = $_REQUEST['filter'];
}

list($original, $fileName) = loadOriginalICal($course);

header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");
header('Content-Type: ' . CONTENT_TYPE . '; charset=' . CHARSET);
header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"') . '"');

$vcal = VCal::parse($original, new FixupListener($filters));
echo $vcal->compile();
//print_r($vcal);
