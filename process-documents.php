<?php

error_reporting(E_ALL);

/* Config
!!! ACHTUNG: hier stehen die DEFAULT Werte. Custom Werte bitte
in der documents.config angeben.

########################################################################  */

$config = (object) [];
$config->LOCALCONFIG = getConfigFile();

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

function getConfigFile()
{
    $config_file = './document-tools-config.json';
    if (!file_exists($config_file)) {
        print "--------------------\n";
        print "Keine document-tools-config.json gefunden :(\n";
        exit;
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

    public function __construct($params)
    {
      global $config;
        $this->params = $params;

        $cmd = "find " . $this->params["source"] . " -maxdepth 4 -mtime ". $this->params["period"] ." -name '" . $this->params["pattern"] . "' ";
        exec($cmd, $this->files);

        $pattern = "=" . $this->params["source"] . "=";
        $this->files = preg_replace($pattern, "", $this->files);
        
        $assets = array();
        foreach ($this->files as $file) {
            $assets[$this->getBasePath($file)] = 0;
        }
        
        foreach (array_keys($assets) as $assetBasePath) {
            $res = [];
            $res["name"] = $assetBasePath;
            
            foreach ($config->TYPES as $typeName => $typeData) {
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


function processDocuments($collection, $params)
{
  global $config;  
  $stackSize = $collection->getSize();
    $count = 0;
    
    foreach ($collection->documents as $document) {

        $documentName = $document["name"];
        $documentData = $document["data"];
        $count++;

        print "\nAsset $count from $stackSize // $documentName:";
        $documentBundle = new DocumentBundle;
        $jsonPath = $params["target"] . "/$documentName/" . $config->LOCALCONFIG->jsonOutputFilename;

        if (file_exists($jsonPath) && !$params["overwrite"]) {
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

function getConvertionParams($cliOptions, $params)
{

    $sourceBasePath = $params["sourceBasePath"];
    $source = isset($cliOptions["dir"])
    ? $sourceBasePath . '/' . $cliOptions["dir"]
    : $sourceBasePath;

    $targetBasePath = $params["targetBasePath"];
    $target = isset($cliOptions["dir"])
    ? $targetBasePath . '/' . $cliOptions["dir"]
    : $targetBasePath;

    $defaultPattern = gettype($params["pattern"]) === "array" ? implode("|", $params["pattern"]) : $params["pattern"];
    $pattern = isset($cliOptions["pattern"])
    ? $cliOptions["pattern"]
    : $defaultPattern;

    $period = isset($cliOptions["period"])
    ? $cliOptions["period"]
    : $params["defaultPeriod"];

    $overwrite = isset($cliOptions["overwrite"])
    ? $cliOptions["overwrite"]
    : "nein";

    $params = [
        "sourceBasePath" => $sourceBasePath,
        "source" => $source,
        "targetBasePath" => $targetBasePath,
        "target" => $target,
        "pattern" => $pattern,
        "period" => $period,
        "overwrite" => $overwrite,
    ];

    return $params;
}

function getCliOptions()
{
    $ret = [];
    $options = getopt("p:d:o:t:");
    if (isset($options["p"])) {$ret["pattern"] = $options["p"];}
    if (isset($options["d"])) {$ret["dir"] = $options["d"];}
    if (isset($options["o"])) {$ret["overwrite"] = true;}
    if (isset($options["t"])) {$ret["period"] = $options["t"];}

    return $ret;
}

function confirmParams($params)
{
    print "----------\n";
    print "Quellverzeichnis: " . $params["source"] . "\n";
    print "Zielverzeichnis: " . $params["target"] . "\n";
    print "Pattern: " . $params["pattern"] . "\n";
    print "Zeitspanne: " . $params["period"] . "\n";
    print "Overwrite: " . $params["overwrite"] . "\n";

    print "\nAlle Angaben in Ordnung? [j,n] ";
    $choice = rtrim(fgets(STDIN));

    if ($choice !== 'j') {exitScript();}
    return true;
}

function exitScript()
{
    print "\nfertig :)\n\n";
    exit;
}

function extraxtDocuments($documentCollection, $params){

  $target = $params["target"];
  $source = $params["source"];

  foreach($documentCollection->documents as $document){
    $name = $document["name"];
    $data = $document["data"];

    foreach ($data as $key => $files) {
      if(sizeof($files) === 0 ) continue;

      foreach($files as $file){
        $sourcePath = $source . "/" . $file;#
        $targetPath = $target . "/" . $file;#
        createRecursiveFolder($targetPath);

        $cmd = "rsync $sourcePath $targetPath";
        shell_exec($cmd);

        print "$file\n";
      }
    }
    
    
  }
}

function createRecursiveFolder($path)
{
    preg_match("=(.*)\/=", $path, $res);
    $segments = explode("/", $res[1]);

    $growingPath = [];
    foreach ($segments as $segment) {
        array_push($growingPath, $segment);
        $newPath = implode("/", $growingPath);
        if (preg_match("=[a-zA-Z]=", $newPath) && !file_exists($newPath)) {mkdir($newPath, 0775);}
    }
    return;
}

function showMainMenu($config)
{

    $actions = [
        "extraxt-and-move-pdfs" => "PDFs aus Verzeichnisstruktur extrahieren und in «documents» Folder packen.",
        "generate-json" => "JSON Dateien erzeugen",
        "exit" => "Skript beenden",
    ];

    $params = [
        "-p" => "Übergibt ein File-Pattern, welches das Pattern der Config überschreibt, z.B. -p \"G_*\"",
        "-t" => "Übergibt ein Periode, z.B. -2 findet nur Dateien, die in den letzten 2 Tagen geändert wurden."
    ];

    print "\n#############################################################################\n";
    print "Cranach Document Tools\n\n";
    print "Das Skript kann mit folgenden Parametern aufgerufen werden:\n";
    foreach ($params as $key => $value) {
        print "$key\t$value\n";
    }
    print "\nFolgende Aktionen sind verfügbar:\n";

    $count = 0;
    $options = [];

    foreach ($actions as $key => $value) {
        $count++;
        print "[$count] $value\n";
        $options[$count] = $key;
    }

    print "\nWas soll gemacht werden? ";
    $choice = intval(rtrim(fgets(STDIN)));
    if (!array_key_exists($choice, $options)) {print "\nDiese Aktion ist nicht verfügbar.\n\n";exit;}

    switch ($options[$choice]) {

      case "extraxt-and-move-pdfs":
        print "\nPDFs aus Verzeichnisstruktur extrahieren und in «documents» Folder packen …\n";

        $cliOptions = getCliOptions($config);
        $params = getConvertionParams($cliOptions, [
            "sourceBasePath" => $config->LOCALCONFIG->source,
            "targetBasePath" => $config->LOCALCONFIG->target,
            "pattern" => ["*.pdf"],
            "defaultPeriod" => $config->LOCALCONFIG->defaultPeriod,
        ]);

        confirmParams($params);
        $documentCollection = new DocumentCollection($params);
        extraxtDocuments($documentCollection, $params);
        exitScript();
        break;

        case "generate-json":
          print "\nJSON Dateien erzeugen …\n";

          $cliOptions = getCliOptions($config);
          $params = getConvertionParams($cliOptions, [
              "sourceBasePath" => $config->LOCALCONFIG->target,
              "targetBasePath" => $config->LOCALCONFIG->target,
              "pattern" => ["*.pdf"],
              "defaultPeriod" => $config->LOCALCONFIG->defaultPeriod,
          ]);

          confirmParams($params);
          $documentCollection = new DocumentCollection($params);
          processDocuments($documentCollection, $params);
          exitScript();
          break;

        case "exit":
            exitScript();
            break;

        default:
            print "Na gut, dann eben nix.\n";
            exit;
    }

}

/* Main
############################################################################ */

showMainMenu($config);