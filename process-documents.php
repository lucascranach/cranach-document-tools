<?php

error_reporting(E_ALL);

/* Config
!!! ACHTUNG: hier stehen die DEFAULT Werte. Custom Werte bitte
in der documents.config angeben.

########################################################################  */

$local_config = getConfig();
$config = (object) [];

// Bestehende JSONS überschreiben?
setConfigValue('FORCE', true);
setConfigValue('PERIOD', '-10000');
setConfigValue('SOURCE', '/var/www/documents');
setConfigValue('TARGET', '/var/www/documents');
setConfigValue('BASEPATH', '/var/www/documents');
setConfigValue('JSON_OUTPUT_FN', 'documentData-1.0.json');


// Nach welchem Pattern soll gesucht werden?
setConfigValue('PATTERN', '*.pdf');

$types = array();
$types["overall"] = '{ "fragment":"Overall", "sort": "01" }';
$types["reverse"] = '{ "fragment":"Reverse", "sort": "02" }';
$types["irr"] = '{ "fragment":"IRR", "sort": "03" }';
$types["x-radiograph"] = '{ "fragment":"X-radiograph", "sort": "04" }';
$types["uv-light"] = '{ "fragment":"UV-light", "sort": "05" }';
$types["detail"] = '{ "fragment":"Detail", "sort": "06" }';
$types["photomicrograph"] = '{ "fragment":"Photomicrograph", "sort": "07" }';
$types["conservation"] = '{ "fragment":"Conservation", "sort": "08" }';
$types["other"] = '{ "fragment":"Other", "sort": "09" }';
$types["analysis"] = '{ "fragment":"Analysis", "sort": "10" }';
$types["rkd"] = '{ "fragment":"RKD", "sort": "11" }';
$types["koe"] = '{ "fragment":"KOE", "sort": "12" }';
$types["transmitted-light"] = '{ "fragment":"Transmitted-light", "sort": "13" }';
$config->TYPES = $types;


/* Functions
############################################################################ */

function getConfig()
{
    $config_file = './documents.config';
    if (!file_exists($config_file)) {
        return false;
    }
    $config = file_get_contents($config_file);
    return json_decode(trim($config));
}

function setConfigValue($key, $default_value)
{
    global $config, $local_config;
    $config->$key = isset($local_config->$key) ? $local_config->$key : $default_value;
}

function getTypeSubfolderName($typeName)
{
    global $config;
    $typeDataJSON = json_decode($config->TYPES[$typeName]);
    $folderName = (isset($typeDataJSON->sort) && isset($typeDataJSON->fragment)) ? $typeDataJSON->sort . "_" . $typeDataJSON->fragment : "";
    return $folderName;
}

function getTypeFilenamePattern($typeName)
{
    global $config;
    $typeDataJSON = json_decode($config->TYPES[$typeName], true);
    return (isset($typeDataJSON->fn_pattern)) ? $typeDataJSON->fn_pattern : "";
}

function addLogEntry($entryData)
{
    $logfile = fopen("logfile.txt", "a");
    fputs($logfile, "$entryData\n");
    fclose($logfile);
}

class DocumentCollection
{

    public $documents = array();

    public function __construct($config)
    {
        $this->config = $config;

        $cmd = "find " . $this->config->SOURCE . " -maxdepth 4 -mtime ".$this->config->PERIOD ." -name '" . $this->config->PATTERN . "' ";
        exec($cmd, $this->files);
        
        $pattern = "=" . $this->config->SOURCE . "=";
        $this->files = preg_replace($pattern, "", $this->files);
        
        $assets = array();
        foreach ($this->files as $file) {
            $assets[$this->getBasePath($file)] = 0;
        }
        
        foreach (array_keys($assets) as $assetBasePath) {
            $res = [];
            $res["name"] = $assetBasePath;
            
            foreach ($this->config->TYPES as $typeName => $typeData) {
                $typePattern = getTypeSubfolderName($typeName);
                $filenamePattern = getTypeFilenamePattern($typeName);
                $searchPattern = (isset($filenamePattern)) ? $typePattern . "/" . $filenamePattern : $typePattern;
                $typeFiles = preg_grep("=/$assetBasePath/$searchPattern=", $this->files);
                $res["data"][$typeName] = $typeFiles;
            }
            array_push($this->documents, $res);
        }
    }

    private function getBasePath($path)
    {
        return preg_replace("=/(.*?)/.*=", '${1}', $path);
    }

    public function getSize()
    {
        return count($this->documents);
    }
}


class DocumentBundle
{
    public function __construct()
    {
        $this->documentStack = [];
    }

    public function addSubStack($type)
    {
        $this->documentStack[$type] = [];
    }
}


function processDocuments($collection, $config)
{
    $stackSize = $collection->getSize();
    $count = 0;

    foreach ($collection->documents as $document) {

        $documentName = $document["name"];
        $documentData = $document["data"];
        $count++;

        print "\nAsset $count from $stackSize // $documentName:";
        $documentBundle = new DocumentBundle;
        $jsonPath = $config->TARGET . "/$documentName/" . $config->JSON_OUTPUT_FN;

        if (file_exists($jsonPath) && !$config->FORCE) {
            print "… already exists :)";
            continue;
        }

        foreach ($config->TYPES as $typeName => $typeData) {
            $documentBundle->addSubStack($typeName);
            
            foreach ($documentData[$typeName] as $document) {
              preg_match("=\/(.*?)\/(.*?)\/(.*)=", $document, $res);
              $data = [];
              $data["path"] = $res[2];
              $data["src"] = $res[3];
              array_push($documentBundle->documentStack[$typeName], $data);
            }
        }
        $jsonData = json_encode($documentBundle);

        file_put_contents($jsonPath, $jsonData);
        print "\n\t\t\twritten $jsonPath\n";
        

        addLogEntry($documentName);
    }
}

/* Main
############################################################################ */

$newSession = "\n#######################################################\n" . date("d.m.Y, H:i:s", time());
addLogEntry($newSession);

$documentCollection = new DocumentCollection($config);
processDocuments($documentCollection, $config);
