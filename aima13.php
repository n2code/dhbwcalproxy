<?php

define('LINE_ENDING', "\r\n");
define('PROGID', '-//Niko//DHBW iCal Fixing Proxy//DE');
//define('CONTENT_TYPE', 'text/plain');
define('CONTENT_TYPE', 'text/Calendar');
define('CHARSET', 'UTF-8');

class VCal {
    public $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
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
        return $l->endVCal(new VCal(self::parseElement(new CharStream($input), $l)));
    }

    private static function parseElement(CharStream $s, Listener $l, $currentContainer = null) {
        $l->beginElement($currentContainer);
        $line = self::parseLine($s, $l);
        if (!$line) {
            return false;
        }
        list($key, $value) = $line;
        $lowerKey = mb_strtolower($key);
        if ($lowerKey == 'begin') {
            return $l->endElement(self::parseContainer($s, $l, trim($value)));
        } else if ($lowerKey == 'end' && mb_strtolower(trim($value)) == mb_strtolower($currentContainer)) {
            return false;
        } else {
            return $l->endElement(self::parseProperty($l, $key, $value));
        }
    }

    private static function parseContainer(CharStream $s, Listener $l, $name) {
        $l->beginContainer($name);
        $out = array();
        while ($element = self::parseElement($s, $l, $name)) {
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
    public $value;

    public function __construct($name, $value)
    {
        parent::__construct($name);
        $this->value = $value;
    }
}

class Container extends Element {
    public $elements;

    public function __construct($name, array $elements)
    {
        parent::__construct($name);
        $this->elements = $elements;
    }

    /**
     * @return Property|null
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
            if (!$replaced && $element->is($name)) {
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
        $this->string = $string;
        $this->length = strlen($string);
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
    public function beginVCal($original)
    {
        return trim(str_replace(array("\r\n", "\r"), "\n", utf8_decode($original)));
    }

    public function endContainer(Container $container)
    {
        if ($container->is('VCALENDAR')) {
            return $container->insertAfter('METHOD', new Property('PRODID', PROGID));
        }
        if ($container->is('VEVENT')) {
            $uid = $container->getFirst('UID')->value;
            $dtend = $container->getFirst('DTEND')->value;
            return $container->setFirst('UID', $uid . '_' . $dtend);
        }
        return $container;
    }

    public function endProperty(Property $property)
    {
        if ($property->is('SUMMARY')) {
            return new Property($property->name, preg_replace("/[,;]/", "\\$0", $property->value));
        }
        return $property;
    }

}

$course = '6188001';
if (isset($_REQUEST['course']) && trim($_REQUEST['course'])) {
    $course = trim($_REQUEST['course']);
}
$original = file_get_contents('http://vorlesungsplan.dhbw-mannheim.de/ical.php?uid=' . $course);
header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Pragma: no-cache");
header('Content-Type: ' . CONTENT_TYPE . ', charset=' . CHARSET);
$vcal = VCal::parse($original, new FixupListener());



echo $vcal->compile();
//print_r($vcal);
