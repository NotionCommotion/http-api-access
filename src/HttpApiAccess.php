<?php
namespace Greenbean\HttpApiAccess;

class ParserException extends \Exception{}
class HttpApiAccess
{
    private $request;

    public function getData(bool $force=false) {
        if($this->request && !$force) return $this->request;
        switch(strtoupper($_SERVER['REQUEST_METHOD'])){
            //Question.  Is using PHP super globals $_GET and $_POST better/faster/etc for GET and POST requests than returning stream?
            case 'GET': $request=$_GET;break;
            case 'POST': $request=$_POST;break;
            case 'PUT': $request=$this->getStream();break;
            case 'DELETE': $request=$this->getStream();break;
            default: throw new ParserException("Unsupported HTTP method $_SERVER[REQUEST_METHOD]'", 500);
        }
        $this->request=$request;
        return $request;
    }

    public function returnData($data, int $code=null) {
        if($code) http_response_code($code);
        $type=$this->getBestSupportedMimeType(["application/json", "application/xml"]);
        header('Content-Type: '.$type);
        switch($type){
            case "application/xml":
                $xml_data = new \SimpleXMLElement('<?xml version="1.0"?><data></data>');
                $this->array_to_xml($data,$xml_data);
                echo($xml_data->asXML());
                break;
            default:    //case 'application/json':;
                echo json_encode($data);
        }
        exit;
    }

    private function getStream() {
        $input = substr(PHP_SAPI, 0, 3) === 'cgi'?'php://stdin':'php://input';

        //Question.  Why not just use: parse_str(file_get_contents($input), $contents);

        if (!($stream = @fopen($input, 'r'))) {
            throw new ParserException('Unable to read request body', 500);
        }
        if (!is_resource($stream)) {
            throw new ParserException('Invalid stream', 500);
        }
        $str = '';
        while (!feof($stream)) {
            $str .= fread($stream, 8192);
        }
        parse_str($str, $contents);

        return $contents;
    }

    private function array_to_xml( $data, &$xml_data ) {
        foreach( $data as $key => $value ) {
            if( is_numeric($key) ){
                $key = 'item'.$key; //dealing with <0/>..<n/> issues
            }
            if( is_array($value) ) {
                $subnode = $xml_data->addChild($key);
                $this->array_to_xml($value, $subnode);
            } else {
                $xml_data->addChild("$key",htmlspecialchars("$value"));
            }
        }
    }

    private function getBestSupportedMimeType($mimeTypes = null) {
        // Values will be stored in this array
        $AcceptTypes = [];
        $accept = strtolower(str_replace(' ', '', $_SERVER['HTTP_ACCEPT']));
        $accept = explode(',', $accept);
        foreach ($accept as $a) {
             $q = 1;  // the default quality is 1.
            // check if there is a different quality
            if (strpos($a, ';q=')) {
                // divide "mime/type;q=X" into two parts: "mime/type" i "X"
                list($a, $q) = explode(';q=', $a);
            }
            // mime-type $a is accepted with the quality $q
            // WARNING: $q == 0 means, that mime-type isn’t supported!
            $AcceptTypes[$a] = $q;
        }
        arsort($AcceptTypes);

        // if no parameter was passed, just return parsed data
        if (!$mimeTypes) return $AcceptTypes;

        //If supported mime-type exists, return it, else return null
        $mimeTypes = array_map('strtolower', (array)$mimeTypes);
        foreach ($AcceptTypes as $mime => $q) {
            if ($q && in_array($mime, $mimeTypes)) return $mime;
        }
        return null;
    }

    static public function callApi(string $method, string $url, array $data=[], array $files=[], array $options=[], bool $raw=false) {
        /*
        string $method: HTTP Method
        string $url: Server URI
        array $data to send
        array $files $_FILES
        array $options additional options such as CURLOPT_HTTPHEADER
        bool $raw: Whether provided content and should not be encoded but turned into a json string.  Defaults to false.
        */
        $method=strtolower($method);
        if($files && !in_array($method, ['post', 'put'])) {
            throw new \Exception("Files may only be set with HTTP methods post and put and not $method");
        }
        if($files) {
            //cURL doesn't work out of the box with both files and POST data.
            $postData = [];
            foreach ($files as $name=>$file){
                $postData[$name] = new \CURLFile($file['tmp_name'],$file['type'],$file['name']);
            }
            if($data) {
                foreach (explode('&', http_build_query($data)) as $pair){
                    list($name, $value) = explode('=', $pair, 2);
                    $postData[urldecode($name)] = urldecode($value);
                }
            }
            $data=$postData;
        }
        elseif($raw) {
            $data=json_encode($data);
            $options[CURLOPT_HTTPHEADER][]='Content-Type: text/plain';
        }
        else {
            $data=http_build_query($data);
        }
        $options=$options+[    //Don't use array_merge since it reorders!
            CURLOPT_RETURNTRANSFER => true,     // return web page
            CURLOPT_HEADER         => false,    // don't return headers
            CURLOPT_FOLLOWLOCATION => true,     // follow redirects
            CURLOPT_ENCODING       => "",       // handle all encodings
            CURLOPT_USERAGENT      => "unknown",// who am i
            CURLOPT_AUTOREFERER    => true,     // set referrer on redirect
            CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
            CURLOPT_TIMEOUT        => 120,      // timeout on response
            CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
        ];
        //Optional authentication
        if (isset($options[CURLOPT_USERPWD])) {$options[CURLOPT_HTTPAUTH]=CURLAUTH_BASIC;}
        switch ($method) {
            case "get":
                if ($data) {$url = sprintf("%s?%s", $url, $data);}
                break;
            case "post":
                $options[CURLOPT_POST]=1;
                // CURLOPT_POST requires CURLOPT_POSTFIELDS to be set!!!  PUT and DELETE don't seem to require.
                $options[CURLOPT_POSTFIELDS]=$data?$data:'';
                break;
            case "put":
                //$options[CURLOPT_PUT]=1;
                $options[CURLOPT_CUSTOMREQUEST]="PUT";
                if ($data) {$options[CURLOPT_POSTFIELDS]=$data;}
                break;
            case "delete":
                //$options[CURLOPT_DELETE]=1;
                $options[CURLOPT_CUSTOMREQUEST]="DELETE";
                if ($data) {$options[CURLOPT_POSTFIELDS]=$data;}
                break;
            default:trigger_error("Invalid HTTP method.", E_USER_ERROR);
        }
        $options[CURLOPT_URL]=$url;
        $ch      = curl_init();
        curl_setopt_array( $ch, $options );

        $rsp=curl_exec( $ch );
        $httpCode=curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if($errno=curl_errno($ch)) {
            if($errno===6) {
                $rsp = ['message'=>'Invalid Datalogger IP','code'=>1];
            }
            else {
                $error=curl_error($ch);
                $rsp = ['message'=>"cURL Error: $error ($errno)"];
            }
        }
        elseif(empty($rsp)) {
            $rsp = $raw?null:[];
        }
        $json=json_decode($rsp);
        if(json_last_error() === JSON_ERROR_NONE) {
            $rsp = $json;
        }
        else {
            syslog(LOG_ERR, "Invalid JSON (callApi): $rsp");
            $rsp = ['message'=>'Invalid JSON response','code'=>1];
        }
        curl_close( $ch );
        //foreach($files as $file) {unlink($file['tmp_name']);}    // Is this necessary?
        return [$rsp, $httpCode];;
    }

}