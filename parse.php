<?php

error_reporting(E_ALL ^ ( E_NOTICE | E_DEPRECATED ));

date_default_timezone_set('Europe/Sofia');

$username = 'username';
$password = 'password';
$space = 'SPACENAME';

if (date('D') === 'Sun' || date('D') === 'Sat') {
    exit;
}
if (date('D') === 'Mon') {
    $daysBack = strtotime('-3 days');
} else {
    $daysBack = strtotime('-24 hours');
}

function curl($url, $type, $data, $username, $password) {
    $ch = curl_init($url);
    if ($type != null) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }


    if (strpos($url, 'smokeping') == true || strpos($url, 'attachment') == true || is_array($data)) {
        $contentType = null;
    } else {
        $contentType = 'Content-Type: application/json';
    }
    $headers[] = 'X-Atlassian-Token: no-check';
    $headers[] = $contentType;
    if ($username != null && $password != null) {
        $headers['header'] = "Authorization: Basic " . base64_encode("$username:$password");
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        echo "Curl error:\n " . curl_error($ch);
    }

    curl_close($ch);
    return $result;
}

$page = 0;
$stop = false;
do {
    $html = curl("https://monitoringwebsite.domain/page=$page", null, null, $username, $password);

    $doc = new DOMDocument();
    @$doc->loadHTML($html);

    $xpath = new DOMXpath($doc);
    $elements = $xpath->query("//div[@class='logEntries']");

    if ($elements->length > 0) {
        foreach ($elements as $element) {
            $nodes = $element->childNodes;
            foreach ($nodes as $node) {
                $line = $node->nodeValue;
                if (preg_match("/\[(.+?)\].+?:\s(.+?);(.+?);(.*)/", $line, $parts)) {
                    $date = strtotime($parts[1]);
                    $host = $parts[2];
                    $type = $parts[3];
                    $info = $parts[4];
                    if ($date > $daysBack) {
                        $arr[$host][] = array("Date" => $date, "Type" => $type, "Info" => $info);
                        $stop = false;
                    } else {
                        $stop = true;
                    }
                }
            }
        }
    }
    $page++;
} while ($stop != true && $page <= 100000);

if (sizeof($arr) <= 0) {
    echo "Parse Error: Empty Array from server history \n";
    exit(1);
}

function sort_by_order($a, $b) {
    return $a['Date'] - $b['Date'];
}

foreach ($arr as $key => $row) {
    usort($row, 'sort_by_order');
    $arr[$key] = $row;
}

$rows = '<h3>1. Server critical alerts</h3>';

foreach ($arr as $host => $values) {
    $rows .= '<table>';
    foreach ($values as $val) {
        $rows .='<tr><td>' . htmlentities(date('Y-m-d H:i:s', $val['Date'])) . '</td><td><a href="' . htmlentities($host) . '">' . htmlentities($host) . '</a></td><td>' . htmlentities($val['Type']) . '</td><td>' . htmlentities($val['Info']) . '</td></tr>';
    }
    $rows .= '</table>
    <ul>
    <li>What is problem</li>
    <li>Why is problem</li>    
    <li>What is downtime</li>   
    </ul> ';
}

$rows .= '<h3>2. Performance</h3>';


/* DOWNLOAD SMOKEPING IMAGES */
$rows .= '<h3>3. Connectivity</h3>';
$servers = array(
    "leo.oai2.ord",
    "ABt.oai2.ord",
    "Dabi.oai2-etec"
);

foreach ($servers as $server) {
    $url = "https://monitoring.domain/dir/file.pl?target=" . $server;
    $html = curl($url, null, null, $username, $password);

    preg_match_all('/src="\.\.(\/\.simg\/.*?\/.*?\.png)"/i', $html, $images[$server]);
    preg_match('/<h1>(.+?)<\/h1>/i', $html, $title); //header tags

    $images[$server]['name'] = $title[1];

    array_pop($images[$server][1]); //Delete last image

    foreach ($images[$server][1] as $img) {
        $imgnameonly = preg_replace('/\/.*\/.*\//', '', $img);
        if (!copy('https://monitoring.domain' . $img, '/tmp/' . $imgnameonly)) {
            echo "Copy Image to /tmp/ folder problem.\n";
            exit(1);
        }
    }
}
/* DOWNLOAD SMOKEPING IMAGES END */


/* 2014-12-03 11:00 - 2014-12-04 11:00 Performance log CREATE BLANK PAGE */
$dateNow = date('Y-m-d H:00');
$yesterday = date('Y-m-d H:00', $daysBack);
$pageName = $yesterday . ' - ' . $dateNow . ' Performance log';

$dataArray = array(
    "type" => "page",
    "title" => "$pageName",
    "space" => array(
        "key" => $space
    ),
    "body" => array(
        "storage" => array(
            "value" => " "
            ,
            "representation" => "storage"
        )
    )
);
$jsonData = json_encode($dataArray);

$result = curl('http://wiki.sitename.com/rest/api/content', 'POST', $jsonData, $username, $password);
$arrId = json_decode($result, true);
$pageId = $arrId['id'];
if (!is_numeric($pageId)) {
    echo "Problem with creating blank page in space or is Allready created \n";
    exit(1);
}
/* CREATE BLANK PAGE END */


/* UPLOAD IMAGES AND GET NAMES FOR HTML */
chdir("/tmp/");
foreach ($images as $server => $data) {
    $rows .= '<h1>' . $data['name'] . '</h1>';
    foreach ($data[1] as $image) {
        $imgnameonly = preg_replace('/\/.*\/.*\//', '', $image);
        $rows .= '<img src="/download/attachments/' . $pageId . '/' . $imgnameonly . '" alt="" /><br />';
        $dataArray = array(
            'file' => "@$imgnameonly"
        );
        $result = curl('http://wiki.sitename.com/rest/api/content/' . $pageId . '/child/attachment', 'POST', $dataArray, $username, $password);
        $resultArr = json_decode($result, true);
        if (array_key_exists('results', $resultArr) == false) {
            echo "Upload Images Problem \n";
            exit(1);
        }
        unlink('/tmp/' . $imgnameonly);
    }
}
chdir("/");
/* UPLOAD IMAGES END */


/* UPDATE PAGE */
$dataArray = array(
    "id" => $pageId,
    "type" => "page",
    "title" => $pageName,
    "space" => array(
        "key" => $space
    ),
    "body" => array(
        "storage" => array(
            "value" => $rows
            ,
            "representation" => "storage"
        )
    ),
    "version" => array(
        "number" => 2
    )
);
$jsonData = json_encode($dataArray);

$result = curl("http://wiki.sitename.com/rest/api/content/$pageId", 'PUT', $jsonData, $username, $password);
$resultArr = json_decode($result, true);
if (array_key_exists('id', $resultArr) == false) {
    echo "Final Update page error \n";
    exit(1);
} else {
    $title = urlencode($resultArr['title']);
    echo "http://wiki.sitename.com/display/$space/$title \n";
}
/* UPDATE PAGE END */    
