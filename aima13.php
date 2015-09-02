<?php
header("Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0");
header("Content-Type: text/Calendar");
header("Pragma: no-cache");
$infeed = file_get_contents("http://vorlesungsplan.dhbw-mannheim.de/ical.php?uid=6188001");
$lines = explode("\n", $infeed);
$result = array();

$uids = array();

foreach ($lines as $raw) {
	$line = $raw;

	//strip trailing carriage return, will be reappended later
	if (substr($raw, -1) == "\r") {
		$line = substr($line, 0, strlen($line)-1);
	}

	//key value splitting
	$keyvalue = explode(":", $line, 2);
	if (count($keyvalue) == 2) {
		//found a valid, non-empty line for processing
		$key   = $keyvalue[0];
		$value = $keyvalue[1];

		//Add application name before METHOD field
		if ($key == "METHOD") {
			$result[] =  "PRODID:-//Niko//DHBW iCal Fixing Proxy//DE";
		}

		//Fix unescaped characters in text fields
		if ($key == "SUMMARY") {
			$value = preg_replace("/([,;])/", "\\$1", $value);
		}

		//Make UIDs actually unique
		if ($key == "UID") {
			//If necessary append "R" (for Recurrence) to UID until unique
			while (in_array($value, $uids)) {
				$value .= "R";
			}
			//Remember all used UIDs
			$uids[] = $value;
		}

		$result[] = $key.":".$value;
	}
}

$outfeed = implode("\r\n", $result);
echo $outfeed;
?>